<?php
/**
 * HTTP/WebSocket 请求处理函数
 */

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use SmartBook\AI\AIService;
use SmartBook\Cache\CacheService;
use SmartBook\Cache\RedisVectorStore;
use SmartBook\RAG\EmbeddingClient;
use SmartBook\RAG\VectorStore;

// ===================================
// HTTP 主入口
// ===================================

function handleHttpRequest(TcpConnection $connection, Request $request): void
{
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
        return;
    }
    
    try {
        // 首页
        if ($path === '/' || $path === '/index.html') {
            $indexHtmlPath = dirname(__DIR__, 2) . '/index.html';
            if (file_exists($indexHtmlPath)) {
                $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], file_get_contents($indexHtmlPath)));
                return;
            }
        }
        
        // pages 目录下的页面
        if (str_starts_with($path, '/pages/')) {
            $pagePath = dirname(__DIR__, 2) . $path;
            if (file_exists($pagePath)) {
                $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], file_get_contents($pagePath)));
                return;
            }
        }
        
        // 旧路由兼容 - 聊天页面
        if ($path === '/chat' || $path === '/chat.html') {
            $chatHtmlPath = dirname(__DIR__, 2) . '/pages/chat.html';
            if (file_exists($chatHtmlPath)) {
                $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], file_get_contents($chatHtmlPath)));
                return;
            }
        }
        
        // 旧路由兼容 - 设置页面
        if ($path === '/settings' || $path === '/settings.html') {
            $settingsHtmlPath = dirname(__DIR__, 2) . '/pages/settings.html';
            if (file_exists($settingsHtmlPath)) {
                $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], file_get_contents($settingsHtmlPath)));
                return;
            }
        }
        
        // 静态文件
        if (str_starts_with($path, '/static/')) {
            $filePath = dirname(__DIR__, 2) . $path;
            if (file_exists($filePath)) {
                $ext = pathinfo($filePath, PATHINFO_EXTENSION);
                $mimeTypes = [
                    'css' => 'text/css', 
                    'js' => 'application/javascript', 
                    'png' => 'image/png', 
                    'jpg' => 'image/jpeg', 
                    'svg' => 'image/svg+xml',
                    'woff2' => 'font/woff2',
                    'woff' => 'font/woff',
                    'ttf' => 'font/ttf',
                    'eot' => 'application/vnd.ms-fontobject',
                ];
                $connection->send(new Response(200, ['Content-Type' => $mimeTypes[$ext] ?? 'application/octet-stream'], file_get_contents($filePath)));
                return;
            }
        }
        
        // API 路由
        $result = match ($path) {
            '/api' => ['status' => 'ok', 'message' => 'Smart Book AI API'],
            '/api/health' => ['status' => 'ok', 'timestamp' => date('Y-m-d H:i:s'), 'redis' => CacheService::isConnected()],
            '/api/cache/stats' => handleCacheStats($connection),
            '/api/vectors/stats' => handleVectorStats($connection),
            '/api/vectors/import' => handleVectorImport($connection),
            '/api/ask' => handleAskWithCache($connection, $request),
            '/api/chat' => handleChat($request),
            '/api/continue' => handleContinue($request),
            '/api/stream/ask' => handleStreamAskAsync($connection, $request),
            '/api/stream/chat' => handleStreamChat($connection, $request),
            '/api/stream/continue' => handleStreamContinue($connection, $request),
            default => ['error' => 'Not Found', 'path' => $path],
        };
        
        if ($result === null) return;
        
        $statusCode = isset($result['error']) ? 404 : 200;
        $connection->send(new Response($statusCode, $jsonHeaders, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
        
    } catch (Exception $e) {
        $connection->send(new Response(500, $jsonHeaders, json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE)));
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
// API 处理函数
// ===================================

function handleChat(Request $request): array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $messages = $body['messages'] ?? [];
    if (empty($messages)) return ['error' => 'Missing messages'];
    return AIService::chat($messages);
}

function handleContinue(Request $request): array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    return AIService::continueStory($body['prompt'] ?? '');
}

function handleAskWithCache(TcpConnection $connection, Request $request): ?array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $question = $body['question'] ?? '';
    $topK = $body['top_k'] ?? 8;
    
    if (empty($question)) return ['error' => 'Missing question'];
    
    $cacheKey = CacheService::makeKey('ask', $question . ':' . $topK);
    $jsonHeaders = ['Content-Type' => 'application/json; charset=utf-8', 'Access-Control-Allow-Origin' => '*'];
    
    CacheService::get($cacheKey, function($cached) use ($connection, $question, $topK, $cacheKey, $jsonHeaders) {
        if ($cached) {
            $cached['cached'] = true;
            $connection->send(new Response(200, $jsonHeaders, json_encode($cached, JSON_UNESCAPED_UNICODE)));
            return;
        }
        $result = AIService::askBook($question, $topK);
        $result['cached'] = false;
        CacheService::set($cacheKey, $result);
        $connection->send(new Response(200, $jsonHeaders, json_encode($result, JSON_UNESCAPED_UNICODE)));
    });
    
    return null;
}

function handleCacheStats(TcpConnection $connection): ?array
{
    $jsonHeaders = ['Content-Type' => 'application/json; charset=utf-8', 'Access-Control-Allow-Origin' => '*'];
    CacheService::getStats(fn($stats) => $connection->send(new Response(200, $jsonHeaders, json_encode($stats))));
    return null;
}

function handleVectorStats(TcpConnection $connection): ?array
{
    $jsonHeaders = ['Content-Type' => 'application/json; charset=utf-8', 'Access-Control-Allow-Origin' => '*'];
    RedisVectorStore::getStats(fn($stats) => $connection->send(new Response(200, $jsonHeaders, json_encode($stats))));
    return null;
}

function handleVectorImport(TcpConnection $connection): ?array
{
    $jsonHeaders = ['Content-Type' => 'application/json; charset=utf-8', 'Access-Control-Allow-Origin' => '*'];
    
    if (!file_exists(DEFAULT_BOOK_CACHE)) {
        $connection->send(new Response(404, $jsonHeaders, json_encode(['error' => 'Index not found'])));
        return null;
    }
    
    try {
        $data = json_decode(file_get_contents(DEFAULT_BOOK_CACHE), true);
        $total = count($data['chunks'] ?? []);
        RedisVectorStore::importFromJson(DEFAULT_BOOK_CACHE);
        $connection->send(new Response(200, $jsonHeaders, json_encode(['success' => true, 'total' => $total])));
    } catch (Exception $e) {
        $connection->send(new Response(500, $jsonHeaders, json_encode(['error' => $e->getMessage()])));
    }
    return null;
}

// ===================================
// SSE 流式处理
// ===================================

function sendSSE(TcpConnection $connection, string $event, string $data): void
{
    // SSE 规范：data 字段中的换行符需要分成多行 data:
    // 或者直接将换行符替换为 \n 字符串（前端会处理）
    // 这里使用分行方式
    $lines = explode("\n", $data);
    $message = "event: {$event}\n";
    foreach ($lines as $line) {
        $message .= "data: {$line}\n";
    }
    $message .= "\n";
    $connection->send($message);
}

function handleStreamAskAsync(TcpConnection $connection, Request $request): ?array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $question = $body['question'] ?? '';
    $chatId = $body['chat_id'] ?? '';  // Chat ID
    
    if (empty($question)) return ['error' => 'Missing question'];
    
    $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
    
    // 从 Redis 获取对话历史
    CacheService::getChatHistory($chatId, function($history) use ($connection, $question, $chatId, $headers) {
        $connection->send(new Response(200, $headers, ''));
        
        $prompts = $GLOBALS['config']['prompts'];
        $libraryPrompts = $prompts['library'];
        
        // 构建书籍上下文提示词
        $bookInfo = $libraryPrompts['book_intro'] . str_replace(['{which}', '{title}', '{authors}'], ['', '《西游记》', '吴承恩'], $libraryPrompts['book_template']) . $libraryPrompts['separator'];
        $systemPrompt = $bookInfo . $libraryPrompts['markdown_instruction'] . ' ' . str_replace('{language}', $prompts['language']['default'], $prompts['language']['instruction']);
        
        // 发送来源信息
        sendSSE($connection, 'sources', json_encode([['text' => 'AI 预训练知识 + Google Search', 'score' => 100]], JSON_UNESCAPED_UNICODE));
        
        // 构建消息数组：系统提示 + 历史消息 + 当前问题
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $question];
        
        // 保存用户消息到历史
        if ($chatId) {
            CacheService::addToChatHistory($chatId, ['role' => 'user', 'content' => $question]);
        }
        
        // 使用异步流式调用，启用 Google Search
        $asyncGemini = AIService::getAsyncGemini();
        $asyncGemini->chatStreamAsync(
            $messages,
            function ($text, $isThought) use ($connection) { 
                if (!$isThought && $text) sendSSE($connection, 'content', $text); 
            },
            function ($fullAnswer) use ($connection, $chatId) {
                // 保存助手回复到历史
                if ($chatId) {
                    CacheService::addToChatHistory($chatId, ['role' => 'assistant', 'content' => $fullAnswer]);
                }
                sendSSE($connection, 'done', '');
                $connection->close();
            },
            function ($error) use ($connection) { 
                sendSSE($connection, 'error', $error); 
                $connection->close(); 
            },
            ['enableSearch' => true]
        );
    });
    
    return null;
}

function handleStreamChat(TcpConnection $connection, Request $request): ?array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $message = $body['message'] ?? '';
    $chatId = $body['chat_id'] ?? '';
    
    if (empty($message)) return ['error' => 'Missing message'];
    
    $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
    
    // 从 Redis 获取对话历史
    CacheService::getChatHistory($chatId, function($history) use ($connection, $message, $chatId, $headers) {
        $connection->send(new Response(200, $headers, ''));
        
        // 构建消息数组
        $messages = [];
        foreach ($history as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $message];
        
        // 保存用户消息到历史
        if ($chatId) {
            CacheService::addToChatHistory($chatId, ['role' => 'user', 'content' => $message]);
        }
        
        $asyncGemini = AIService::getAsyncGemini();
        $asyncGemini->chatStreamAsync(
            $messages,
            function ($text, $isThought) use ($connection) { 
                if (!$isThought && $text) sendSSE($connection, 'content', $text); 
            },
            function ($fullContent) use ($connection, $chatId) { 
                // 保存助手回复到历史
                if ($chatId) {
                    CacheService::addToChatHistory($chatId, ['role' => 'assistant', 'content' => $fullContent]);
                }
                sendSSE($connection, 'done', ''); 
                $connection->close(); 
            },
            function ($error) use ($connection) { 
                sendSSE($connection, 'error', $error); 
                $connection->close(); 
            },
            ['enableSearch' => true]
        );
    });
    
    return null;
}

function handleStreamContinue(TcpConnection $connection, Request $request): ?array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $prompt = $body['prompt'] ?? '';
    
    $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
    $connection->send(new Response(200, $headers, ''));
    
    $systemPrompt = $GLOBALS['config']['prompts']['continue']['system'] ?? '';
    $userPrompt = $prompt ?: ($GLOBALS['config']['prompts']['continue']['default_prompt'] ?? '');
    
    $asyncGemini = AIService::getAsyncGemini();
    $asyncGemini->chatStreamAsync(
        [['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => $userPrompt]],
        function ($text, $isThought) use ($connection) { if (!$isThought && $text) sendSSE($connection, 'content', $text); },
        function ($fullContent) use ($connection) { sendSSE($connection, 'done', ''); $connection->close(); },
        function ($error) use ($connection) { sendSSE($connection, 'error', $error); $connection->close(); },
        ['enableSearch' => false]
    );
    return null;
}

// ===================================
// WebSocket 流式处理
// ===================================

function streamAsk(TcpConnection $connection, array $request): void
{
    $question = $request['question'] ?? '';
    $topK = $request['top_k'] ?? 8;
    if (empty($question)) { $connection->send(json_encode(['error' => 'Missing question'])); return; }
    
    $embedder = new EmbeddingClient(GEMINI_API_KEY);
    $queryEmbedding = $embedder->embedQuery($question);
    
    $vectorStore = new VectorStore(DEFAULT_BOOK_CACHE);
    $results = $vectorStore->hybridSearch($question, $queryEmbedding, $topK, 0.6);
    
    $connection->send(json_encode(['type' => 'sources', 'sources' => array_map(fn($r) => ['text' => mb_substr($r['chunk']['text'], 0, 200) . '...', 'score' => round($r['score'] * 100, 1)], $results)]));
    
    $context = "";
    foreach ($results as $i => $result) $context .= "【片段 " . ($i + 1) . "】\n" . $result['chunk']['text'] . "\n\n";
    
    $gemini = AIService::getGemini();
    $gemini->chatStream(
        [['role' => 'system', 'content' => "你是一个书籍分析助手。根据以下内容回答问题，使用中文：\n\n{$context}"], ['role' => 'user', 'content' => $question]],
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
