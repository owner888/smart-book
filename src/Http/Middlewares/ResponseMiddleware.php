<?php
/**
 * 响应格式化中间件
 * 
 * 自动包装响应为统一格式: {success, data, error, meta}
 */

namespace SmartBook\Http\Middlewares;

use SmartBook\Http\Middleware;
use SmartBook\Http\Context;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

class ResponseMiddleware implements Middleware
{
    private bool $enabled;
    private array $excludePaths;
    
    public function __construct(bool $enabled = true, array $excludePaths = [])
    {
        $this->enabled = $enabled;
        $this->excludePaths = $excludePaths;
    }
    
    public function handle(Context $ctx, callable $next): mixed
    {
        // 如果中间件已禁用，直接继续
        if (!$this->enabled) {
            return $next($ctx);
        }
        
        // 如果是排除路径，直接继续
        $path = $ctx->path();
        foreach ($this->excludePaths as $excludePath) {
            if (str_starts_with($path, $excludePath)) {
                return $next($ctx);
            }
        }
        
        // 执行请求
        $result = $next($ctx);
        
        // 如果已发送响应（返回 null），不处理
        if ($result === null) {
            return null;
        }
        
        // 如果是 Response 对象，不处理
        if ($result instanceof Response) {
            return $result;
        }
        
        // 包装响应
        $wrapped = $this->wrapResponse($result);
        
        // 发送包装后的响应
        $ctx->connection()->send(new Response(
            $wrapped['status'],
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Response-Format' => 'wrapped',
            ],
            json_encode($wrapped['body'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        ));
        
        return null;
    }
    
    /**
     * 包装响应为统一格式
     */
    private function wrapResponse(mixed $result): array
    {
        // 默认成功响应
        $status = 200;
        $body = [
            'success' => true,
            'data' => null,
            'error' => null,
            'meta' => [
                'timestamp' => date('Y-m-d H:i:s'),
                'version' => '1.0',
            ],
        ];
        
        // 如果是数组
        if (is_array($result)) {
            // 检查是否是错误响应
            if (isset($result['error'])) {
                $status = $this->getErrorStatus($result);
                $body['success'] = false;
                $body['error'] = [
                    'code' => $result['error_code'] ?? $this->getErrorCode($status),
                    'message' => $result['error'] ?? $result['message'] ?? 'Unknown error',
                    'details' => $result['details'] ?? null,
                ];
                $body['data'] = null;
            }
            // 检查是否已经是标准格式
            elseif (isset($result['success']) || isset($result['data'])) {
                $body = array_merge($body, $result);
            }
            // 普通数据响应
            else {
                $body['data'] = $result;
            }
        }
        // 其他类型直接作为 data
        else {
            $body['data'] = $result;
        }
        
        return ['status' => $status, 'body' => $body];
    }
    
    /**
     * 根据错误信息判断 HTTP 状态码
     */
    private function getErrorStatus(array $result): int
    {
        // 如果明确指定了状态码
        if (isset($result['status_code'])) {
            return (int) $result['status_code'];
        }
        
        // 根据错误类型推断
        $error = $result['error'] ?? '';
        
        if (stripos($error, 'not found') !== false) {
            return 404;
        }
        if (stripos($error, 'unauthorized') !== false) {
            return 401;
        }
        if (stripos($error, 'forbidden') !== false) {
            return 403;
        }
        if (stripos($error, 'invalid') !== false) {
            return 400;
        }
        if (stripos($error, 'too many') !== false) {
            return 429;
        }
        
        return 500;
    }
    
    /**
     * 获取错误代码
     */
    private function getErrorCode(int $status): string
    {
        return match($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            429 => 'TOO_MANY_REQUESTS',
            500 => 'INTERNAL_ERROR',
            default => 'ERROR',
        };
    }
}
