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
    // 对话历史管理（Chat ID）
    // ===================================
    
    private static int $chatHistoryTTL = 3600; // 对话历史保存 1 小时
    private static int $maxHistoryLength = 20; // 最多保存 20 轮对话
    
    /**
     * 获取对话历史
     */
    public static function getChatHistory(string $chatId, callable $callback): void
    {
        if (!self::$connected || !self::$redis || empty($chatId)) { 
            $callback([]); 
            return; 
        }
        $key = CACHE_PREFIX . 'chat:' . $chatId;
        self::$redis->get($key, fn($r) => $callback($r ? json_decode($r, true) ?? [] : []));
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
            
            // 限制历史长度
            if (count($history) > self::$maxHistoryLength * 2) {
                $history = array_slice($history, -self::$maxHistoryLength * 2);
            }
            
            self::$redis->setex($key, self::$chatHistoryTTL, json_encode($history, JSON_UNESCAPED_UNICODE));
        });
    }
    
    /**
     * 清空对话历史
     */
    public static function clearChatHistory(string $chatId): void
    {
        if (!self::$connected || !self::$redis || empty($chatId)) return;
        $key = CACHE_PREFIX . 'chat:' . $chatId;
        self::$redis->del($key);
    }
}
