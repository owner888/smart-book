<?php
/**
 * MCP Streamable HTTP Server
 * 
 * 实现 MCP 2024-11-05 Streamable HTTP Transport 协议
 * 参考: https://spec.modelcontextprotocol.io/specification/basic/transports/
 * 
 * 特点：
 * - 单一 POST 端点处理所有 JSON-RPC 请求
 * - 支持会话管理 (Mcp-Session-Id header)
 * - 支持批量请求
 * - 不使用 SSE，使用标准 HTTP 响应
 */

namespace SmartBook\MCP;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

class StreamableHttpServer
{
    private string $booksDir;
    private array $sessions = [];
    private bool $debug = false; // 启用调试日志
    private string $sessionsFile; // session 持久化文件路径
    
    // 服务器信息
    private const SERVER_INFO = [
        'name' => 'smart-book',
        'version' => '1.0.0',
    ];
    
    // 支持的协议版本 (Cline 3.46.1 使用 2025-11-25)
    private const PROTOCOL_VERSION = '2025-03-26';
    
    // CORS 响应头
    private const CORS_HEADERS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Mcp-Session-Id, Accept',
        'Access-Control-Expose-Headers' => 'Mcp-Session-Id',
    ];
    
    public function __construct(string $booksDir, bool $debug = false)
    {
        $this->booksDir = $booksDir;
        $this->debug = $debug;
        
        // 设置 session 持久化文件路径
        $this->sessionsFile = $booksDir . '/.mcp_sessions.json';
        
        // 从文件加载 sessions
        $this->loadSessions();
    }
    
    /**
     * 从文件加载 sessions
     */
    private function loadSessions(): void
    {
        if (file_exists($this->sessionsFile)) {
            $data = file_get_contents($this->sessionsFile);
            $sessions = json_decode($data, true);
            
            if (is_array($sessions)) {
                // 过滤掉过期的 session（超过 24 小时）
                $now = time();
                foreach ($sessions as $id => $session) {
                    $createdAt = $session['createdAt'] ?? 0;
                    $lastAccessAt = $session['lastAccessAt'] ?? $createdAt;
                    
                    // 如果 session 在 24 小时内活跃过，保留它
                    if (($now - $lastAccessAt) < 86400) {
                        $this->sessions[$id] = $session;
                    }
                }
                
                $this->log('INFO', 'Loaded sessions from file', [
                    'total' => count($sessions),
                    'active' => count($this->sessions),
                    'file' => $this->sessionsFile,
                ]);
            }
        }
    }
    
    /**
     * 保存 sessions 到文件
     */
    private function saveSessions(): void
    {
        $data = json_encode($this->sessions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents($this->sessionsFile, $data, LOCK_EX);
    }
    
    /**
     * 打印调试日志
     * ERROR 类型的日志总是输出，其他类型只在 debug 模式下输出
     */
    private function log(string $type, string $message, mixed $data = null): void
    {
        // ERROR 类型总是输出，其他类型只在 debug 模式下输出
        if (!$this->debug && $type !== 'ERROR') {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $color = match ($type) {
            'REQUEST' => "\033[36m",   // 青色
            'RESPONSE' => "\033[32m",  // 绿色
            'ERROR' => "\033[31m",     // 红色
            'INFO' => "\033[33m",      // 黄色
            'WARN' => "\033[35m",      // 紫色
            default => "\033[0m",
        };
        $reset = "\033[0m";
        
        echo "{$color}[{$timestamp}] [{$type}]{$reset} {$message}\n";
        
        if ($data !== null) {
            $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            echo "{$color}{$jsonData}{$reset}\n";
        }
        echo "\n";
    }
    
    /**
     * 处理 HTTP 请求
     */
    public function handleRequest(TcpConnection $connection, Request $request): void
    {
        $method = $request->method();
        $path = $request->path();
        
        // 处理 CORS 预检请求
        if ($method === 'OPTIONS') {
            $connection->send(new Response(204, self::CORS_HEADERS, ''));
            return;
        }
        
        // MCP Streamable HTTP 主端点
        if ($path === '/mcp' || $path === '/') {
            $this->handleMCPEndpoint($connection, $request);
            return;
        }
        
        // 兼容性：保留简单的 REST 风格端点
        if ($path === '/mcp/tools' && $method === 'GET') {
            $this->sendJsonResponse($connection, ['tools' => $this->getTools()]);
            return;
        }
        
        // 健康检查端点
        if ($path === '/health' || $path === '/mcp/health') {
            $this->sendJsonResponse($connection, $this->getHealthStatus());
            return;
        }
        
        // 404 Not Found
        $this->sendJsonResponse($connection, [
            'error' => 'Not Found',
            'path' => $path,
        ], 404);
    }
    
    /**
     * 处理 MCP Streamable HTTP 端点
     */
    private function handleMCPEndpoint(TcpConnection $connection, Request $request): void
    {
        $method = $request->method();
        
        // GET 请求：返回 405 表示不支持 SSE 流
        // MCP SDK 会发送 GET 请求检查是否支持 SSE 流
        // 返回 405 告诉 SDK 只支持 POST 请求的 JSON 响应
        if ($method === 'GET') {
            $connection->send(new Response(405, array_merge(self::CORS_HEADERS, [
                'Allow' => 'POST, DELETE, OPTIONS',
            ]), ''));
            return;
        }
        
        // DELETE 请求：终止会话
        if ($method === 'DELETE') {
            $sessionId = $request->header('Mcp-Session-Id');
            if ($sessionId && isset($this->sessions[$sessionId])) {
                unset($this->sessions[$sessionId]);
                $this->saveSessions(); // 持久化
            }
            $connection->send(new Response(204, self::CORS_HEADERS, ''));
            return;
        }
        
        // POST 请求：处理 JSON-RPC 消息
        if ($method === 'POST') {
            $this->handleJsonRpcRequest($connection, $request);
            return;
        }
        
        // 不支持的方法
        $this->sendJsonResponse($connection, [
            'error' => 'Method Not Allowed',
        ], 405);
    }
    
    /**
     * 处理 JSON-RPC 请求
     */
    private function handleJsonRpcRequest(TcpConnection $connection, Request $request): void
    {
        $contentType = $request->header('Content-Type', '');
        
        // 验证 Content-Type
        if (!str_contains($contentType, 'application/json')) {
            $this->log('ERROR', 'Invalid Content-Type', ['contentType' => $contentType]);
            $this->sendJsonRpcError($connection, null, -32700, 'Invalid Content-Type, expected application/json');
            return;
        }
        
        $body = $request->rawBody();
        $data = json_decode($body, true);
        
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->log('ERROR', 'Parse error', ['body' => $body, 'error' => json_last_error_msg()]);
            $this->sendJsonRpcError($connection, null, -32700, 'Parse error: ' . json_last_error_msg());
            return;
        }
        
        // 获取会话 ID
        $sessionId = $request->header('Mcp-Session-Id');
        
        // 如果客户端发送了 session ID 但服务器不认识（可能是服务器重启后持久化文件被清理了），
        // 自动为该 session ID 重建一个空会话，这样客户端不需要重新初始化
        if ($sessionId && !isset($this->sessions[$sessionId])) {
            $this->log('INFO', 'Unknown session ID (session expired or server data lost), recreating session', [
                'receivedSessionId' => $sessionId,
            ]);
            // 重建会话
            $this->sessions[$sessionId] = [
                'clientInfo' => [],
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => [],
                'createdAt' => time(),
                'lastAccessAt' => time(),
                'selectedBook' => null,
                'restored' => true,  // 标记这是恢复的会话
            ];
            // 持久化新会话
            $this->saveSessions();
        } elseif ($sessionId && isset($this->sessions[$sessionId])) {
            // 更新最后访问时间
            $this->sessions[$sessionId]['lastAccessAt'] = time();
            // 不频繁保存，只在关键操作时保存
        }
        
        // 打印请求日志
        $this->log('REQUEST', 'MCP JSON-RPC Request', [
            'sessionId' => $sessionId,
            'data' => $data,
        ]);
        
        // 检查是否是批量请求
        if (isset($data[0])) {
            // 批量请求 - 返回 JSON 数组
            $responses = [];
            foreach ($data as $req) {
                $response = $this->processJsonRpcMessage($req, $sessionId);
                if ($response !== null) {
                    unset($response['_sessionId']);
                    $responses[] = $response;
                }
            }
            
            if (!empty($responses)) {
                $this->sendJsonResponse($connection, $responses, 200, $sessionId);
            } else {
                $connection->send(new Response(202, self::CORS_HEADERS, ''));
            }
        } else {
            // 单个请求 - 返回 JSON 对象
            $response = $this->processJsonRpcMessage($data, $sessionId);
            
            if ($response !== null) {
                // 如果是 initialize 请求，设置新的 session ID
                $responseSessionId = $response['_sessionId'] ?? $sessionId;
                unset($response['_sessionId']);
                
                $this->sendJsonResponse($connection, $response, 200, $responseSessionId);
            } else {
                // 通知消息，无需响应
                $connection->send(new Response(202, array_merge(self::CORS_HEADERS, [
                    'Mcp-Session-Id' => $sessionId ?? '',
                ]), ''));
            }
        }
    }
    
    /**
     * 处理单个 JSON-RPC 消息
     */
    private function processJsonRpcMessage(array $message, ?string &$sessionId): ?array
    {
        $method = $message['method'] ?? '';
        $params = $message['params'] ?? [];
        $id = $message['id'] ?? null;
        
        // 通知消息（没有 id）不需要响应
        $isNotification = $id === null;
        
        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize($params, $sessionId),
                'notifications/initialized' => null, // 通知，无需响应
                'notifications/cancelled' => null,
                'ping' => new \stdClass(), // 返回空对象
                'tools/list' => ['tools' => $this->getTools()],
                'tools/call' => $this->handleToolCall($params, $sessionId),
                'resources/list' => $this->handleResourcesList($sessionId),
                'resources/templates/list' => ['resourceTemplates' => []],
                'resources/read' => $this->handleResourcesRead($params, $sessionId),
                'prompts/list' => ['prompts' => []],
                'prompts/get' => throw new \Exception('Prompt not found'),
                default => throw new \Exception("Method not found: {$method}"),
            };
            
            // 通知消息不返回响应
            if ($isNotification || $result === null) {
                return null;
            }
            
            $response = [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ];
            
            // 对于 initialize，附加 session ID
            if ($method === 'initialize' && $sessionId) {
                $response['_sessionId'] = $sessionId;
            }
            
            return $response;
            
        } catch (\Throwable $e) {
            // 记录详细错误日志（ERROR 类型总是输出）
            $this->log('ERROR', "Exception in method '{$method}'", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => array_slice($e->getTrace(), 0, 5), // 只保留前5层调用栈
            ]);
            
            if ($isNotification) {
                return null;
            }
            
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32000,
                    'message' => $e->getMessage(),
                    'data' => [
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine(),
                    ],
                ],
            ];
        }
    }
    
    /**
     * 处理 initialize 请求
     */
    private function handleInitialize(array $params, ?string &$sessionId): array
    {
        // 创建新会话
        $sessionId = $this->createSession();
        
        // 存储客户端信息
        $this->sessions[$sessionId] = [
            'clientInfo' => $params['clientInfo'] ?? [],
            'protocolVersion' => $params['protocolVersion'] ?? self::PROTOCOL_VERSION,
            'capabilities' => $params['capabilities'] ?? [],
            'createdAt' => time(),
            'lastAccessAt' => time(),
            'selectedBook' => null,
        ];
        
        // 持久化 session
        $this->saveSessions();
        
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'serverInfo' => self::SERVER_INFO,
            'capabilities' => $this->getCapabilities(),
        ];
    }
    
    /**
     * 处理 tools/call 请求
     */
    private function handleToolCall(array $params, ?string $sessionId): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];
        
        if (empty($toolName)) {
            throw new \Exception('Missing tool name');
        }
        
        // 获取会话数据
        $session = $sessionId ? ($this->sessions[$sessionId] ?? null) : null;
        
        $result = match ($toolName) {
            'list_books' => $this->toolListBooks(),
            'get_book_info' => $this->toolGetBookInfo($session),
            'select_book' => $this->toolSelectBook($arguments, $session, $sessionId),
            'search_book' => $this->toolSearchBook($arguments, $session),
            'server_status' => $this->toolServerStatus(),
            default => throw new \Exception("Unknown tool: {$toolName}"),
        };
        
        return $result;
    }
    
    /**
     * 获取服务器能力
     */
    private function getCapabilities(): array
    {
        return [
            'tools' => new \stdClass(),
            'resources' => new \stdClass(),
            // 'prompts' => ['listChanged' => false],
        ];
    }
    
    /**
     * 获取工具列表
     */
    private function getTools(): array
    {
        return [
            [
                'name' => 'search_book',
                'description' => 'Search content in the current book using hybrid vector + keyword search',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query',
                        ],
                        'top_k' => [
                            'type' => 'integer',
                            'description' => 'Number of results (default: 5)',
                            'default' => 5,
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_book_info',
                'description' => 'Get information about the current book',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
            [
                'name' => 'list_books',
                'description' => 'List all available books',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
            [
                'name' => 'select_book',
                'description' => 'Select a book to use',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'book' => [
                            'type' => 'string',
                            'description' => 'Book filename',
                        ],
                    ],
                    'required' => ['book'],
                ],
            ],
            [
                'name' => 'server_status',
                'description' => 'Get MCP server status including active sessions, available books, and health information',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
        ];
    }
    
    /**
     * 工具：列出所有书籍
     */
    private function toolListBooks(): array
    {
        $books = [];
        
        if (!is_dir($this->booksDir)) {
            return ['content' => [['type' => 'text', 'text' => json_encode(['books' => []])]]];
        }
        
        foreach (scandir($this->booksDir) as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['epub', 'txt'])) continue;
            
            $baseName = pathinfo($file, PATHINFO_FILENAME);
            $indexFile = $this->booksDir . '/' . $baseName . '_index.json';
            
            $title = $baseName;
            if ($ext === 'epub') {
                try {
                    $metadata = \SmartBook\Parser\EpubParser::extractMetadata($this->booksDir . '/' . $file);
                    $title = $metadata['title'] ?? $baseName;
                } catch (\Exception $e) {}
            }
            
            $books[] = [
                'file' => $file,
                'title' => $title,
                'format' => strtoupper($ext),
                'hasIndex' => file_exists($indexFile),
            ];
        }
        
        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode(['books' => $books], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
            ],
        ];
    }
    
    /**
     * 工具：获取书籍信息
     */
    private function toolGetBookInfo(?array $session): array
    {
        $selectedBook = $session['selectedBook'] ?? null;
        
        // 如果没有选择书籍，尝试自动选择第一本有索引的书
        if (!$selectedBook) {
            $selectedBook = $this->autoSelectBook();
        }
        
        if (!$selectedBook) {
            return [
                'content' => [['type' => 'text', 'text' => 'No book selected and no indexed books found']],
            ];
        }
        
        $info = [
            'file' => $selectedBook['file'],
            'hasIndex' => file_exists($selectedBook['cache']),
        ];
        
        $ext = strtolower(pathinfo($selectedBook['file'], PATHINFO_EXTENSION));
        if ($ext === 'epub') {
            try {
                $metadata = \SmartBook\Parser\EpubParser::extractMetadata($selectedBook['path']);
                $info['title'] = $metadata['title'] ?? pathinfo($selectedBook['file'], PATHINFO_FILENAME);
                $info['authors'] = $metadata['authors'] ?? '';
            } catch (\Exception $e) {}
        } else {
            $info['title'] = pathinfo($selectedBook['file'], PATHINFO_FILENAME);
        }
        
        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode($info, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
            ],
        ];
    }
    
    /**
     * 工具：选择书籍
     */
    private function toolSelectBook(array $args, ?array &$session, ?string $sessionId): array
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
        $indexPath = $this->booksDir . '/' . $baseName . '_index.json';
        
        $selectedBook = [
            'file' => $bookFile,
            'path' => $bookPath,
            'cache' => $indexPath,
        ];
        
        // 更新会话中的选择
        if ($sessionId && isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId]['selectedBook'] = $selectedBook;
            $this->sessions[$sessionId]['lastAccessAt'] = time();
            $this->saveSessions(); // 持久化
        }
        
        // 同时更新全局选择（为了兼容其他功能）
        $GLOBALS['selected_book'] = $selectedBook;
        
        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode([
                    'success' => true,
                    'book' => $bookFile,
                    'hasIndex' => file_exists($indexPath),
                ], JSON_UNESCAPED_UNICODE)],
            ],
        ];
    }
    
    /**
     * 工具：搜索书籍
     */
    private function toolSearchBook(array $args, ?array $session): array
    {
        $query = $args['query'] ?? '';
        $topK = $args['top_k'] ?? 5;
        
        if (empty($query)) {
            throw new \Exception('Missing query parameter');
        }
        
        // 获取当前书籍
        $selectedBook = $session['selectedBook'] ?? $this->autoSelectBook();
        
        if (!$selectedBook || !file_exists($selectedBook['cache'])) {
            throw new \Exception('No book index available. Please select a book with an index first.');
        }
        
        $embedder = new \SmartBook\RAG\EmbeddingClient(GEMINI_API_KEY);
        $queryEmbedding = $embedder->embedQuery($query);
        
        $vectorStore = new \SmartBook\RAG\VectorStore($selectedBook['cache']);
        $results = $vectorStore->hybridSearch($query, $queryEmbedding, $topK, 0.5);
        
        $output = [];
        foreach ($results as $i => $r) {
            $output[] = [
                'index' => $i + 1,
                'text' => $r['chunk']['text'],
                'score' => round($r['score'] * 100, 1) . '%',
            ];
        }
        
        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode([
                    'query' => $query,
                    'book' => $selectedBook['file'],
                    'results' => $output,
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
            ],
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
    
    /**
     * 创建新会话
     */
    private function createSession(): string
    {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * 工具：获取服务器状态
     */
    private function toolServerStatus(): array
    {
        $status = $this->getHealthStatus();
        
        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
            ],
        ];
    }
    
    /**
     * 处理 resources/list 请求
     */
    private function handleResourcesList(?string $sessionId): array
    {
        $resources = [];
        $session = $sessionId ? ($this->sessions[$sessionId] ?? null) : null;
        
        // 1. 书籍列表资源
        $resources[] = [
            'uri' => 'book://library/list',
            'name' => 'Book Library',
            'description' => 'List of all available books in the library',
            'mimeType' => 'application/json',
        ];
        
        // 2. 当前书籍的资源（如果已选择）
        $selectedBook = $session['selectedBook'] ?? $this->autoSelectBook();
        if ($selectedBook) {
            $bookName = pathinfo($selectedBook['file'], PATHINFO_FILENAME);
            
            // 书籍元数据
            $resources[] = [
                'uri' => 'book://current/metadata',
                'name' => "Metadata: {$bookName}",
                'description' => 'Metadata of the currently selected book',
                'mimeType' => 'application/json',
            ];
            
            // 书籍目录（如果是 EPUB）
            $ext = strtolower(pathinfo($selectedBook['file'], PATHINFO_EXTENSION));
            if ($ext === 'epub') {
                $resources[] = [
                    'uri' => 'book://current/toc',
                    'name' => "Table of Contents: {$bookName}",
                    'description' => 'Table of contents of the currently selected book',
                    'mimeType' => 'application/json',
                ];
            }
        }
        
        return ['resources' => $resources];
    }
    
    /**
     * 处理 resources/read 请求
     */
    private function handleResourcesRead(array $params, ?string $sessionId): array
    {
        $uri = $params['uri'] ?? '';
        
        if (empty($uri)) {
            throw new \Exception('Missing uri parameter');
        }
        
        $session = $sessionId ? ($this->sessions[$sessionId] ?? null) : null;
        
        // 解析 URI
        if ($uri === 'book://library/list') {
            return $this->resourceBookList();
        }
        
        if ($uri === 'book://current/metadata') {
            return $this->resourceCurrentMetadata($session);
        }
        
        if ($uri === 'book://current/toc') {
            return $this->resourceCurrentToc($session);
        }
        
        throw new \Exception("Resource not found: {$uri}");
    }
    
    /**
     * 资源：书籍列表
     */
    private function resourceBookList(): array
    {
        $books = [];
        
        if (is_dir($this->booksDir)) {
            foreach (scandir($this->booksDir) as $file) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (!in_array($ext, ['epub', 'txt'])) continue;
                
                $baseName = pathinfo($file, PATHINFO_FILENAME);
                $indexFile = $this->booksDir . '/' . $baseName . '_index.json';
                
                $title = $baseName;
                if ($ext === 'epub') {
                    try {
                        $metadata = \SmartBook\Parser\EpubParser::extractMetadata($this->booksDir . '/' . $file);
                        $title = $metadata['title'] ?? $baseName;
                    } catch (\Exception $e) {}
                }
                
                $books[] = [
                    'file' => $file,
                    'title' => $title,
                    'format' => strtoupper($ext),
                    'hasIndex' => file_exists($indexFile),
                ];
            }
        }
        
        return [
            'contents' => [
                [
                    'uri' => 'book://library/list',
                    'mimeType' => 'application/json',
                    'text' => json_encode(['books' => $books], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }
    
    /**
     * 资源：当前书籍元数据
     */
    private function resourceCurrentMetadata(?array $session): array
    {
        $selectedBook = $session['selectedBook'] ?? $this->autoSelectBook();
        
        if (!$selectedBook) {
            throw new \Exception('No book selected');
        }
        
        $metadata = [
            'file' => $selectedBook['file'],
            'hasIndex' => file_exists($selectedBook['cache']),
        ];
        
        $ext = strtolower(pathinfo($selectedBook['file'], PATHINFO_EXTENSION));
        if ($ext === 'epub') {
            try {
                $epubMeta = \SmartBook\Parser\EpubParser::extractMetadata($selectedBook['path']);
                $metadata['title'] = $epubMeta['title'] ?? pathinfo($selectedBook['file'], PATHINFO_FILENAME);
                $metadata['authors'] = $epubMeta['authors'] ?? '';
                $metadata['language'] = $epubMeta['language'] ?? '';
                $metadata['publisher'] = $epubMeta['publisher'] ?? '';
                $metadata['description'] = $epubMeta['description'] ?? '';
            } catch (\Exception $e) {
                $metadata['title'] = pathinfo($selectedBook['file'], PATHINFO_FILENAME);
            }
        } else {
            $metadata['title'] = pathinfo($selectedBook['file'], PATHINFO_FILENAME);
        }
        
        return [
            'contents' => [
                [
                    'uri' => 'book://current/metadata',
                    'mimeType' => 'application/json',
                    'text' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }
    
    /**
     * 资源：当前书籍目录
     */
    private function resourceCurrentToc(?array $session): array
    {
        $selectedBook = $session['selectedBook'] ?? $this->autoSelectBook();
        
        if (!$selectedBook) {
            throw new \Exception('No book selected');
        }
        
        $ext = strtolower(pathinfo($selectedBook['file'], PATHINFO_EXTENSION));
        if ($ext !== 'epub') {
            throw new \Exception('Table of contents only available for EPUB books');
        }
        
        // 尝试从索引文件获取章节信息
        $toc = [];
        if (file_exists($selectedBook['cache'])) {
            $indexData = json_decode(file_get_contents($selectedBook['cache']), true);
            if (isset($indexData['metadata']['chapters'])) {
                $toc = $indexData['metadata']['chapters'];
            } elseif (isset($indexData['chunks'])) {
                // 从 chunks 中提取章节信息
                $seenChapters = [];
                foreach ($indexData['chunks'] as $chunk) {
                    $chapter = $chunk['chapter'] ?? 'Unknown';
                    if (!in_array($chapter, $seenChapters)) {
                        $seenChapters[] = $chapter;
                        $toc[] = ['title' => $chapter];
                    }
                }
            }
        }
        
        return [
            'contents' => [
                [
                    'uri' => 'book://current/toc',
                    'mimeType' => 'application/json',
                    'text' => json_encode([
                        'book' => $selectedBook['file'],
                        'toc' => $toc,
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }
    
    /**
     * 获取服务器健康状态
     */
    private function getHealthStatus(): array
    {
        $books = [];
        $indexedBooks = 0;
        
        if (is_dir($this->booksDir)) {
            foreach (scandir($this->booksDir) as $file) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, ['epub', 'txt'])) {
                    $baseName = pathinfo($file, PATHINFO_FILENAME);
                    $hasIndex = file_exists($this->booksDir . '/' . $baseName . '_index.json');
                    if ($hasIndex) {
                        $indexedBooks++;
                    }
                    $books[] = $file;
                }
            }
        }
        
        return [
            'status' => 'healthy',
            'server' => self::SERVER_INFO,
            'protocol' => self::PROTOCOL_VERSION,
            'timestamp' => date('Y-m-d H:i:s'),
            'uptime' => $this->getUptime(),
            'stats' => [
                'activeSessions' => count($this->sessions),
                'totalBooks' => count($books),
                'indexedBooks' => $indexedBooks,
            ],
            'sessions' => array_map(function ($session, $id) {
                return [
                    'id' => substr($id, 0, 8) . '...',
                    'client' => $session['clientInfo']['name'] ?? 'unknown',
                    'selectedBook' => $session['selectedBook']['file'] ?? null,
                    'lastAccess' => date('Y-m-d H:i:s', $session['lastAccessAt'] ?? 0),
                    'restored' => $session['restored'] ?? false,
                ];
            }, $this->sessions, array_keys($this->sessions)),
        ];
    }
    
    /**
     * 获取服务器运行时间
     */
    private function getUptime(): string
    {
        static $startTime = null;
        if ($startTime === null) {
            $startTime = time();
        }
        
        $uptime = time() - $startTime;
        $hours = floor($uptime / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        $seconds = $uptime % 60;
        
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }
    
    /**
     * 发送 JSON 响应
     */
    private function sendJsonResponse(TcpConnection $connection, mixed $data, int $statusCode = 200, ?string $sessionId = null): void
    {
        $headers = array_merge(self::CORS_HEADERS, [
            'Content-Type' => 'application/json',
        ]);
        
        if ($sessionId) {
            $headers['Mcp-Session-Id'] = $sessionId;
        }
        
        // 打印响应日志
        $this->log('RESPONSE', "MCP JSON-RPC Response (HTTP {$statusCode})", [
            'sessionId' => $sessionId,
            'data' => $data,
        ]);
        
        $connection->send(new Response(
            $statusCode,
            $headers,
            json_encode($data, JSON_UNESCAPED_UNICODE)
        ));
    }
    
    /**
     * 发送 JSON-RPC 错误响应
     */
    private function sendJsonRpcError(TcpConnection $connection, mixed $id, int $code, string $message): void
    {
        $this->sendJsonResponse($connection, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], 200);
    }
    
}
