<?php
/**
 * 限流中间件
 * 
 * 基于 IP 地址的请求频率限制
 */

namespace SmartBook\Http\Middlewares;

use SmartBook\Http\Middleware;
use SmartBook\Http\Context;
use SmartBook\Cache\CacheService;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

class RateLimitMiddleware implements Middleware
{
    private int $maxRequests;
    private int $windowSeconds;
    
    public function __construct(int $maxRequests = 60, int $windowSeconds = 60)
    {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }
    
    public function handle(Context $ctx, callable $next): mixed
    {
        $ip = $ctx->ip();
        $key = "rate_limit:{$ip}";
        
        // 获取当前计数
        $count = 0;
        CacheService::get($key, function($cached) use (&$count) {
            $count = $cached ? (int) $cached : 0;
        });
        
        // 检查是否超过限制
        if ($count >= $this->maxRequests) {
            return $ctx->error(
                "Rate limit exceeded. Try again in {$this->windowSeconds} seconds.",
                429,
                [
                    'limit' => $this->maxRequests,
                    'window' => $this->windowSeconds,
                    'retry_after' => $this->windowSeconds,
                ]
            );
        }
        
        // 增加计数
        $count++;
        CacheService::set($key, $count, $this->windowSeconds);
        
        // 继续处理请求
        $result = $next($ctx);
        
        // 添加限流头信息
        if (is_array($result)) {
            $ctx->connection()->send(new Response(200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-RateLimit-Limit' => (string) $this->maxRequests,
                'X-RateLimit-Remaining' => (string) ($this->maxRequests - $count),
                'X-RateLimit-Reset' => (string) (time() + $this->windowSeconds),
            ], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
            return null;
        }
        
        return $result;
    }
}
