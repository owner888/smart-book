<?php
/**
 * Redis 缓存服务
 */

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
    
    public static function getSemanticIndex(callable $callback): void
    {
        if (!self::$connected || !self::$redis) { $callback([]); return; }
        self::$redis->get(CACHE_PREFIX . 'semantic_index', fn($r) => $callback($r ? json_decode($r, true) ?? [] : []));
    }
    
    public static function addToSemanticIndex(string $cacheKey, array $embedding, string $question): void
    {
        if (!self::$connected || !self::$redis || empty($embedding)) return;
        
        $indexKey = CACHE_PREFIX . 'semantic_index';
        self::$redis->get($indexKey, function($result) use ($indexKey, $cacheKey, $embedding, $question) {
            $index = $result ? (json_decode($result, true) ?? []) : [];
            $index[$cacheKey] = ['embedding' => $embedding, 'question' => $question];
            if (count($index) > 100) $index = array_slice($index, -100, 100, true);
            self::$redis->setex($indexKey, CACHE_TTL * 2, json_encode($index));
        });
    }
    
    public static function findSimilarCache(array $queryEmbedding, array $index, float $threshold = 0.96): ?array
    {
        if (empty($queryEmbedding)) return null;
        
        $bestMatch = null; $bestScore = -1; $bestQuestion = '';
        foreach ($index as $cacheKey => $item) {
            if (!isset($item['embedding']) || count($queryEmbedding) !== count($item['embedding'])) continue;
            $similarity = self::cosineSimilarity($queryEmbedding, $item['embedding']);
            if ($similarity > $threshold && $similarity > $bestScore) {
                $bestScore = $similarity;
                $bestMatch = $cacheKey;
                $bestQuestion = $item['question'] ?? '';
            }
        }
        return $bestMatch ? ['key' => $bestMatch, 'score' => $bestScore, 'question' => $bestQuestion] : null;
    }
    
    private static function cosineSimilarity(array $a, array $b): float
    {
        $len = count($a);
        if ($len !== count($b) || $len === 0) return 0.0;
        
        $dot = $normA = $normB = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $valA = (float)($a[$i] ?? 0); $valB = (float)($b[$i] ?? 0);
            $dot += $valA * $valB; $normA += $valA * $valA; $normB += $valB * $valB;
        }
        $normA = sqrt($normA); $normB = sqrt($normB);
        return ($normA < 1e-10 || $normB < 1e-10) ? 0.0 : $dot / ($normA * $normB);
    }
}
