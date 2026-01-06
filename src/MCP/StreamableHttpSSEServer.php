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
use Workerman\Timer;

class StreamableHttpSSEServer
{
    private string $booksDir;
    private array $sseConnections = []; // session_id => connection
    private array $sseTimers = []; // session_id => timer_id (心跳定时器)
    private array $sessions = []; // 会话数据存储 (持久化)
    private string $sessionsFile; // 会话持久化文件路径
    private StreamableHttpServer $httpServer;
    
    // 心跳间隔（秒）- 每 15 秒发送一次心跳
    private const HEARTBEAT_INTERVAL = 15;
    
    private const CORS_HEADERS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Mcp-Session-Id',
        'Access-Control-Expose-Headers' => 'Mcp-Session-Id',
    ];
    
    public function __construct(string $booksDir)
    {
        $this->booksDir = $booksDir;
        $this->sessionsFile = $booksDir . '/.mcp_sse_sessions.json';
        $this->httpServer = new StreamableHttpServer($booksDir, true); // 启用调试日志
        
        // 从文件加载持久化的会话
        $this->loadSessions();
    }
    
    /**
     * 从文件加载会话
     */
    private function loadSessions(): void
    {
        if (file_exists($this->sessionsFile)) {
            $data = file_get_contents($this->sessionsFile);
            $sessions = json_decode($data, true);
            
            if (is_array($sessions)) {
                // 过滤掉过期的会话（超过 24 小时）
                $now = time();
                foreach ($sessions as $id => $session) {
                    $lastAccessAt = $session['lastAccessAt'] ?? $session['createdAt'] ?? 0;
                    
                    // 如果会话在 24 小时内活跃过，保留它
                    if (($now - $lastAccessAt) < 86400) {
                        $this->sessions[$id] = $session;
                    }
                }
                
                $this->log('INFO', '[SSE] Loaded sessions from file', [
                    'total' => count($sessions),
                    'active' => count($this->sessions),
                    'file' => $this->sessionsFile,
                ]);
            }
        }
    }
    
    /**
     * 保存会话到文件
     */
    private function saveSessions(): void
    {
        $data = json_encode($this->sessions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents($this->sessionsFile, $data, LOCK_EX);
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
        // 检查是否有客户端传来的 session ID（用于会话恢复）
        $existingSessionId = $request->header('Mcp-Session-Id') ?? $request->get('session_id');
        
        // 如果客户端提供了 session ID 且该会话存在于持久化存储中，则恢复会话
        if ($existingSessionId && isset($this->sessions[$existingSessionId])) {
            $sessionId = $existingSessionId;
            $this->log('INFO', "[SSE] Restoring existing session", ['sessionId' => $sessionId]);
            
            // 更新最后访问时间
            $this->sessions[$sessionId]['lastAccessAt'] = time();
            $this->sessions[$sessionId]['restored'] = true;
            $this->saveSessions();
        } else {
            // 生成新的 session ID
            $sessionId = bin2hex(random_bytes(16));
            
            // 创建新会话并持久化
            $this->sessions[$sessionId] = [
                'createdAt' => time(),
                'lastAccessAt' => time(),
                'clientInfo' => [],
                'selectedBook' => null,
                'restored' => false,
            ];
            $this->saveSessions();
            
            $this->log('INFO', "[SSE] Created new session", ['sessionId' => $sessionId]);
        }
        
        // SSE 响应头
        $headers = array_merge(self::CORS_HEADERS, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'Mcp-Session-Id' => $sessionId,
        ]);
        
        $connection->send(new Response(200, $headers, ''));
        
        // 保存连接（内存中，不持久化）
        $this->sseConnections[$sessionId] = $connection;
        
        // 发送 endpoint 事件（告诉客户端消息端点）
        $this->sendSSE($connection, 'endpoint', "/message?session_id={$sessionId}");
        
        // 启动心跳定时器 - 每隔 HEARTBEAT_INTERVAL 秒发送一次心跳
        $timerId = Timer::add(self::HEARTBEAT_INTERVAL, function() use ($sessionId, $connection) {
            // 检查连接是否仍然有效
            if (!isset($this->sseConnections[$sessionId])) {
                return;
            }
            
            // 发送 SSE 心跳注释（: ping）
            // SSE 规范中以冒号开头的行是注释，不会被解析为事件，但会保持连接活跃
            $heartbeat = ": ping " . time() . "\n\n";
            try {
                $connection->send($heartbeat);
            } catch (\Exception $e) {
                $this->log('WARN', "[SSE] Heartbeat failed", ['sessionId' => $sessionId, 'error' => $e->getMessage()]);
            }
        });
        
        // 保存定时器 ID
        $this->sseTimers[$sessionId] = $timerId;
        
        // 处理连接关闭 - 清理定时器和连接，保留持久化的会话数据
        $connection->onClose = function() use ($sessionId) {
            // 停止心跳定时器
            if (isset($this->sseTimers[$sessionId])) {
                Timer::del($this->sseTimers[$sessionId]);
                unset($this->sseTimers[$sessionId]);
            }
            
            unset($this->sseConnections[$sessionId]);
            $this->log('INFO', "[SSE] Connection closed, session data preserved", ['sessionId' => $sessionId]);
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
                // 保存客户端信息到会话
                if (isset($this->sessions[$sessionId])) {
                    $this->sessions[$sessionId]['clientInfo'] = $params['clientInfo'] ?? [];
                    $this->sessions[$sessionId]['protocolVersion'] = $params['protocolVersion'] ?? '2025-03-26';
                    $this->sessions[$sessionId]['lastAccessAt'] = time();
                    $this->saveSessions();
                    
                    $this->log('INFO', "[SSE] Session initialized", [
                        'sessionId' => $sessionId,
                        'clientInfo' => $params['clientInfo'] ?? [],
                    ]);
                }
                
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
                
                // 获取会话数据
                $session = $this->sessions[$sessionId] ?? null;
                
                $result = match ($toolName) {
                    'list_books' => $this->toolListBooks(),
                    'get_book_info' => $this->toolGetBookInfo($session),
                    'select_book' => $this->toolSelectBook($arguments, $sessionId),
                    'search_book' => $this->toolSearchBook($arguments, $session),
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
            
            // 处理 logging/setLevel
            if ($method === 'logging/setLevel') {
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => new \stdClass(),
                ];
            }
            
            // 处理 prompts/get
            if ($method === 'prompts/get') {
                $promptName = $params['name'] ?? '';
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'description' => "Prompt: {$promptName}",
                        'messages' => [
                            ['role' => 'user', 'content' => ['type' => 'text', 'text' => "使用 {$promptName} 提示词"]],
                        ],
                    ],
                ];
            }
            
            // 处理 completion/complete
            if ($method === 'completion/complete') {
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'completion' => [
                            'values' => [],
                            'total' => 0,
                            'hasMore' => false,
                        ],
                    ],
                ];
            }
            
            // 处理 tasks 相关方法
            if ($method === 'tasks/list') {
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tasks' => []]];
            }
            if ($method === 'tasks/get' || $method === 'tasks/cancel' || $method === 'tasks/result') {
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => ['code' => -32602, 'message' => 'Task not found'],
                ];
            }
            
            // 处理 notifications/cancelled
            if ($method === 'notifications/cancelled') {
                return []; // 通知不需要响应
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
    
    // ==================== 工具方法 ====================
    
    private function getBooksList(): array
    {
        $books = [];
        if (is_dir($this->booksDir)) {
            foreach (scandir($this->booksDir) as $file) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, ['epub', 'txt'])) {
                    $baseName = pathinfo($file, PATHINFO_FILENAME);
                    $books[] = [
                        'file' => $file,
                        'title' => $baseName,
                        'hasIndex' => file_exists($this->booksDir . '/' . $baseName . '_index.json'),
                    ];
                }
            }
        }
        return $books;
    }
    
    private function toolListBooks(): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode(['books' => $this->getBooksList()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ]],
        ];
    }
    
    private function toolGetBookInfo(?array $session): array
    {
        // 优先使用会话中保存的书籍选择，然后是全局选择
        $selectedBook = $session['selectedBook'] ?? $GLOBALS['selected_book'] ?? null;
        
        // 如果没有选择，尝试自动选择第一本有索引的书
        if (!$selectedBook) {
            $selectedBook = $this->autoSelectBook();
        }
        
        if (!$selectedBook) {
            return ['content' => [['type' => 'text', 'text' => 'No book selected and no indexed books found']]];
        }
        
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'file' => $selectedBook['file'],
                    'hasIndex' => file_exists($selectedBook['cache'] ?? ''),
                ], JSON_UNESCAPED_UNICODE),
            ]],
        ];
    }
    
    private function toolSelectBook(array $args, string $sessionId): array
    {
        $bookFile = $args['book'] ?? '';
        if (empty($bookFile)) {
            throw new \Exception('Missing book parameter');
        }
        
        $bookPath = $this->booksDir . '/' . $bookFile;
        if (!file_exists($bookPath)) {
            throw new \Exception("Book not found: {$bookFile}");
        }
        
        $baseName = pathinfo($bookFile, PATHINFO_FILENAME);
        $selectedBook = [
            'file' => $bookFile,
            'path' => $bookPath,
            'cache' => $this->booksDir . '/' . $baseName . '_index.json',
        ];
        
        // 保存到会话数据（持久化）
        if (isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId]['selectedBook'] = $selectedBook;
            $this->sessions[$sessionId]['lastAccessAt'] = time();
            $this->saveSessions();
            
            $this->log('INFO', "[SSE] Book selected and saved to session", [
                'sessionId' => $sessionId,
                'book' => $bookFile,
            ]);
        }
        
        // 同时更新全局选择（兼容性）
        $GLOBALS['selected_book'] = $selectedBook;
        
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'success' => true,
                    'book' => $bookFile,
                    'hasIndex' => file_exists($selectedBook['cache']),
                ], JSON_UNESCAPED_UNICODE),
            ]],
        ];
    }
    
    private function toolSearchBook(array $args, ?array $session): array
    {
        $query = $args['query'] ?? '';
        if (empty($query)) {
            throw new \Exception('Missing query parameter');
        }
        
        // 优先使用会话中保存的书籍选择
        $selectedBook = $session['selectedBook'] ?? $GLOBALS['selected_book'] ?? null;
        
        // 如果没有选择，尝试自动选择第一本有索引的书
        if (!$selectedBook) {
            $selectedBook = $this->autoSelectBook();
        }
        
        if (!$selectedBook || !file_exists($selectedBook['cache'])) {
            throw new \Exception('No book index available. Please select a book with an index first.');
        }
        
        // 简化搜索（关键词匹配）
        $indexData = json_decode(file_get_contents($selectedBook['cache']), true);
        $results = [];
        $chunks = $indexData['chunks'] ?? [];
        
        foreach ($chunks as $chunk) {
            if (stripos($chunk['text'], $query) !== false) {
                $results[] = [
                    'text' => mb_substr($chunk['text'], 0, 200) . '...',
                    'chapter' => $chunk['chapter'] ?? 'Unknown',
                ];
                if (count($results) >= 5) break;
            }
        }
        
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'query' => $query,
                    'book' => $selectedBook['file'],
                    'results' => $results,
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ]],
        ];
    }
    
    /**
     * 自动选择第一本有索引的书籍
     */
    private function autoSelectBook(): ?array
    {
        // 首先检查全局选择
        if (isset($GLOBALS['selected_book']['path']) && file_exists($GLOBALS['selected_book']['path'])) {
            return $GLOBALS['selected_book'];
        }
        
        if (!is_dir($this->booksDir)) {
            return null;
        }
        
        foreach (scandir($this->booksDir) as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['epub', 'txt'])) continue;
            
            $baseName = pathinfo($file, PATHINFO_FILENAME);
            $indexFile = $this->booksDir . '/' . $baseName . '_index.json';
            
            if (file_exists($indexFile)) {
                $selectedBook = [
                    'file' => $file,
                    'path' => $this->booksDir . '/' . $file,
                    'cache' => $indexFile,
                ];
                
                // 设置为全局选择
                $GLOBALS['selected_book'] = $selectedBook;
                
                return $selectedBook;
            }
        }
        
        return null;
    }
    
    private function toolServerStatus(): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'status' => 'healthy',
                    'transport' => 'sse',
                    'connections' => count($this->sseConnections),
                    'books' => count($this->getBooksList()),
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ]],
        ];
    }
}
