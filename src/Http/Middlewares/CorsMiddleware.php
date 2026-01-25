<?php
/**
 * CORS 中间件
 * 
 * 处理跨域请求，自动添加 CORS 头
 */

namespace SmartBook\Http\Middlewares;

use SmartBook\Http\Middleware;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

class CorsMiddleware implements Middleware
{
    private array $config;
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'allow_origin' => '*',
            'allow_methods' => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'allow_headers' => 'Content-Type, Authorization, X-Requested-With',
            'allow_credentials' => false,
            'max_age' => 86400,
        ], $config);
    }
    
    public function handle(TcpConnection $connection, Request $request, callable $next): mixed
    {
        // 处理 OPTIONS 预检请求
        if ($request->method() === 'OPTIONS') {
            $connection->send(new Response(200, [
                'Access-Control-Allow-Origin' => $this->config['allow_origin'],
                'Access-Control-Allow-Methods' => $this->config['allow_methods'],
                'Access-Control-Allow-Headers' => $this->config['allow_headers'],
                'Access-Control-Max-Age' => (string) $this->config['max_age'],
                'Access-Control-Allow-Credentials' => $this->config['allow_credentials'] ? 'true' : 'false',
            ], ''));
            return null;
        }
        
        // 继续处理请求
        $result = $next($connection, $request);
        
        // 如果返回数组，需要包装成 Response
        if (is_array($result)) {
            $connection->send(new Response(200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Access-Control-Allow-Origin' => $this->config['allow_origin'],
                'Access-Control-Allow-Credentials' => $this->config['allow_credentials'] ? 'true' : 'false',
            ], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
            return null;
        }
        
        return $result;
    }
}
