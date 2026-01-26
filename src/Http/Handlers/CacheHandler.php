<?php
/**
 * 缓存统计处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Http\Context;
use SmartBook\Cache\CacheService;
use Workerman\Protocols\Http\Response;

class CacheHandler
{
    /**
     * 获取缓存统计信息
     */
    public static function getStats(Context $ctx): ?array
    {
        $connection = $ctx->connection();
        $jsonHeaders = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*'
        ];
        
        CacheService::getStats(function($stats) use ($connection, $jsonHeaders) {
            $connection->send(new Response(200, $jsonHeaders, json_encode($stats)));
        });
        
        return null;
    }
}
