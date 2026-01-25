<?php
/**
 * 认证中间件
 * 
 * 验证 API Token 或 Bearer Token
 */

namespace SmartBook\Http\Middlewares;

use SmartBook\Http\Middleware;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

class AuthMiddleware implements Middleware
{
    private array $tokens;
    private string $headerName;
    
    public function __construct(array $tokens = [], string $headerName = 'Authorization')
    {
        // 默认使用环境变量中的 API_TOKEN
        $this->tokens = $tokens ?: [getenv('API_TOKEN') ?: 'default-secret-token'];
        $this->headerName = $headerName;
    }
    
    public function handle(TcpConnection $connection, Request $request, callable $next): mixed
    {
        $token = $this->extractToken($request);
        
        if (!$token || !$this->isValidToken($token)) {
            $connection->send(new Response(401, [
                'Content-Type' => 'application/json; charset=utf-8',
                'WWW-Authenticate' => 'Bearer realm="API"',
            ], json_encode([
                'error' => 'Unauthorized',
                'message' => 'Invalid or missing authentication token',
            ], JSON_UNESCAPED_UNICODE)));
            return null;
        }
        
        // 认证成功，继续处理
        return $next($connection, $request);
    }
    
    private function extractToken(Request $request): ?string
    {
        $header = $request->header($this->headerName);
        
        if (!$header) {
            // 尝试从查询参数获取
            return $request->get('token');
        }
        
        // 支持 Bearer Token
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $matches[1];
        }
        
        return $header;
    }
    
    private function isValidToken(string $token): bool
    {
        return in_array($token, $this->tokens, true);
    }
}
