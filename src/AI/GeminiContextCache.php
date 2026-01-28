<?php
/**
 * Gemini Context Caching - 缓存长文本减少 token 消耗
 * 
 * 显式缓存功能：将书籍内容等大文本缓存到 Gemini 服务器，
 * 后续请求直接引用缓存，减少重复传输和计费。
 * 
 * API 文档: https://ai.google.dev/gemini-api/docs/caching
 */

namespace SmartBook\AI;

use SmartBook\Cache\CacheService;

class GeminiContextCache
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    
    // 缓存 TTL (秒)，默认 1 小时
    const DEFAULT_TTL = 3600;
    
    // 最小 token 数要求（不同模型不同）
    const MIN_TOKENS = [
        'gemini-2.5-flash' => 1024,
        'gemini-2.5-pro' => 4096,
        'gemini-2.5-flash-lite' => 1024,
    ];
    
    public function __construct(
        string $apiKey,
        string $model = 'gemini-2.5-flash',
        string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta'
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    /**
     * 创建上下文缓存
     * 
     * @param string $content 要缓存的内容（如书籍全文）
     * @param string|null $displayName 缓存显示名称
     * @param string|null $systemInstruction 系统指令
     * @param int $ttl 缓存有效期（秒）
     * @return array{success: bool, name?: string, error?: string}
     */
    public function create(
        string $content,
        ?string $displayName = null,
        ?string $systemInstruction = null,
        int $ttl = self::DEFAULT_TTL
    ): array {
        $url = "{$this->baseUrl}/cachedContents?key={$this->apiKey}";
        
        $data = [
            'model' => "models/{$this->model}",
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $content,
                        ]
                    ],
                    'role' => 'user',
                ]
            ],
            'ttl' => "{$ttl}s",
        ];
        
        if ($displayName) {
            $data['displayName'] = $displayName;
        }
        
        if ($systemInstruction) {
            $data['systemInstruction'] = [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ];
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($error) {
            return ['success' => false, 'error' => "CURL error: {$error}"];
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMsg = $result['error']['message'] ?? $response;
            return ['success' => false, 'error' => $errorMsg];
        }
        
        // 缓存名称格式: cachedContents/xxx
        $cacheName = $result['name'] ?? null;
        
        if (!$cacheName) {
            return ['success' => false, 'error' => 'No cache name returned'];
        }
        
        // 保存到本地 Redis 方便查询
        $this->saveToLocal($cacheName, [
            'displayName' => $displayName,
            'model' => $this->model,
            'ttl' => $ttl,
            'createdAt' => time(),
            'expireAt' => time() + $ttl,
            'usageMetadata' => $result['usageMetadata'] ?? null,
        ]);
        
        return [
            'success' => true,
            'name' => $cacheName,
            'usageMetadata' => $result['usageMetadata'] ?? null,
            'expireTime' => $result['expireTime'] ?? null,
        ];
    }
    
    /**
     * 为书籍创建缓存
     * 
     * @param string $bookFile 书籍文件名
     * @param string $bookContent 书籍内容
     * @param int $ttl 缓存有效期（秒）
     * @param string|null $fileMd5 文件 MD5（可选，不传则用内容 MD5）
     * @return array{success: bool, name?: string, error?: string}
     */
    public function createForBook(string $bookFile, string $bookContent, int $ttl = self::DEFAULT_TTL, ?string $fileMd5 = null): array
    {
        // 使用文件 MD5 作为唯一标识（如果提供），否则用内容 MD5
        $cacheMd5 = $fileMd5 ?? md5($bookContent);
        
        // 检查是否已有有效缓存
        $existing = $this->getBookCache($cacheMd5);
        if ($existing && $existing['expireAt'] > time()) {
            return [
                'success' => true,
                'name' => $existing['name'],
                'cached' => true,
                'expireAt' => $existing['expireAt'],
            ];
        }
        
        $displayName = "book:{$cacheMd5}";
        $systemInstruction = "你是一个专业的书籍分析助手。以下是书籍《{$bookFile}》的完整内容，请基于书籍内容回答用户问题。";
        
        $result = $this->create($bookContent, $displayName, $systemInstruction, $ttl);
        
        if ($result['success']) {
            // 关联书籍和缓存
            $this->associateBookCache($cacheMd5, $result['name']);
        }
        
        return $result;
    }
    
    /**
     * 列出所有缓存
     */
    public function listCaches(): array
    {
        $url = "{$this->baseUrl}/cachedContents?key={$this->apiKey}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'Failed to list caches'];
        }
        
        $result = json_decode($response, true);
        
        return [
            'success' => true,
            'caches' => $result['cachedContents'] ?? [],
        ];
    }
    
    /**
     * 获取缓存详情
     */
    public function get(string $cacheName): ?array
    {
        $url = "{$this->baseUrl}/{$cacheName}?key={$this->apiKey}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        return json_decode($response, true);
    }
    
    /**
     * 更新缓存 TTL
     */
    public function updateTtl(string $cacheName, int $ttl): array
    {
        $url = "{$this->baseUrl}/{$cacheName}?key={$this->apiKey}";
        
        $data = [
            'ttl' => "{$ttl}s",
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            $result = json_decode($response, true);
            return ['success' => false, 'error' => $result['error']['message'] ?? 'Update failed'];
        }
        
        return ['success' => true];
    }
    
    /**
     * 删除缓存
     */
    public function delete(string $cacheName): array
    {
        $url = "{$this->baseUrl}/{$cacheName}?key={$this->apiKey}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200 && $httpCode !== 204) {
            return ['success' => false, 'error' => 'Delete failed'];
        }
        
        // 从本地移除
        $this->removeFromLocal($cacheName);
        
        return ['success' => true];
    }
    
    /**
     * 保存缓存信息到本地 Redis
     */
    private function saveToLocal(string $cacheName, array $data): void
    {
        $redis = CacheService::getRedis();
        if ($redis) {
            $key = "gemini:cache:{$cacheName}";
            $redis->setex($key, $data['ttl'] ?? 3600, json_encode($data));
        }
    }
    
    /**
     * 从本地移除缓存信息
     */
    private function removeFromLocal(string $cacheName): void
    {
        $redis = CacheService::getRedis();
        if ($redis) {
            $redis->del("gemini:cache:{$cacheName}");
        }
    }
    
    /**
     * 关联书籍和缓存
     * @param string $bookKey 书籍标识（通常是文件内容的 MD5）
     */
    private function associateBookCache(string $bookKey, string $cacheName): void
    {
        $redis = CacheService::getRedis();
        if ($redis) {
            $key = "gemini:book_cache:{$bookKey}";
            $redis->set($key, $cacheName);
        }
    }
    
    /**
     * 获取书籍关联的缓存
     * 
     * 直接从 Gemini API 查询，通过 displayName 匹配书籍
     * @param string $bookKey 书籍标识（通常是文件内容的 MD5）
     */
    public function getBookCache(string $bookKey): ?array
    {
        $displayName = "book:{$bookKey}";
        
        // 从 Gemini API 获取缓存列表
        $list = $this->listCaches();
        if (!$list['success']) {
            return null;
        }
        
        // 按 displayName 查找（返回最新的一个）
        $found = null;
        foreach ($list['caches'] as $cache) {
            if (($cache['displayName'] ?? '') === $displayName) {
                // 检查是否过期
                $expireTime = $cache['expireTime'] ?? null;
                if ($expireTime) {
                    $expireTimestamp = strtotime($expireTime);
                    if ($expireTimestamp > time()) {
                        // 返回最新的有效缓存
                        if (!$found || strtotime($cache['createTime']) > strtotime($found['createTime'])) {
                            $found = $cache;
                        }
                    }
                }
            }
        }
        
        if (!$found) {
            return null;
        }
        
        // 返回标准化的缓存信息
        return [
            'name' => $found['name'],
            'displayName' => $found['displayName'],
            'model' => $found['model'],
            'expireAt' => strtotime($found['expireTime']),
            'expireTime' => $found['expireTime'],
            'usageMetadata' => $found['usageMetadata'] ?? null,
        ];
    }
    
    /**
     * 获取或创建书籍缓存
     * @param string $bookFile 书籍文件名（用于显示）
     * @param string $bookContent 书籍内容
     */
    public function getOrCreateBookCache(string $bookFile, string $bookContent, int $ttl = self::DEFAULT_TTL): array
    {
        $contentMd5 = md5($bookContent);
        
        // 先检查本地缓存
        $existing = $this->getBookCache($contentMd5);
        if ($existing && $existing['expireAt'] > time()) {
            return [
                'success' => true,
                'name' => $existing['name'],
                'cached' => true,
            ];
        }
        
        return $this->createForBook($bookFile, $bookContent, $ttl);
    }
    
    /**
     * 估算 token 数（粗略估计）
     */
    public static function estimateTokens(string $text): int
    {
        // 英文约 4 字符 = 1 token，中文约 1-2 字符 = 1 token
        // 这里用保守估计
        $len = mb_strlen($text);
        $asciiCount = strlen(preg_replace('/[^\x00-\x7F]/', '', $text));
        $nonAsciiCount = $len - $asciiCount;
        
        return intval($asciiCount / 4 + $nonAsciiCount);
    }
    
    /**
     * 检查内容是否满足最低 token 要求
     */
    public function meetsMinTokens(string $content): bool
    {
        $tokens = self::estimateTokens($content);
        $minRequired = self::MIN_TOKENS[$this->model] ?? 1024;
        
        return $tokens >= $minRequired;
    }
    
    /**
     * 获取缓存使用统计
     * 
     * @return array{
     *   success: bool,
     *   total_caches: int,
     *   total_tokens: int,
     *   estimated_storage_cost: float,
     *   estimated_hourly_cost: float,
     *   cache_limit: int,
     *   usage_percentage: float,
     *   caches?: array
     * }
     */
    public function getStatistics(): array
    {
        $listResult = $this->listCaches();
        
        if (!$listResult['success']) {
            return [
                'success' => false,
                'error' => $listResult['error'] ?? 'Failed to get statistics'
            ];
        }
        
        $caches = $listResult['caches'];
        $totalCaches = count($caches);
        $totalTokens = 0;
        $cacheDetails = [];
        
        foreach ($caches as $cache) {
            $tokens = $cache['usageMetadata']['totalTokenCount'] ?? 0;
            $totalTokens += $tokens;
            
            $expireTime = $cache['expireTime'] ?? null;
            $createTime = $cache['createTime'] ?? null;
            $ttlSeconds = 0;
            
            if ($expireTime && $createTime) {
                $expire = strtotime($expireTime);
                $create = strtotime($createTime);
                $ttlSeconds = max(0, $expire - time());
            }
            
            $cacheDetails[] = [
                'name' => $cache['name'] ?? 'Unknown',
                'displayName' => $cache['displayName'] ?? 'N/A',
                'tokens' => $tokens,
                'model' => str_replace('models/', '', $cache['model'] ?? ''),
                'ttl_remaining_hours' => round($ttlSeconds / 3600, 2),
                'expire_time' => $expireTime,
            ];
        }
        
        // 成本估算（Gemini 2.0/2.5 Flash 定价参考）
        // 缓存创建：$0.000001/token（一次性）
        // 缓存存储：$0.00000025/token/小时
        $storageHourlyCost = $totalTokens * 0.00000025;  // 每小时存储成本
        
        // 缓存限制
        $cacheLimit = 1000;  // Gemini API 默认限制
        $usagePercentage = ($totalCaches / $cacheLimit) * 100;
        
        return [
            'success' => true,
            'total_caches' => $totalCaches,
            'total_tokens' => $totalTokens,
            'estimated_storage_cost' => round($storageHourlyCost, 6),  // 每小时
            'estimated_daily_cost' => round($storageHourlyCost * 24, 4),  // 每天
            'estimated_monthly_cost' => round($storageHourlyCost * 24 * 30, 2),  // 每月
            'cache_limit' => $cacheLimit,
            'usage_percentage' => round($usagePercentage, 2),
            'caches' => $cacheDetails,
        ];
    }
    
    /**
     * 格式化统计信息为日志字符串
     */
    public function formatStatistics(array $stats): string
    {
        if (!$stats['success']) {
            return "缓存统计获取失败: " . ($stats['error'] ?? 'Unknown error');
        }
        
        $lines = [];
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "📊 Context Cache 使用统计";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "📦 缓存数量: {$stats['total_caches']}/{$stats['cache_limit']} ({$stats['usage_percentage']}%)";
        $lines[] = "🔢 总 Tokens: " . number_format($stats['total_tokens']);
        $lines[] = "";
        $lines[] = "💰 预估成本:";
        $lines[] = "  • 每小时: $" . number_format($stats['estimated_storage_cost'], 6);
        $lines[] = "  • 每天: $" . number_format($stats['estimated_daily_cost'], 4);
        $lines[] = "  • 每月: $" . number_format($stats['estimated_monthly_cost'], 2) . " (约 ¥" . number_format($stats['estimated_monthly_cost'] * 7.2, 2) . ")";
        
        if ($stats['usage_percentage'] > 80) {
            $lines[] = "";
            $lines[] = "⚠️  警告: 缓存使用率超过 80%，建议清理旧缓存";
        }
        
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
        
        return implode("\n", $lines);
    }
    
    /**
     * 清理过期或长期未使用的缓存
     * 
     * @param int $daysUnused 清理超过指定天数未使用的缓存
     * @return array{deleted: int, errors: array}
     */
    public function cleanup(int $daysUnused = 7): array
    {
        $listResult = $this->listCaches();
        
        if (!$listResult['success']) {
            return ['deleted' => 0, 'errors' => ['Failed to list caches']];
        }
        
        $deleted = 0;
        $errors = [];
        $cutoffTime = time() - ($daysUnused * 86400);
        
        foreach ($listResult['caches'] as $cache) {
            $cacheName = $cache['name'] ?? null;
            if (!$cacheName) continue;
            
            // 检查是否应该删除
            $shouldDelete = false;
            
            // 检查过期时间
            if (isset($cache['expireTime'])) {
                $expireTime = strtotime($cache['expireTime']);
                if ($expireTime < time()) {
                    $shouldDelete = true;
                }
            }
            
            // 检查创建时间（如果超过指定天数）
            if (!$shouldDelete && isset($cache['createTime'])) {
                $createTime = strtotime($cache['createTime']);
                if ($createTime < $cutoffTime) {
                    $shouldDelete = true;
                }
            }
            
            if ($shouldDelete) {
                $result = $this->delete($cacheName);
                if ($result['success']) {
                    $deleted++;
                } else {
                    $errors[] = "Failed to delete {$cacheName}: " . ($result['error'] ?? 'Unknown');
                }
            }
        }
        
        return [
            'deleted' => $deleted,
            'errors' => $errors,
        ];
    }
}
