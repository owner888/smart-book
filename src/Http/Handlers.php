<?php
/**
 * HTTP/WebSocket 请求处理函数（入口文件）
 * 
 * 已模块化的函数已移至对应的 Handler 类：
 * - ConfigHandler: 配置和模型管理
 * - ChatHandler: 聊天功能
 * - BookHandler: 书籍管理
 * - TTSHandler: 语音合成
 * - ASRHandler: 语音识别
 * - StreamHelper: SSE 工具
 * 
 * 本文件保留：主入口、WebSocket、Context Cache、MCP 等未模块化功能
 */

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use SmartBook\Http\Context;
use SmartBook\Http\RequestLogger;
use SmartBook\Http\Router;
use SmartBook\Cache\CacheService;
use SmartBook\AI\GeminiContextCache;
use SmartBook\AI\EnhancedStoryWriter;
use SmartBook\AI\TokenCounter;
use SmartBook\AI\AIService;
use SmartBook\RAG\EmbeddingClient;
use SmartBook\RAG\VectorStore;
use SmartBook\Http\Handlers\StreamHelper;
use SmartBook\Http\Handlers\ConfigHandler;

// 加载路由定义
require_once __DIR__ . '/routes.php';

// ===================================
// HTTP 主入口
// ===================================

function handleHttpRequest(TcpConnection $connection, Request $request): void
{
    $startTime = RequestLogger::start($request);
    
    $path = $request->path();
    $method = $request->method();
    
    $jsonHeaders = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type',
    ];
    
    if ($method === 'OPTIONS') {
        $connection->send(new Response(200, $jsonHeaders, ''));
        RequestLogger::end($request, 200, $startTime, $connection);
        return;
    }
    
    try {
        // CORS headers for all responses
        $corsHeaders = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        ];
        
        if ($path === '/favicon.ico') {
            $icoPath = dirname(__DIR__, 2) . '/static/favicon.ico';
            if (file_exists($icoPath)) {
                $connection->send(new Response(200, array_merge([
                    'Content-Type' => 'image/x-icon', 
                    'Cache-Control' => 'public, max-age=86400'
                ], $corsHeaders), file_get_contents($icoPath)));
                RequestLogger::end($request, 200, $startTime, $connection);
            } else {
                $connection->send(new Response(204, $corsHeaders, ''));
                RequestLogger::end($request, 204, $startTime, $connection);
            }
            return;
        }
        
        if ($path === '/' || $path === '/index.html') {
            $indexHtmlPath = dirname(__DIR__, 2) . '/index.html';
            if (file_exists($indexHtmlPath)) {
                $connection->send(new Response(200, array_merge(['Content-Type' => 'text/html; charset=utf-8'], $corsHeaders), file_get_contents($indexHtmlPath)));
                RequestLogger::end($request, 200, $startTime, $connection);
                return;
            }
        }
        
        if (str_starts_with($path, '/pages/')) {
            $pagePath = dirname(__DIR__, 2) . $path;
            if (file_exists($pagePath)) {
                $connection->send(new Response(200, array_merge(['Content-Type' => 'text/html; charset=utf-8'], $corsHeaders), file_get_contents($pagePath)));
                RequestLogger::end($request, 200, $startTime, $connection);
                return;
            }
        }
        
        if (str_starts_with($path, '/static/')) {
            $filePath = dirname(__DIR__, 2) . $path;
            if (file_exists($filePath)) {
                $ext = pathinfo($filePath, PATHINFO_EXTENSION);
                $mimeTypes = [
                    'css' => 'text/css', 
                    'js' => 'application/javascript', 
                    'map' => 'application/json',
                    'png' => 'image/png', 
                    'jpg' => 'image/jpeg', 
                    'svg' => 'image/svg+xml',
                    'woff2' => 'font/woff2',
                    'woff' => 'font/woff',
                    'ttf' => 'font/ttf',
                    'eot' => 'application/vnd.ms-fontobject',
                ];
                $connection->send(new Response(200, array_merge(['Content-Type' => $mimeTypes[$ext] ?? 'application/octet-stream'], $corsHeaders), file_get_contents($filePath)));
                RequestLogger::end($request, 200, $startTime, $connection);
                return;
            }
        }
        
        // Chrome DevTools Protocol
        if ($path === '/.well-known/appspecific/com.chrome.devtools.json') {
            $connection->send(new Response(200, array_merge(['Content-Type' => 'application/json'], $corsHeaders), json_encode([
                'webSocketDebuggerUrl' => 'ws://' . WS_SERVER_HOST . ':' . WS_SERVER_PORT
            ])));
            RequestLogger::end($request, 200, $startTime, $connection);
            return;
        }
        
        $result = Router::dispatch($connection, $request);
        
        if ($result === null) {
            RequestLogger::end($request, 200, $startTime, $connection);
            return;
        }
        
        $statusCode = isset($result['error']) ? 404 : 200;
        $connection->send(new Response($statusCode, $jsonHeaders, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
        RequestLogger::end($request, $statusCode, $startTime, $connection);
        
    } catch (Exception $e) {
        $connection->send(new Response(500, $jsonHeaders, json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE)));
        RequestLogger::end($request, 500, $startTime, $connection);
    }
}

// ===================================
// WebSocket 处理
// ===================================

function handleWebSocketMessage(TcpConnection $connection, string $data): void
{
    $request = json_decode($data, true);
    if (!$request) {
        $connection->send(json_encode(['error' => 'Invalid JSON']));
        return;
    }
    
    $action = $request['action'] ?? '';
    
    try {
        match ($action) {
            'ask' => streamAsk($connection, $request),
            'chat' => streamChat($connection, $request),
            'continue' => streamContinue($connection, $request),
            default => $connection->send(json_encode(['error' => 'Unknown action']))
        };
    } catch (Exception $e) {
        $connection->send(json_encode(['error' => $e->getMessage()]));
    }
}

// ===================================
// WebSocket 流式处理函数
// ===================================

function streamAsk(TcpConnection $connection, array $request): void
{
    $question = $request['question'] ?? '';
    $topK = $request['top_k'] ?? 8;
    if (empty($question)) { $connection->send(json_encode(['error' => 'Missing question'])); return; }
    
    $currentCache = ConfigHandler::getCurrentBookCache();
    if (!$currentCache) { $connection->send(json_encode(['error' => 'No book index available'])); return; }
    
    $embedder = new EmbeddingClient(GEMINI_API_KEY);
    $queryEmbedding = $embedder->embedQuery($question);
    
    $vectorStore = new VectorStore($currentCache);
    $results = $vectorStore->hybridSearch($question, $queryEmbedding, $topK, 0.6);
    
    $connection->send(json_encode(['type' => 'sources', 'sources' => array_map(fn($r) => ['text' => mb_substr($r['chunk']['text'], 0, 200) . '...', 'score' => round($r['score'] * 100, 1)], $results)]));
    
    $chunkLabel = $GLOBALS['config']['prompts']['chunk_label'] ?? '【片段 {index}】';
    $context = "";
    foreach ($results as $i => $result) {
        $label = str_replace('{index}', $i + 1, $chunkLabel);
        $context .= "{$label}\n" . $result['chunk']['text'] . "\n\n";
    }
    
    $ragSimplePrompt = $GLOBALS['config']['prompts']['rag_simple']['system'] ?? '你是一个书籍分析助手。根据以下内容回答问题，使用中文：

{context}';
    $systemPrompt = str_replace('{context}', $context, $ragSimplePrompt);
    
    $gemini = AIService::getGemini();
    $gemini->chatStream(
        [['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => $question]],
        function ($text, $chunk, $isThought) use ($connection) { if (!$isThought && $text) $connection->send(json_encode(['type' => 'content', 'content' => $text])); },
        ['enableSearch' => false]
    );
    $connection->send(json_encode(['type' => 'done']));
}

function streamChat(TcpConnection $connection, array $request): void
{
    $messages = $request['messages'] ?? [];
    if (empty($messages)) { $connection->send(json_encode(['error' => 'Missing messages'])); return; }
    
    $gemini = AIService::getGemini();
    $gemini->chatStream(
        $messages,
        function ($text, $chunk, $isThought) use ($connection) { if (!$isThought && $text) $connection->send(json_encode(['type' => 'content', 'content' => $text])); },
        ['enableSearch' => false]
    );
    $connection->send(json_encode(['type' => 'done']));
}

function streamContinue(TcpConnection $connection, array $request): void
{
    $prompt = $request['prompt'] ?? '';
    $systemPrompt = $GLOBALS['config']['prompts']['continue']['system'] ?? '';
    $userPrompt = $prompt ?: ($GLOBALS['config']['prompts']['continue']['default_prompt'] ?? '');
    
    $gemini = AIService::getGemini();
    $gemini->chatStream(
        [['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => $userPrompt]],
        function ($text, $chunk, $isThought) use ($connection) { if (!$isThought && $text) $connection->send(json_encode(['type' => 'content', 'content' => $text])); },
        ['enableSearch' => false]
    );
    $connection->send(json_encode(['type' => 'done']));
}

// ===================================
// Cache Stats
// ===================================

function handleCacheStats(Context $ctx): ?array
{
    $connection = $ctx->connection();
    $jsonHeaders = ['Content-Type' => 'application/json; charset=utf-8', 'Access-Control-Allow-Origin' => '*'];
    CacheService::getStats(fn($stats) => $connection->send(new Response(200, $jsonHeaders, json_encode($stats))));
    return null;
}

// ===================================
// Gemini Context Cache 管理
// ===================================

function handleContextCacheList(): array
{
    try {
        $cache = new GeminiContextCache(GEMINI_API_KEY);
        return $cache->listCaches();
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function handleContextCacheCreate(Context $ctx): array
{
    $body = $ctx->jsonBody() ?? [];
    $content = $body['content'] ?? '';
    $displayName = $body['display_name'] ?? null;
    $systemInstruction = $body['system_instruction'] ?? null;
    $ttl = intval($body['ttl'] ?? GeminiContextCache::DEFAULT_TTL);
    $model = $body['model'] ?? 'gemini-2.5-flash';
    
    if (empty($content)) {
        return ['success' => false, 'error' => 'Missing content'];
    }
    
    try {
        $cache = new GeminiContextCache(GEMINI_API_KEY, $model);
        
        if (!$cache->meetsMinTokens($content)) {
            $estimatedTokens = GeminiContextCache::estimateTokens($content);
            $minRequired = GeminiContextCache::MIN_TOKENS[$model] ?? 1024;
            return [
                'success' => false, 
                'error' => "内容太短，估算 {$estimatedTokens} tokens，最低要求 {$minRequired} tokens"
            ];
        }
        
        return $cache->create($content, $displayName, $systemInstruction, $ttl);
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function handleContextCacheCreateForBook(Context $ctx): array
{
    $body = $ctx->jsonBody() ?? [];
    $bookFile = $body['book'] ?? '';
    $ttl = intval($body['ttl'] ?? GeminiContextCache::DEFAULT_TTL);
    $model = $body['model'] ?? 'gemini-2.5-flash';
    
    if (empty($bookFile)) {
        return ['success' => false, 'error' => 'Missing book parameter'];
    }
    
    $booksDir = dirname(__DIR__, 2) . '/books';
    $bookPath = $booksDir . '/' . $bookFile;
    
    if (!file_exists($bookPath)) {
        return ['success' => false, 'error' => 'Book not found: ' . $bookFile];
    }
    
    try {
        $ext = strtolower(pathinfo($bookFile, PATHINFO_EXTENSION));
        if ($ext === 'epub') {
            $content = \SmartBook\Parser\EpubParser::extractText($bookPath);
        } else {
            $content = file_get_contents($bookPath);
        }
        
        if (empty($content)) {
            return ['success' => false, 'error' => 'Failed to extract book content'];
        }
        
        $cache = new GeminiContextCache(GEMINI_API_KEY, $model);
        
        if (!$cache->meetsMinTokens($content)) {
            $estimatedTokens = GeminiContextCache::estimateTokens($content);
            $minRequired = GeminiContextCache::MIN_TOKENS[$model] ?? 1024;
            return [
                'success' => false, 
                'error' => "书籍内容太短，估算 {$estimatedTokens} tokens，最低要求 {$minRequired} tokens"
            ];
        }
        
        $result = $cache->createForBook($bookFile, $content, $ttl);
        
        if ($result['success']) {
            $result['book'] = $bookFile;
            $result['contentLength'] = mb_strlen($content);
            $result['estimatedTokens'] = GeminiContextCache::estimateTokens($content);
        }
        
        return $result;
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function handleContextCacheDelete(Context $ctx): array
{
    $body = $ctx->jsonBody() ?? [];
    $cacheName = $body['name'] ?? '';
    
    if (empty($cacheName)) {
        return ['success' => false, 'error' => 'Missing cache name'];
    }
    
    try {
        $cache = new GeminiContextCache(GEMINI_API_KEY);
        return $cache->delete($cacheName);
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function handleContextCacheGet(Context $ctx): array
{
    $body = $ctx->jsonBody() ?? [];
    $cacheName = $body['name'] ?? '';
    
    if (empty($cacheName)) {
        return ['success' => false, 'error' => 'Missing cache name'];
    }
    
    try {
        $cache = new GeminiContextCache(GEMINI_API_KEY);
        $result = $cache->get($cacheName);
        
        if ($result) {
            return ['success' => true, 'cache' => $result];
        }
        
        return ['success' => false, 'error' => 'Cache not found'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ===================================
// 增强版续写
// ===================================

function handleEnhancedWriterPrepare(Context $ctx): array
{
    $body = $ctx->jsonBody() ?? [];
    $bookFile = $body['book'] ?? '';
    $model = $body['model'] ?? 'gemini-2.5-flash';
    
    if (empty($bookFile)) {
        return ['success' => false, 'error' => 'Missing book parameter'];
    }
    
    $booksDir = dirname(__DIR__, 2) . '/books';
    $bookPath = $booksDir . '/' . $bookFile;
    
    if (!file_exists($bookPath)) {
        return ['success' => false, 'error' => 'Book not found: ' . $bookFile];
    }
    
    try {
        $ext = strtolower(pathinfo($bookFile, PATHINFO_EXTENSION));
        if ($ext === 'epub') {
            $content = \SmartBook\Parser\EpubParser::extractText($bookPath);
        } else {
            $content = file_get_contents($bookPath);
        }
        
        if (empty($content)) {
            return ['success' => false, 'error' => 'Failed to extract book content'];
        }
        
        $writer = new EnhancedStoryWriter(GEMINI_API_KEY, $model);
        return $writer->prepareForBook($bookFile, $content);
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function handleEnhancedWriterStatus(Context $ctx): array
{
    $body = $ctx->jsonBody() ?? [];
    $bookFile = $body['book'] ?? '';
    
    if (empty($bookFile)) {
        return ['success' => false, 'error' => 'Missing book parameter'];
    }
    
    try {
        $writer = new EnhancedStoryWriter(GEMINI_API_KEY);
        return $writer->getWriterStatus($bookFile);
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function handleStreamEnhancedContinue(Context $ctx): ?array
{
    $connection = $ctx->connection();
    $body = $ctx->jsonBody() ?? [];
    $bookFile = $body['book'] ?? '';
    $prompt = $body['prompt'] ?? '';
    $customInstructions = $body['custom_instructions'] ?? '';
    $requestedModel = $body['model'] ?? 'gemini-2.5-flash';
    
    if (empty($bookFile)) {
        return ['error' => 'Missing book parameter'];
    }
    
    if (empty($prompt)) {
        return ['error' => 'Missing prompt'];
    }
    
    $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
    $connection->send(new Response(200, $headers, ''));
    
    try {
        $cacheClient = new GeminiContextCache(GEMINI_API_KEY, $requestedModel);
        $bookCache = $cacheClient->getBookCache($bookFile);
        
        if (!$bookCache) {
            StreamHelper::sendSSE($connection, 'sources', json_encode([
                ['text' => "正在为《{$bookFile}》创建 Context Cache，请稍候...", 'score' => 0]
            ], JSON_UNESCAPED_UNICODE));
            
            $booksDir = dirname(__DIR__, 2) . '/books';
            $bookPath = $booksDir . '/' . $bookFile;
            
            if (!file_exists($bookPath)) {
                StreamHelper::sendSSE($connection, 'error', "书籍文件不存在: {$bookFile}");
                $connection->close();
                return null;
            }
            
            $ext = strtolower(pathinfo($bookFile, PATHINFO_EXTENSION));
            if ($ext === 'epub') {
                $content = \SmartBook\Parser\EpubParser::extractText($bookPath);
            } else {
                $content = file_get_contents($bookPath);
            }
            
            if (empty($content)) {
                StreamHelper::sendSSE($connection, 'error', "无法提取书籍内容");
                $connection->close();
                return null;
            }
            
            $createResult = $cacheClient->createForBook($bookFile, $content, 7200);
            
            if (!$createResult['success']) {
                StreamHelper::sendSSE($connection, 'error', "创建缓存失败: " . ($createResult['error'] ?? '未知错误'));
                $connection->close();
                return null;
            }
            
            $bookCache = $cacheClient->getBookCache($bookFile);
            
            if (!$bookCache) {
                StreamHelper::sendSSE($connection, 'error', "创建缓存后仍无法获取");
                $connection->close();
                return null;
            }
            
            StreamHelper::sendSSE($connection, 'sources', json_encode([
                ['text' => "✅ Context Cache 创建成功！", 'score' => 100]
            ], JSON_UNESCAPED_UNICODE));
        }
        
        $cacheModel = str_replace('models/', '', $bookCache['model'] ?? '');
        if ($cacheModel !== $requestedModel) {
            $errorMsg = "⚠️ 模型不匹配！\n\n" .
                "• 当前选择: {$requestedModel}\n" .
                "• 缓存要求: {$cacheModel}\n\n" .
                "请切换到 {$cacheModel} 模型后重试。";
            StreamHelper::sendSSE($connection, 'error', $errorMsg);
            $connection->close();
            return null;
        }
        
        $model = $cacheModel;
        
        $writer = new EnhancedStoryWriter(GEMINI_API_KEY, $model);
        
        $tokenCount = $bookCache['usageMetadata']['totalTokenCount'] ?? 0;
        StreamHelper::sendSSE($connection, 'sources', json_encode([
            ['text' => "Context Cache（{$tokenCount} tokens）+ Few-shot（{$model}）", 'score' => 100]
        ], JSON_UNESCAPED_UNICODE));
        
        $isConnectionAlive = true;
        $writer->continueStory(
            $bookFile,
            $prompt,
            function ($text, $isThought) use ($connection, &$isConnectionAlive) {
                if (!$isConnectionAlive) return;
                if ($text && !$isThought) {
                    if (!StreamHelper::sendSSE($connection, 'content', $text)) {
                        $isConnectionAlive = false;
                    }
                }
            },
            function ($fullContent, $usageMetadata = null, $usedModel = null) use ($connection, $model, &$isConnectionAlive) {
                if (!$isConnectionAlive) return;
                if ($usageMetadata) {
                    $costInfo = TokenCounter::calculateCost($usageMetadata, $usedModel ?? $model);
                    StreamHelper::sendSSE($connection, 'usage', json_encode([
                        'tokens' => $costInfo['tokens'],
                        'cost' => $costInfo['cost'],
                        'cost_formatted' => TokenCounter::formatCost($costInfo['cost']),
                        'currency' => $costInfo['currency'],
                        'model' => $usedModel ?? $model
                    ], JSON_UNESCAPED_UNICODE));
                }
                StreamHelper::sendSSE($connection, 'done', '');
                $connection->close();
            },
            function ($error) use ($connection, &$isConnectionAlive) {
                if (!$isConnectionAlive) return;
                StreamHelper::sendSSE($connection, 'error', $error);
                $connection->close();
            },
            [
                'custom_instructions' => $customInstructions,
                'token_count' => $tokenCount,
            ]
        );
        
    } catch (Exception $e) {
        StreamHelper::sendSSE($connection, 'error', $e->getMessage());
        $connection->close();
    }
    
    return null;
}

function handleStreamAnalyzeCharacters(Context $ctx): ?array
{
    $connection = $ctx->connection();
    $body = $ctx->jsonBody() ?? [];
    $bookFile = $body['book'] ?? '';
    $model = $body['model'] ?? 'gemini-2.5-flash';
    
    if (empty($bookFile)) {
        return ['error' => 'Missing book parameter'];
    }
    
    $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
    $connection->send(new Response(200, $headers, ''));
    
    try {
        $writer = new EnhancedStoryWriter(GEMINI_API_KEY, $model);
        
        StreamHelper::sendSSE($connection, 'sources', json_encode([
            ['text' => '使用 Context Cache 分析人物', 'score' => 100]
        ], JSON_UNESCAPED_UNICODE));
        
        $writer->analyzeCharacters(
            $bookFile,
            function ($text, $isThought) use ($connection) {
                if ($text && !$isThought) {
                    StreamHelper::sendSSE($connection, 'content', $text);
                }
            },
            function ($fullContent, $usageMetadata = null, $usedModel = null) use ($connection, $model) {
                if ($usageMetadata) {
                    $costInfo = TokenCounter::calculateCost($usageMetadata, $usedModel ?? $model);
                    StreamHelper::sendSSE($connection, 'usage', json_encode([
                        'tokens' => $costInfo['tokens'],
                        'cost' => $costInfo['cost'],
                        'cost_formatted' => TokenCounter::formatCost($costInfo['cost']),
                        'currency' => $costInfo['currency'],
                        'model' => $usedModel ?? $model
                    ], JSON_UNESCAPED_UNICODE));
                }
                StreamHelper::sendSSE($connection, 'done', '');
                $connection->close();
            },
            function ($error) use ($connection) {
                StreamHelper::sendSSE($connection, 'error', $error);
                $connection->close();
            }
        );
        
    } catch (Exception $e) {
        StreamHelper::sendSSE($connection, 'error', $e->getMessage());
        $connection->close();
    }
    
    return null;
}

// ===================================
// MCP Servers 管理
// ===================================

function handleGetMCPServers(): array
{
    $configPath = dirname(__DIR__, 2) . '/config/mcp.json';
    
    if (!file_exists($configPath)) {
        return ['servers' => []];
    }
    
    $config = json_decode(file_get_contents($configPath), true) ?? [];
    $servers = [];
    
    foreach ($config['mcpServers'] ?? [] as $name => $serverConfig) {
        $servers[] = [
            'name' => $name,
            'description' => $serverConfig['description'] ?? '',
            'type' => 'stdio',
            'command' => $serverConfig['command'] ?? '',
            'args' => $serverConfig['args'] ?? [],
            'env' => $serverConfig['env'] ?? [],
            'enabled' => !($serverConfig['disabled'] ?? false),
            'tools' => array_keys($serverConfig['tools'] ?? []),
        ];
    }
    
    return ['servers' => $servers];
}

function handleSaveMCPServers(Request $request): array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $servers = $body['servers'] ?? [];
    
    $configPath = dirname(__DIR__, 2) . '/config/mcp.json';
    
    $config = [];
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true) ?? [];
    }
    
    $mcpServers = [];
    foreach ($servers as $server) {
        $name = $server['name'] ?? 'unnamed';
        $mcpServers[$name] = [
            'command' => $server['command'] ?? '',
            'args' => $server['args'] ?? [],
            'disabled' => !($server['enabled'] ?? true),
        ];
        
        if (!empty($server['description'])) {
            $mcpServers[$name]['description'] = $server['description'];
        }
        if (!empty($server['env'])) {
            $mcpServers[$name]['env'] = $server['env'];
        }
    }
    
    $config['mcpServers'] = $mcpServers;
    
    $result = file_put_contents(
        $configPath, 
        json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
    
    if ($result === false) {
        return ['success' => false, 'error' => 'Failed to save config'];
    }
    
    return ['success' => true, 'message' => 'MCP servers saved'];
}

// ===================================
// MCP Server 请求处理
// ===================================

$mcpServer = null;

function getMCPServer(): \SmartBook\MCP\StreamableHttpServer
{
    global $mcpServer;
    
    if ($mcpServer === null) {
        $booksDir = dirname(__DIR__, 2) . '/books';
        $mcpServer = new \SmartBook\MCP\StreamableHttpServer($booksDir, debug: true);
    }
    
    return $mcpServer;
}

function handleMCPRequest(TcpConnection $connection, Request $request): void
{
    $server = getMCPServer();
    $server->handleRequest($connection, $request);
}

function parseHttpRequest(string $data, TcpConnection $connection): ?Request
{
    $inputLength = \Workerman\Protocols\Http::input($data, $connection);
    
    if ($inputLength === 0) {
        return null;
    }
    
    if ($inputLength < 0) {
        $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n");
        return null;
    }
    
    try {
        $request = \Workerman\Protocols\Http::decode($data, $connection);
        return $request;
    } catch (\Exception $e) {
        $connection->close("HTTP/1.1 400 Bad Request\r\n\r\nParse error: " . $e->getMessage());
        return null;
    }
}
