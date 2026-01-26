<?php
/**
 * HTTP 上下文（类似 Gin 的 Context）
 * 
 * 封装请求和响应，提供便捷的 API
 */

namespace SmartBook\Http;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

class Context
{
    private TcpConnection $connection;
    private Request $request;
    private array $params;
    private array $data = [];
    private bool $aborted = false;
    
    public function __construct(TcpConnection $connection, Request $request, array $params = [])
    {
        $this->connection = $connection;
        $this->request = $request;
        $this->params = $params;
    }
    
    // ===================================
    // 请求参数访问
    // ===================================
    
    /**
     * 获取路径参数
     * 
     * @param string $key 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }
    
    /**
     * 获取所有路径参数
     */
    public function params(): array
    {
        return $this->params;
    }
    
    /**
     * 获取查询参数（GET）
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->request->get();
        }
        return $this->request->get($key, $default);
    }
    
    /**
     * 获取 POST 数据
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->request->post();
        }
        return $this->request->post($key, $default);
    }
    
    /**
     * 获取 JSON 请求体
     */
    public function jsonBody(): mixed
    {
        $body = $this->request->rawBody();
        return $body ? json_decode($body, true) : null;
    }
    
    /**
     * 获取请求头
     */
    public function header(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->request->header();
        }
        return $this->request->header($key, $default);
    }
    
    /**
     * 获取请求方法
     */
    public function method(): string
    {
        return $this->request->method();
    }
    
    /**
     * 获取请求路径
     */
    public function path(): string
    {
        return $this->request->path();
    }
    
    /**
     * 获取完整 URI（路径+查询字符串）
     */
    public function uri(): string
    {
        return $this->request->uri();
    }
    
    /**
     * 获取客户端 IP
     */
    public function ip(): string
    {
        return $this->request->header('x-forwarded-for') 
            ?: $this->request->header('x-real-ip')
            ?: $this->connection->getRemoteIp();
    }
    
    // ===================================
    // 响应方法
    // ===================================
    
    /**
     * 发送 JSON 响应
     */
    public function json(array $data, int $status = 200, array $headers = []): void
    {
        $this->send(
            $status,
            array_merge([
                'Content-Type' => 'application/json; charset=utf-8',
            ], $headers),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
    
    /**
     * 发送文本响应
     */
    public function text(string $text, int $status = 200, array $headers = []): void
    {
        $this->send(
            $status,
            array_merge([
                'Content-Type' => 'text/plain; charset=utf-8',
            ], $headers),
            $text
        );
    }
    
    /**
     * 发送 HTML 响应
     */
    public function html(string $html, int $status = 200, array $headers = []): void
    {
        $this->send(
            $status,
            array_merge([
                'Content-Type' => 'text/html; charset=utf-8',
            ], $headers),
            $html
        );
    }
    
    /**
     * 发送原始响应
     */
    public function send(int $status, array $headers, string $body): void
    {
        $this->connection->send(new Response($status, $headers, $body));
        $this->aborted = true;
    }
    
    /**
     * 重定向
     */
    public function redirect(string $url, int $status = 302): void
    {
        $this->send($status, ['Location' => $url], '');
    }
    
    /**
     * 发送文件
     */
    public function file(string $filepath): void
    {
        if (!file_exists($filepath)) {
            $this->json(['error' => 'File not found'], 404);
            return;
        }
        
        $mimeType = mime_content_type($filepath) ?: 'application/octet-stream';
        $this->send(200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) filesize($filepath),
        ], file_get_contents($filepath));
    }
    
    // ===================================
    // 快捷响应方法
    // ===================================
    
    /**
     * 成功响应（200）
     */
    public function success(array $data = [], string $message = 'Success'): void
    {
        $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }
    
    /**
     * 错误响应
     */
    public function error(string $message, int $status = 400, array $details = []): void
    {
        $this->json([
            'success' => false,
            'error' => $message,
            'details' => $details,
        ], $status);
    }
    
    /**
     * 未找到（404）
     */
    public function notFound(string $message = 'Not Found'): void
    {
        $this->error($message, 404);
    }
    
    /**
     * 未授权（401）
     */
    public function unauthorized(string $message = 'Unauthorized'): void
    {
        $this->error($message, 401);
    }
    
    /**
     * 禁止访问（403）
     */
    public function forbidden(string $message = 'Forbidden'): void
    {
        $this->error($message, 403);
    }
    
    /**
     * 服务器错误（500）
     */
    public function serverError(string $message = 'Internal Server Error'): void
    {
        $this->error($message, 500);
    }
    
    // ===================================
    // 中间件数据传递
    // ===================================
    
    /**
     * 设置上下文数据
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
    
    /**
     * 获取上下文数据
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
    
    /**
     * 检查是否存在
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }
    
    // ===================================
    // 流程控制
    // ===================================
    
    /**
     * 中断请求（停止后续中间件执行）
     */
    public function abort(int $status = 500, string $message = ''): void
    {
        if ($message) {
            $this->error($message, $status);
        }
        $this->aborted = true;
    }
    
    /**
     * 是否已中断
     */
    public function isAborted(): bool
    {
        return $this->aborted;
    }
    
    // ===================================
    // 原始对象访问（兼容性）
    // ===================================
    
    /**
     * 获取原始连接对象
     */
    public function connection(): TcpConnection
    {
        return $this->connection;
    }
    
    /**
     * 获取原始请求对象
     */
    public function request(): Request
    {
        return $this->request;
    }
}
