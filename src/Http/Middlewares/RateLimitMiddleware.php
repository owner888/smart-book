<?php
/**
 * 限流中间件
 * 
 * 基于 IP 地址的请求频率限制
 */

namespace SmartBook\Http\Middlewares;

use SmartBook\Http\Middleware;
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
    
    public function handle(TcpConnection $connection, Request $request, callable $next): mixed
    {
        $ip = $request->header('x-forwarded-for') ?: $connection->getRemoteIp();
        $key = "rate_limit:{$ip}";
        
        // 获取当前计数
        $count = 0;
        CacheService::get($key, function($cached) use (&$count) {
            $count = $cached ? (int) $cached : 0;
        });
        
        // 检查是否超过限制
        if ($count >= $this->maxRequests) {
            $retryAfter = $this->windowSeconds;
            
            $connection->send(new Response(429, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $this->maxRequests,
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) (time() + $retryAfter),
            ], json_encode([
                'error' => 'Too Many Requests',
                'message' => "Rate limit exceeded. Try again in {$retryAfter} seconds.",
                'limit' => $this->maxRequests,
                'window' => $this->windowSeconds,
            ], JSON_UNESCAPED_UNICODE)));
            return null;
        }
        
        // 增加计数
        $count++;
        CacheService::set($key, $count, $this->windowSeconds);
        
        // 继续处理请求
        $result = $next($connection, $request);
        
        // 添加限流头信息
        if (is_array($result)) {
            $connection->send(new Response(200, [
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
