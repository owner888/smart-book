<?php
/**
 * 缓存统计处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Logger;
use SmartBook\Http\Context;
use SmartBook\Http\ErrorHandler;
use SmartBook\Cache\CacheService;
use Workerman\Protocols\Http\Response;

class CacheHandler
{
    /**
     * 获取缓存统计信息
     */
    public static function getStats(Context $ctx): ?array
    {
        Logger::info('[Cache] 获取缓存统计信息');
        
        $connection = $ctx->connection();
        $jsonHeaders = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*'
        ];
        
        CacheService::getStats(function($stats) use ($connection, $jsonHeaders) {
            // 记录统计信息
            ErrorHandler::logOperation('Cache::getStats', 'success', [
                'keys' => $stats['keys'] ?? 0,
                'memory' => $stats['used_memory_human'] ?? 'unknown'
            ]);
            
            $connection->send(new Response(200, $jsonHeaders, json_encode($stats)));
        });
        
        return null;
    }
}
