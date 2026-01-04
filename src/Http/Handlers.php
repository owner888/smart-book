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
        if ($path === '/' || $path === '/chat' || $path === '/chat.html') {
            $chatHtmlPath = dirname(__DIR__, 2) . '/chat.html';
            if (file_exists($chatHtmlPath)) {
                $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], file_get_contents($chatHtmlPath)));
                return;
            }
        }
        
        // 静态文件
        if (str_starts_with($path, '/static/')) {
            $filePath = dirname(__DIR__, 2) . $path;
            if (file_exists($filePath)) {
                $ext = pathinfo($filePath, PATHINFO_EXTENSION);
                $mimeTypes = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml'];
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
    $connection->send("event: {$event}\ndata: {$data}\n\n");
}

function handleStreamAskAsync(TcpConnection $connection, Request $request): ?array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $question = $body['question'] ?? '';
    $topK = $body['top_k'] ?? 8;
    
    if (empty($question)) return ['error' => 'Missing question'];
    
    $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
    
    CacheService::getSemanticIndex(function($semanticIndex) use ($connection, $question, $topK, $headers) {
        $connection->send(new Response(200, $headers, ''));
        
        $embedder = new EmbeddingClient(GEMINI_API_KEY);
        $queryEmbedding = $embedder->embedQuery($question);
        
        $similar = CacheService::findSimilarCache($queryEmbedding, $semanticIndex, 0.96);
        
        if ($similar) {
            CacheService::get($similar['key'], function($cached) use ($connection, $similar, $question, $queryEmbedding, $topK) {
                if ($cached) {
                    sendSSE($connection, 'sources', json_encode($cached['sources'], JSON_UNESCAPED_UNICODE));
                    sendSSE($connection, 'cached', json_encode(['hit' => true, 'similarity' => round($similar['score'] * 100, 1)]));
                    sendSSE($connection, 'content', $cached['answer']);
                    sendSSE($connection, 'done', '');
                    $connection->close();
                    return;
                }
                handleStreamAskGenerate($connection, $question, $queryEmbedding, $topK);
            });
            return;
        }
        
        handleStreamAskGenerate($connection, $question, $queryEmbedding, $topK);
    });
    
    return null;
}

function handleStreamAskGenerate(TcpConnection $connection, string $question, array $queryEmbedding, int $topK): void
{
    $prompts = $GLOBALS['config']['prompts'];
    $libraryPrompts = $prompts['library'];
    
    $bookInfo = $libraryPrompts['book_intro'] . str_replace(['{which}', '{title}', '{authors}'], ['', '《西游记》', '吴承恩'], $libraryPrompts['book_template']) . $libraryPrompts['separator'];
    $systemPrompt = $bookInfo . $libraryPrompts['markdown_instruction'] . $libraryPrompts['unknown_single'] . ' ' . str_replace('{language}', $prompts['language']['default'], $prompts['language']['instruction']);
    
    $gemini = AIService::getGemini();
    $response = $gemini->chat([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $question],
    ], ['enableSearch' => false]);
    
    $aiAnswer = '';
    foreach ($response['candidates'] ?? [] as $candidate) {
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (!($part['thought'] ?? false)) $aiAnswer .= $part['text'] ?? '';
        }
    }
    
    $isUnknown = false;
    $lowerAnswer = mb_strtolower($aiAnswer);
    foreach ($prompts['unknown_patterns'] as $pattern) {
        if (strpos($lowerAnswer, mb_strtolower($pattern)) !== false) { $isUnknown = true; break; }
    }
    
    if (!$isUnknown) {
        sendSSE($connection, 'sources', json_encode([['text' => 'AI 预训练知识', 'score' => 100]], JSON_UNESCAPED_UNICODE));
        sendSSE($connection, 'content', $aiAnswer);
        sendSSE($connection, 'done', '');
        $connection->close();
        return;
    }
    
    $vectorStore = new VectorStore(DEFAULT_BOOK_CACHE);
    $results = $vectorStore->hybridSearch($question, $queryEmbedding, $topK, 0.6);
    
    $sources = array_map(fn($r) => ['text' => mb_substr($r['chunk']['text'], 0, 200) . '...', 'score' => round($r['score'] * 100, 1)], $results);
    sendSSE($connection, 'sources', json_encode($sources, JSON_UNESCAPED_UNICODE));
    
    $context = "";
    foreach ($results as $i => $result) {
        $context .= "【片段 " . ($i + 1) . "】\n" . $result['chunk']['text'] . "\n\n";
    }
    
    $asyncGemini = AIService::getAsyncGemini();
    $asyncGemini->chatStreamAsync(
        [['role' => 'system', 'content' => "你是一个书籍分析助手。根据以下从书中检索到的内容回答问题，使用中文：\n\n{$context}"], ['role' => 'user', 'content' => $question]],
        function ($text, $isThought) use ($connection) { if (!$isThought && $text) sendSSE($connection, 'content', $text); },
        function ($fullAnswer) use ($connection, $question, $queryEmbedding, $topK, $sources) {
            $cacheKey = CacheService::makeKey('stream_ask', $question . ':' . $topK);
            CacheService::set($cacheKey, ['sources' => $sources, 'answer' => $fullAnswer]);
            CacheService::addToSemanticIndex($cacheKey, $queryEmbedding, $question);
            sendSSE($connection, 'done', '');
            $connection->close();
        },
        function ($error) use ($connection) { sendSSE($connection, 'error', $error); $connection->close(); },
        ['enableSearch' => false]
    );
}

function handleStreamChat(TcpConnection $connection, Request $request): ?array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $messages = $body['messages'] ?? [];
    if (empty($messages)) return ['error' => 'Missing messages'];
    
    $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
    $connection->send(new Response(200, $headers, ''));
    
    $asyncGemini = AIService::getAsyncGemini();
    $asyncGemini->chatStreamAsync(
        $messages,
        function ($text, $isThought) use ($connection) { if (!$isThought && $text) sendSSE($connection, 'content', $text); },
        function ($fullContent) use ($connection) { sendSSE($connection, 'done', ''); $connection->close(); },
        function ($error) use ($connection) { sendSSE($connection, 'error', $error); $connection->close(); },
        ['enableSearch' => false]
    );
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
