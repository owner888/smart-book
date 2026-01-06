<?php
/**
 * MCP SSE Server (Server-Sent Events)
 * 
 * 支持 MCP 2025-11-25 SSE Transport
 * - GET /sse: 建立 SSE 连接
 * - POST /message: 发送 JSON-RPC 消息
 * - 支持进度通知、资源变更通知等
 */

namespace SmartBook\MCP;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

class StreamableHttpSSEServer
{
    private string $booksDir;
    private array $sseConnections = []; // session_id => connection
    private StreamableHttpServer $httpServer;
    
    private const CORS_HEADERS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Mcp-Session-Id',
        'Access-Control-Expose-Headers' => 'Mcp-Session-Id',
    ];
    
    public function __construct(string $booksDir)
    {
        $this->booksDir = $booksDir;
        $this->httpServer = new StreamableHttpServer($booksDir);
    }
    
    /**
     * 处理 HTTP 请求
     */
    public function handleRequest(TcpConnection $connection, Request $request): void
    {
        $method = $request->method();
        $path = $request->path();
        
        // CORS
        if ($method === 'OPTIONS') {
            $connection->send(new Response(204, self::CORS_HEADERS, ''));
            return;
        }
        
        // GET /sse: 建立 SSE 连接
        if ($path === '/sse' && $method === 'GET') {
            $this->handleSSEConnection($connection, $request);
            return;
        }
        
        // POST /message: 发送 JSON-RPC 消息
        if ($path === '/message' && $method === 'POST') {
            $this->handleMessage($connection, $request);
            return;
        }
        
        // 健康检查
        if ($path === '/health') {
            $this->sendJson($connection, [
                'status' => 'ok',
                'transport' => 'sse',
                'connections' => count($this->sseConnections),
            ]);
            return;
        }
        
        // 404
        $this->sendJson($connection, ['error' => 'Not Found'], 404);
    }
    
    /**
     * 建立 SSE 连接
     */
    private function handleSSEConnection(TcpConnection $connection, Request $request): void
    {
        // 生成 session ID
        $sessionId = bin2hex(random_bytes(16));
        
        // SSE 响应头
        $headers = array_merge(self::CORS_HEADERS, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'Mcp-Session-Id' => $sessionId,
        ]);
        
        $connection->send(new Response(200, $headers, ''));
        
        // 保存连接
        $this->sseConnections[$sessionId] = $connection;
        
        // 发送 endpoint 事件（告诉客户端消息端点）
        $this->sendSSE($connection, 'endpoint', "/message?session_id={$sessionId}");
        
        // 处理连接关闭
        $connection->onClose = function() use ($sessionId) {
            unset($this->sseConnections[$sessionId]);
        };
    }
    
    /**
     * 处理 JSON-RPC 消息
     */
    private function handleMessage(TcpConnection $connection, Request $request): void
    {
        $sessionId = $request->get('session_id') ?? $request->header('Mcp-Session-Id');
        
        // 获取 SSE 连接（用于发送通知）
        $sseConnection = $this->sseConnections[$sessionId] ?? null;
        
        // 解析请求
        $body = $request->rawBody();
        $data = json_decode($body, true);
        
        if ($data === null) {
            $this->sendJson($connection, [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Parse error'],
            ]);
            return;
        }
        
        // 处理 JSON-RPC（复用 HTTP Server 的逻辑）
        $this->httpServer->handleRequest($connection, $request);
    }
    
    /**
     * 发送 SSE 事件
     */
    private function sendSSE(TcpConnection $connection, string $event, string $data): void
    {
        $message = "event: {$event}\ndata: {$data}\n\n";
        $connection->send($message);
    }
    
    /**
     * 向所有连接发送通知
     */
    public function broadcast(string $event, array $data): void
    {
        $message = json_encode($data, JSON_UNESCAPED_UNICODE);
        foreach ($this->sseConnections as $connection) {
            $this->sendSSE($connection, $event, $message);
        }
    }
    
    /**
     * 向指定 session 发送通知
     */
    public function sendNotification(string $sessionId, string $method, array $params = []): void
    {
        $connection = $this->sseConnections[$sessionId] ?? null;
        if ($connection) {
            $notification = [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $params,
            ];
            $this->sendSSE($connection, 'message', json_encode($notification, JSON_UNESCAPED_UNICODE));
        }
    }
    
    /**
     * 发送进度通知
     */
    public function sendProgress(string $sessionId, string $progressToken, int $progress, ?int $total = null, ?string $message = null): void
    {
        $params = [
            'progressToken' => $progressToken,
            'progress' => $progress,
        ];
        if ($total !== null) {
            $params['total'] = $total;
        }
        if ($message !== null) {
            $params['message'] = $message;
        }
        $this->sendNotification($sessionId, 'notifications/progress', $params);
    }
    
    /**
     * 发送 JSON 响应
     */
    private function sendJson(TcpConnection $connection, array $data, int $statusCode = 200): void
    {
        $connection->send(new Response(
            $statusCode,
            array_merge(self::CORS_HEADERS, ['Content-Type' => 'application/json']),
            json_encode($data, JSON_UNESCAPED_UNICODE)
        ));
    }
}
