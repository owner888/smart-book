<?php
/**
 * Redis 缓存服务
 */

namespace SmartBook\Cache;

use Workerman\Redis\Client as RedisClient;

class CacheService
{
    private static ?RedisClient $redis = null;
    private static bool $connected = false;
    
    public static function init(): void
    {
        if (self::$redis !== null) return;
        self::$redis = new RedisClient('redis://' . REDIS_HOST . ':' . REDIS_PORT);
        self::$connected = true;
        echo "✅ Redis 连接成功\n";
    }
    
    public static function getRedis(): ?RedisClient { return self::$redis; }
    public static function isConnected(): bool { return self::$connected; }
    
    public static function makeKey(string $type, string $input): string
    {
        return CACHE_PREFIX . $type . ':' . md5($input);
    }
    
    public static function get(string $key, callable $callback): void
    {
        if (!self::$connected || !self::$redis) { $callback(null); return; }
        self::$redis->get($key, fn($r) => $callback($r ? json_decode($r, true) : null));
    }
    
    public static function set(string $key, mixed $value, int $ttl = CACHE_TTL): void
    {
        if (!self::$connected || !self::$redis) return;
        self::$redis->setex($key, $ttl, json_encode($value, JSON_UNESCAPED_UNICODE));
    }
    
    public static function getStats(callable $callback): void
    {
        if (!self::$connected || !self::$redis) { $callback(['connected' => false]); return; }
        self::$redis->keys(CACHE_PREFIX . '*', fn($keys) => $callback([
            'connected' => true, 'cached_items' => count($keys ?? [])
        ]));
    }
    
    // ===================================
    // 对话历史管理（Chat ID + 上下文压缩）
    // ===================================
    
    private static int $chatHistoryTTL = 3600;      // 对话历史保存 1 小时
    private static int $maxHistoryLength = 20;       // 最多保存 20 轮对话
    private static int $summarizeThreshold = 8;      // 超过 8 轮后进行摘要
    private static int $keepRecentMessages = 4;      // 摘要后保留最近 4 轮对话
    
    /**
     * 获取对话上下文（包含摘要 + 最近消息）
     */
    public static function getChatContext(string $chatId, callable $callback): void
    {
        if (!self::$connected || !self::$redis || empty($chatId)) { 
            $callback(['summary' => null, 'messages' => [], 'total_rounds' => 0]); 
            return; 
        }
        
        $historyKey = CACHE_PREFIX . 'chat:' . $chatId;
        $summaryKey = CACHE_PREFIX . 'chat_summary:' . $chatId;
        
        // 同时获取摘要和历史
        self::$redis->get($summaryKey, function($summaryResult) use ($historyKey, $callback) {
            $summary = $summaryResult ? json_decode($summaryResult, true) : null;
            
            self::$redis->get($historyKey, function($historyResult) use ($summary, $callback) {
                $messages = $historyResult ? (json_decode($historyResult, true) ?? []) : [];
                $totalRounds = count($messages) / 2 + ($summary ? $summary['rounds_summarized'] : 0);
                
                $callback([
                    'summary' => $summary,
                    'messages' => $messages,
                    'total_rounds' => (int)$totalRounds
                ]);
            });
        });
    }
    
    /**
     * 获取对话历史（兼容旧方法）
     */
    public static function getChatHistory(string $chatId, callable $callback): void
    {
        self::getChatContext($chatId, fn($ctx) => $callback($ctx['messages']));
    }
    
    /**
     * 添加消息到对话历史
     */
    public static function addToChatHistory(string $chatId, array $message): void
    {
        if (!self::$connected || !self::$redis || empty($chatId)) return;
        
        $key = CACHE_PREFIX . 'chat:' . $chatId;
        self::$redis->get($key, function($result) use ($key, $message) {
            $history = $result ? (json_decode($result, true) ?? []) : [];
            $history[] = $message;
            
            // 限制历史长度（硬限制）
            if (count($history) > self::$maxHistoryLength * 2) {
                $history = array_slice($history, -self::$maxHistoryLength * 2);
            }
            
            self::$redis->setex($key, self::$chatHistoryTTL, json_encode($history, JSON_UNESCAPED_UNICODE));
        });
    }
    
    /**
     * 检查是否需要进行上下文压缩
     */
    public static function needsSummarization(string $chatId, callable $callback): void
    {
        if (!self::$connected || !self::$redis || empty($chatId)) { 
            $callback(false); 
            return; 
        }
        
        $key = CACHE_PREFIX . 'chat:' . $chatId;
        self::$redis->get($key, function($result) use ($callback) {
            $history = $result ? (json_decode($result, true) ?? []) : [];
            // 超过阈值（8轮 = 16条消息）时需要摘要
            $callback(count($history) >= self::$summarizeThreshold * 2);
        });
    }
    
    /**
     * 保存摘要并压缩历史
     */
    public static function saveSummaryAndCompress(string $chatId, string $summaryText): void
    {
        if (!self::$connected || !self::$redis || empty($chatId)) return;
        
        $historyKey = CACHE_PREFIX . 'chat:' . $chatId;
        $summaryKey = CACHE_PREFIX . 'chat_summary:' . $chatId;
        
        self::$redis->get($historyKey, function($historyResult) use ($historyKey, $summaryKey, $summaryText) {
            $history = $historyResult ? (json_decode($historyResult, true) ?? []) : [];
            
            // 获取现有摘要
            self::$redis->get($summaryKey, function($existingSummary) use ($history, $historyKey, $summaryKey, $summaryText) {
                $oldSummary = $existingSummary ? json_decode($existingSummary, true) : null;
                $oldRounds = $oldSummary ? $oldSummary['rounds_summarized'] : 0;
                
                // 计算被摘要的轮数
                $messagesToSummarize = count($history) - self::$keepRecentMessages * 2;
                $roundsSummarized = max(0, (int)($messagesToSummarize / 2));
                
                // 保存新摘要
                $newSummary = [
                    'text' => $summaryText,
                    'rounds_summarized' => $oldRounds + $roundsSummarized,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                self::$redis->setex($summaryKey, self::$chatHistoryTTL, json_encode($newSummary, JSON_UNESCAPED_UNICODE));
                
                // 只保留最近的消息
                $recentMessages = array_slice($history, -self::$keepRecentMessages * 2);
                self::$redis->setex($historyKey, self::$chatHistoryTTL, json_encode($recentMessages, JSON_UNESCAPED_UNICODE));
            });
        });
    }
    
    /**
     * 清空对话历史和摘要
     */
    public static function clearChatHistory(string $chatId): void
    {
        if (!self::$connected || !self::$redis || empty($chatId)) return;
        self::$redis->del(CACHE_PREFIX . 'chat:' . $chatId);
        self::$redis->del(CACHE_PREFIX . 'chat_summary:' . $chatId);
    }
    
    /**
     * 获取摘要提示词
     */
    public static function getSummarizePrompt(): string
    {
        return "请用中文简洁地总结以上对话的要点，包括：1) 用户讨论的主要话题 2) AI 给出的关键信息和结论 3) 任何重要的背景上下文。总结应该简短精炼（100-200字），便于后续对话参考。";
    }
}
