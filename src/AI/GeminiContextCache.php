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
        curl_close($ch);
        
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
     * @return array{success: bool, name?: string, error?: string}
     */
    public function createForBook(string $bookFile, string $bookContent, int $ttl = self::DEFAULT_TTL): array
    {
        // 检查是否已有有效缓存
        $existing = $this->getBookCache($bookFile);
        if ($existing && $existing['expireAt'] > time()) {
            return [
                'success' => true,
                'name' => $existing['name'],
                'cached' => true,
                'expireAt' => $existing['expireAt'],
            ];
        }
        
        $displayName = "book:{$bookFile}";
        $systemInstruction = "你是一个专业的书籍分析助手。以下是书籍《{$bookFile}》的完整内容，请基于书籍内容回答用户问题。";
        
        $result = $this->create($bookContent, $displayName, $systemInstruction, $ttl);
        
        if ($result['success']) {
            // 关联书籍和缓存
            $this->associateBookCache($bookFile, $result['name']);
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
        curl_close($ch);
        
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
        curl_close($ch);
        
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
        curl_close($ch);
        
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
        curl_close($ch);
        
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
     */
    private function associateBookCache(string $bookFile, string $cacheName): void
    {
        $redis = CacheService::getRedis();
        if ($redis) {
            $key = "gemini:book_cache:{$bookFile}";
            $redis->set($key, $cacheName);
        }
    }
    
    /**
     * 获取书籍关联的缓存
     */
    public function getBookCache(string $bookFile): ?array
    {
        $redis = CacheService::getRedis();
        if (!$redis) {
            return null;
        }
        
        $cacheName = $redis->get("gemini:book_cache:{$bookFile}");
        if (!$cacheName) {
            return null;
        }
        
        $cacheKey = "gemini:cache:{$cacheName}";
        $data = $redis->get($cacheKey);
        
        if (!$data) {
            return null;
        }
        
        $info = json_decode($data, true);
        $info['name'] = $cacheName;
        
        return $info;
    }
    
    /**
     * 获取或创建书籍缓存
     */
    public function getOrCreateBookCache(string $bookFile, callable $getContent, int $ttl = self::DEFAULT_TTL): array
    {
        // 先检查本地缓存
        $existing = $this->getBookCache($bookFile);
        if ($existing && $existing['expireAt'] > time()) {
            return [
                'success' => true,
                'name' => $existing['name'],
                'cached' => true,
            ];
        }
        
        // 获取内容并创建缓存
        $content = $getContent();
        if (!$content) {
            return ['success' => false, 'error' => 'Failed to get book content'];
        }
        
        return $this->createForBook($bookFile, $content, $ttl);
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
}
