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
}
