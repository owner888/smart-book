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
        $this->httpServer = new StreamableHttpServer($booksDir, true); // 启用调试日志
    }
    
    /**
     * 处理 HTTP 请求
     */
    public function handleRequest(TcpConnection $connection, Request $request): void
    {
        $method = $request->method();
        $path = $request->path();
        
        // 日志：记录所有请求
        $this->log('REQUEST', "[SSE] {$method} {$path}", [
            'headers' => $request->header(),
        ]);
        
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
        
        // POST /message: 发送 JSON-RPC 消息（支持多种路径）
        if ($method === 'POST' && in_array($path, ['/message', '/', '/mcp'])) {
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
        
        // 404 - 记录详细日志
        $this->log('WARN', "[SSE] 404 Not Found", [
            'method' => $method,
            'path' => $path,
        ]);
        $this->sendJson($connection, ['error' => 'Not Found', 'path' => $path], 404);
    }
    
    /**
     * 简单日志方法
     */
    private function log(string $type, string $message, array $data = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $color = match ($type) {
            'ERROR' => "\033[31m",
            'WARN' => "\033[33m",
            'INFO' => "\033[36m",
            'REQUEST' => "\033[34m",
            default => "\033[0m",
        };
        $reset = "\033[0m";
        
        echo "{$color}[{$timestamp}] [{$type}]{$reset} {$message}\n";
        if (!empty($data)) {
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        }
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
     * 
     * 根据 MCP SSE 规范：
     * - POST /message 返回 HTTP 202 Accepted
     * - 实际响应通过 SSE 流发送 (event: message)
     */
    private function handleMessage(TcpConnection $connection, Request $request): void
    {
        $sessionId = $request->get('session_id') ?? $request->header('Mcp-Session-Id');
        
        $this->log('INFO', "[SSE] Processing message", [
            'sessionId' => $sessionId,
        ]);
        
        // 获取 SSE 连接（用于发送响应）
        $sseConnection = $this->sseConnections[$sessionId] ?? null;
        
        if (!$sseConnection) {
            $this->log('WARN', "[SSE] No SSE connection for session", ['sessionId' => $sessionId]);
            $this->sendJson($connection, [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'No active SSE connection'],
            ], 400);
            return;
        }
        
        // 解析请求
        $body = $request->rawBody();
        $data = json_decode($body, true);
        
        $this->log('INFO', "[SSE] Message body", [
            'data' => $data,
        ]);
        
        if ($data === null) {
            // 解析错误，直接通过 SSE 返回
            $errorResponse = json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Parse error'],
            ], JSON_UNESCAPED_UNICODE);
            $this->sendSSE($sseConnection, 'message', $errorResponse);
            $connection->send(new Response(202, self::CORS_HEADERS, ''));
            return;
        }
        
        // 处理 JSON-RPC 请求
        $response = $this->processJsonRpc($data, $sessionId);
        
        $this->log('INFO', "[SSE] Sending response via SSE", [
            'response' => $response,
        ]);
        
        // 通过 SSE 流发送响应
        $this->sendSSE($sseConnection, 'message', json_encode($response, JSON_UNESCAPED_UNICODE));
        
        // 返回 HTTP 202 Accepted
        $connection->send(new Response(202, self::CORS_HEADERS, ''));
        
        $this->log('INFO', "[SSE] Response sent successfully");
    }
    
    /**
     * 处理 JSON-RPC 请求
     */
    private function processJsonRpc(array $data, string $sessionId): array
    {
        $method = $data['method'] ?? '';
        $params = $data['params'] ?? [];
        $id = $data['id'] ?? null;
        
        try {
            // 处理 initialize 方法
            if ($method === 'initialize') {
                $result = [
                    'protocolVersion' => '2025-03-26',
                    'serverInfo' => [
                        'name' => 'smart-book',
                        'title' => 'Smart Book AI Server',
                        'version' => '1.0.0',
                    ],
                    'capabilities' => [
                        'tools' => ['listChanged' => false],
                        'resources' => ['subscribe' => false, 'listChanged' => false],
                        'prompts' => ['listChanged' => false],
                    ],
                ];
                
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => $result,
                ];
            }
            
            // 处理 notifications/initialized
            if ($method === 'notifications/initialized') {
                return []; // 通知不需要响应
            }
            
            // 处理 tools/list
            if ($method === 'tools/list') {
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'tools' => [
                            [
                                'name' => 'search_book',
                                'description' => 'Search content in the current book',
                                'inputSchema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'query' => ['type' => 'string', 'description' => 'Search query'],
                                    ],
                                    'required' => ['query'],
                                ],
                            ],
                            [
                                'name' => 'get_book_info',
                                'description' => 'Get information about the current book',
                                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                            ],
                            [
                                'name' => 'list_books',
                                'description' => 'List all available books',
                                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                            ],
                            [
                                'name' => 'select_book',
                                'description' => 'Select a book to use',
                                'inputSchema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'book' => ['type' => 'string', 'description' => 'Book filename'],
                                    ],
                                    'required' => ['book'],
                                ],
                            ],
                            [
                                'name' => 'server_status',
                                'description' => 'Get MCP server status',
                                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                            ],
                        ],
                    ],
                ];
            }
            
            // 处理 resources/list
            if ($method === 'resources/list') {
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'resources' => [
                            [
                                'uri' => 'book://library/list',
                                'name' => 'Book Library',
                                'description' => 'List of all available books',
                                'mimeType' => 'application/json',
                            ],
                        ],
                    ],
                ];
            }
            
            // 处理 prompts/list
            if ($method === 'prompts/list') {
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => ['prompts' => []],
                ];
            }
            
            // 处理 resources/templates/list
            if ($method === 'resources/templates/list') {
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => ['resourceTemplates' => []],
                ];
            }
            
            // 处理 tools/call - 调用工具
            if ($method === 'tools/call') {
                $toolName = $params['name'] ?? '';
                $arguments = $params['arguments'] ?? [];
                
                $result = match ($toolName) {
                    'list_books' => $this->toolListBooks(),
                    'get_book_info' => $this->toolGetBookInfo(),
                    'select_book' => $this->toolSelectBook($arguments),
                    'search_book' => $this->toolSearchBook($arguments),
                    'server_status' => $this->toolServerStatus(),
                    default => throw new \Exception("Unknown tool: {$toolName}"),
                };
                
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => $result,
                ];
            }
            
            // 处理 resources/read
            if ($method === 'resources/read') {
                $uri = $params['uri'] ?? '';
                
                if ($uri === 'book://library/list') {
                    $books = $this->getBooksList();
                    return [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => [
                            'contents' => [[
                                'uri' => $uri,
                                'mimeType' => 'application/json',
                                'text' => json_encode(['books' => $books], JSON_UNESCAPED_UNICODE),
                            ]],
                        ],
                    ];
                }
                
                throw new \Exception("Resource not found: {$uri}");
            }
            
            // 处理 ping
            if ($method === 'ping') {
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => new \stdClass(),
                ];
            }
            
            // 其他方法返回错误
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32601, 'message' => "Method not found: {$method}"],
            ];
            
        } catch (\Exception $e) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32000, 'message' => $e->getMessage()],
            ];
        }
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
