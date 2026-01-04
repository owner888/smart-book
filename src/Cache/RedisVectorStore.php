<?php
/**
 * Redis 8.0 向量存储
 */

namespace SmartBook\Cache;

use Workerman\Redis\Client as RedisClient;

class RedisVectorStore
{
    private static ?RedisClient $redis = null;
    private static string $vectorKey = 'smartbook:vectors';
    private static string $chunksKey = 'smartbook:chunks';
    
    public static function init(RedisClient $redis): void { self::$redis = $redis; }
    
    public static function importFromJson(string $jsonPath, ?callable $onProgress = null): void
    {
        if (!self::$redis || !file_exists($jsonPath)) return;
        
        $data = json_decode(file_get_contents($jsonPath), true);
        if (!$data || empty($data['chunks'])) return;
        
        $total = count($data['chunks']);
        $imported = 0;
        
        foreach ($data['chunks'] as $i => $chunk) {
            $chunkId = "chunk:{$i}";
            self::$redis->hSet(self::$chunksKey, $chunkId, json_encode([
                'text' => $chunk['text'], 'index' => $i
            ], JSON_UNESCAPED_UNICODE));
            
            if (!empty($chunk['embedding'])) {
                $args = [self::$vectorKey, $chunkId, 'VALUES'];
                foreach ($chunk['embedding'] as $val) $args[] = (string)$val;
                call_user_func_array([self::$redis, 'rawCommand'], array_merge(['VADD'], $args));
            }
            
            $imported++;
            if ($onProgress && $imported % 100 === 0) $onProgress($imported, $total);
        }
        echo "✅ 向量导入完成: {$imported}/{$total}\n";
    }
    
    public static function isImported(callable $callback): void
    {
        if (!self::$redis) { $callback(false, 0); return; }
        self::$redis->rawCommand('VCARD', self::$vectorKey, fn($count) => $callback($count > 0, $count ?? 0));
    }
    
    public static function search(array $queryVector, int $topK, callable $callback): void
    {
        if (!self::$redis) { $callback([]); return; }
        
        $args = ['VSIM', self::$vectorKey];
        foreach ($queryVector as $val) $args[] = (string)$val;
        $args[] = 'COUNT'; $args[] = (string)$topK;
        
        $args[] = function($results) use ($callback) {
            if (!$results || !is_array($results)) { $callback([]); return; }
            $chunkIds = [];
            for ($i = 0; $i < count($results); $i += 2) {
                $chunkIds[] = ['id' => $results[$i], 'score' => $results[$i + 1] ?? 1.0];
            }
            self::getChunksText($chunkIds, $callback);
        };
        
        call_user_func_array([self::$redis, 'rawCommand'], $args);
    }
    
    private static function getChunksText(array $chunkIds, callable $callback): void
    {
        if (empty($chunkIds)) { $callback([]); return; }
        
        $results = []; $pending = count($chunkIds);
        foreach ($chunkIds as $item) {
            self::$redis->hGet(self::$chunksKey, $item['id'], function($data) use ($item, &$results, &$pending, $callback) {
                if ($data) {
                    $results[] = ['chunk' => json_decode($data, true), 'score' => floatval($item['score'])];
                }
                if (--$pending === 0) {
                    usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
                    $callback($results);
                }
            });
        }
    }
    
    public static function clear(): void
    {
        if (!self::$redis) return;
        self::$redis->del(self::$vectorKey);
        self::$redis->del(self::$chunksKey);
    }
    
    public static function getStats(callable $callback): void
    {
        if (!self::$redis) { $callback(['initialized' => false]); return; }
        self::$redis->rawCommand('VCARD', self::$vectorKey, fn($count) => $callback([
            'initialized' => true, 'vector_count' => $count ?? 0
        ]));
    }
}
