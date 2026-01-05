<?php
/**
 * HTTP/WebSocket 请求处理函数
 */

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use SmartBook\AI\AIService;
use SmartBook\AI\TokenCounter;
use SmartBook\Cache\CacheService;
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
            '/api/models' => handleGetModels(),
            '/api/assistants' => handleGetAssistants(),
            '/api/cache/stats' => handleCacheStats($connection),
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

/**
 * 获取可用模型列表（从 Gemini API 动态获取）
 */
function handleGetModels(): array
{
    static $cache = null;
    static $cacheTime = 0;
    
    // 缓存 5 分钟
    if ($cache && (time() - $cacheTime) < 300) {
        return $cache;
    }
    
    $models = [];
    $default = 'gemini-2.5-flash';
    
    // 调用 Gemini Models API
    try {
        $apiKey = GEMINI_API_KEY;
        $url = "https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}";
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => "Content-Type: application/json\r\n"
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response) {
            $data = json_decode($response, true);
            
            // 定价表（USD per million tokens）
            $pricing = [
                'gemini-2.5-pro' => ['input' => 2.5, 'output' => 15],
                'gemini-2.5-flash' => ['input' => 0.3, 'output' => 2.5],
                'gemini-2.5-flash-lite' => ['input' => 0.1, 'output' => 0.4],
                'gemini-2.0-flash' => ['input' => 0, 'output' => 0],  // 免费
                'gemini-1.5-pro' => ['input' => 3.5, 'output' => 10.5],
                'gemini-1.5-flash' => ['input' => 0.075, 'output' => 0.3],
            ];
            
            foreach ($data['models'] ?? [] as $model) {
                $modelId = str_replace('models/', '', $model['name']);
                
                // 只显示 gemini 模型，排除 embedding/imagen/text-bison 等
                if (!str_starts_with($modelId, 'gemini')) continue;
                
                // 排除 preview/exp 版本
                if (str_contains($modelId, 'preview') || str_contains($modelId, 'exp')) continue;
                
                // 计算相对价格比率 (相对于 gemini-2.5-pro)
                $basePrice = $pricing['gemini-2.5-pro']['output'];
                $modelPrice = $pricing[$modelId]['output'] ?? 2.5;
                $rate = $modelPrice == 0 ? '0x' : round($modelPrice / $basePrice, 2) . 'x';
                
                $models[] = [
                    'id' => $modelId,
                    'name' => $model['displayName'] ?? $modelId,
                    'provider' => 'google',
                    'rate' => $rate,
                    'description' => $model['description'] ?? '',
                    'context_length' => $model['inputTokenLimit'] ?? 0,
                    'output_limit' => $model['outputTokenLimit'] ?? 0,
                    'default' => $modelId === $default,
                ];
            }
            
            // 按名称排序
            usort($models, fn($a, $b) => strcmp($a['name'], $b['name']));
        }
    } catch (Exception $e) {
        // API 调用失败，使用默认列表
    }
    
    // 如果 API 没返回数据，使用默认配置
    if (empty($models)) {
        $models = [
            ['id' => 'gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash', 'provider' => 'google', 'rate' => '0.33x', 'default' => true],
            ['id' => 'gemini-2.5-pro', 'name' => 'Gemini 2.5 Pro', 'provider' => 'google', 'rate' => '1x'],
        ];
    }
    
    $cache = ['models' => $models, 'default' => $default, 'source' => 'gemini_api'];
    $cacheTime = time();
    
    return $cache;
}

/**
 * 获取所有助手配置（包含系统提示词）
 */
function handleGetAssistants(): array
{
    $prompts = $GLOBALS['config']['prompts'];
    $libraryPrompts = $prompts['library'];
    
    // 从 EPUB 文件读取书籍元数据
    $bookTitle = '未知书籍';
    $bookAuthors = '未知作者';
    
    if (defined('DEFAULT_BOOK_PATH') && file_exists(DEFAULT_BOOK_PATH)) {
        $metadata = \SmartBook\Parser\EpubParser::extractMetadata(DEFAULT_BOOK_PATH);
        if (!empty($metadata['title'])) {
            $bookTitle = '《' . $metadata['title'] . '》';
        }
        if (!empty($metadata['authors'])) {
            $bookAuthors = $metadata['authors'];
        }
    }
    
    // 构建书籍助手的系统提示词（完全对齐 Python 的拼接顺序）
    // 1. book_intro + book_template + separator
    // 2. markdown_instruction
    // 3. unknown_single (单本书) 或 unknown_multiple (多本书)
    // 4. language_instruction
    $bookSystemPrompt = $libraryPrompts['book_intro'] 
        . str_replace(['{which}', '{title}', '{authors}'], ['', $bookTitle, $bookAuthors], $libraryPrompts['book_template']) 
        . $libraryPrompts['separator']
        . $libraryPrompts['markdown_instruction']
        . ($libraryPrompts['unknown_single'] ?? ' If the specified book is unknown to you instead of answering the following questions just say the book is unknown.')
        . ' ' . str_replace('{language}', $prompts['language']['default'], $prompts['language']['instruction']);
    
    return [
        'book' => [
            'name' => '书籍问答助手',
            'avatar' => '📚',
            'color' => '#4caf50',
            'description' => "我是书籍问答助手，可以帮你分析{$bookTitle}的内容。你可以问我关于书中人物、情节、主题等问题。",
            'systemPrompt' => $bookSystemPrompt,
            'action' => 'ask',
        ],
        'continue' => [
            'name' => '续写小说',
            'avatar' => '✍️',
            'color' => '#ff9800',
            'description' => '我是小说续写助手，擅长模仿《西游记》的章回体风格续写故事。告诉我你想要的情节设定，我会为你创作新章节。',
            'systemPrompt' => $prompts['continue']['system'] ?? '',
            'action' => 'continue',
        ],
        'chat' => [
            'name' => '通用聊天',
            'avatar' => '💬',
            'color' => '#2196f3',
            'description' => '我是通用聊天助手，可以和你讨论任何话题。',
            'systemPrompt' => $prompts['chat']['system'] ?? '',
            'action' => 'chat',
        ],
        'default' => [
            'name' => 'Default Assistant',
            'avatar' => '⭐',
            'color' => '#9c27b0',
            'description' => '我是默认助手，有什么可以帮你的吗？',
            'systemPrompt' => '你是一个通用 AI 助手，请友善地帮助用户。',
            'action' => 'chat',
        ],
    ];
}

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


// ===================================
// 上下文压缩（自动摘要）
// ===================================

/**
 * 检查并触发上下文摘要
 */
function triggerSummarizationIfNeeded(string $chatId, array $context): void
{
    CacheService::needsSummarization($chatId, function($needsSummary) use ($chatId, $context) {
        if (!$needsSummary) return;
        
        // 获取完整历史用于生成摘要
        CacheService::getChatHistory($chatId, function($history) use ($chatId, $context) {
            if (empty($history)) return;
            
            // 构建摘要请求
            $conversationText = "";
            if ($context['summary']) {
                $conversationText .= "【之前的摘要】\n" . $context['summary']['text'] . "\n\n【新对话】\n";
            }
            foreach ($history as $msg) {
                $role = $msg['role'] === 'user' ? '用户' : 'AI';
                $conversationText .= "{$role}: {$msg['content']}\n\n";
            }
            
            $summarizePrompt = CacheService::getSummarizePrompt();
            
            // 异步调用 AI 生成摘要
            $asyncGemini = AIService::getAsyncGemini();
            $asyncGemini->chatStreamAsync(
                [
                    ['role' => 'user', 'content' => $conversationText . "\n\n" . $summarizePrompt]
                ],
                function ($text, $isThought) { /* 忽略流式输出 */ },
                function ($summaryText) use ($chatId) {
                    // 保存摘要并压缩历史
                    if (!empty($summaryText)) {
                        CacheService::saveSummaryAndCompress($chatId, $summaryText);
                        echo "📝 对话 {$chatId} 已自动摘要\n";
                    }
                },
                function ($error) use ($chatId) {
                    echo "❌ 摘要生成失败 ({$chatId}): {$error}\n";
                },
                ['enableSearch' => false]
            );
        });
    });
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
    $chatId = $body['chat_id'] ?? '';
    $enableSearch = $body['search'] ?? true;
    $engine = $body['engine'] ?? 'google';
    $ragEnabled = $body['rag'] ?? true;
    $keywordWeight = floatval($body['keyword_weight'] ?? 0.5);
    $model = $body['model'] ?? 'gemini-2.5-flash';
    
    if (empty($question)) return ['error' => 'Missing question'];
    
    $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
    
    CacheService::getChatContext($chatId, function($context) use ($connection, $question, $chatId, $headers, $enableSearch, $engine, $ragEnabled, $keywordWeight, $model) {
        $connection->send(new Response(200, $headers, ''));
        
        $prompts = $GLOBALS['config']['prompts'];
        $libraryPrompts = $prompts['library'];
        $ragPrompts = $prompts['rag'];
        
        $bookTitle = '未知书籍';
        $bookAuthors = '未知作者';
        
        if (defined('DEFAULT_BOOK_PATH') && file_exists(DEFAULT_BOOK_PATH)) {
            $metadata = \SmartBook\Parser\EpubParser::extractMetadata(DEFAULT_BOOK_PATH);
            if (!empty($metadata['title'])) $bookTitle = '《' . $metadata['title'] . '》';
            if (!empty($metadata['authors'])) $bookAuthors = $metadata['authors'];
        }
        
        // 构建提示词并调用 AI 的函数
        $doChat = function($ragContext, $ragSources) use (
            $connection, $question, $chatId, $enableSearch, $engine, $ragEnabled, $model,
            $context, $bookTitle, $bookAuthors, $prompts, $libraryPrompts, $ragPrompts
        ) {
            if ($ragEnabled && !empty($ragContext)) {
                $bookInfo = str_replace('{title}', $bookTitle, $ragPrompts['book_intro'] ?? 'I am discussing the book: {title}');
                if (!empty($bookAuthors)) {
                    $bookInfo .= str_replace('{authors}', $bookAuthors, $ragPrompts['author_template'] ?? ' by {authors}');
                }
                $systemPrompt = str_replace(['{book_info}', '{context}'], [$bookInfo, $ragContext], $ragPrompts['system'] ?? 'You are a book analysis assistant. {book_info}\n\nContext:\n{context}');
                sendSSE($connection, 'sources', json_encode($ragSources, JSON_UNESCAPED_UNICODE));
            } else {
                $bookInfo = $libraryPrompts['book_intro'] . str_replace(['{which}', '{title}', '{authors}'], ['', $bookTitle, $bookAuthors], $libraryPrompts['book_template']) . $libraryPrompts['separator'];
                $systemPrompt = $bookInfo . $libraryPrompts['markdown_instruction'] . ($libraryPrompts['unknown_single'] ?? '') . ' ' . str_replace('{language}', $prompts['language']['default'], $prompts['language']['instruction']);
                $sourceTexts = ['google' => 'AI 预训练知识 + Google Search', 'mcp' => 'AI 预训练知识 + MCP 工具', 'off' => 'AI 预训练知识（搜索已关闭）'];
                sendSSE($connection, 'sources', json_encode([['text' => $sourceTexts[$engine] ?? $sourceTexts['off'], 'score' => 100]], JSON_UNESCAPED_UNICODE));
            }
            
            if ($context['summary']) {
                $systemPrompt .= "\n\n【对话历史摘要】\n" . $context['summary']['text'];
                sendSSE($connection, 'summary_used', json_encode(['rounds_summarized' => $context['summary']['rounds_summarized'], 'recent_messages' => count($context['messages']) / 2], JSON_UNESCAPED_UNICODE));
            }
            
            $messages = [['role' => 'system', 'content' => $systemPrompt]];
            foreach ($context['messages'] as $msg) {
                if (isset($msg['role']) && isset($msg['content'])) $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
            $messages[] = ['role' => 'user', 'content' => $question];
            
            if ($chatId) CacheService::addToChatHistory($chatId, ['role' => 'user', 'content' => $question]);
            
            $asyncGemini = AIService::getAsyncGemini($model);
            $asyncGemini->chatStreamAsync(
                $messages,
                function ($text, $isThought) use ($connection) { if ($text) sendSSE($connection, $isThought ? 'thinking' : 'content', $text); },
                function ($fullAnswer, $usageMetadata = null, $usedModel = null) use ($connection, $chatId, $context, $model) {
                    if ($chatId) {
                        CacheService::addToChatHistory($chatId, ['role' => 'assistant', 'content' => $fullAnswer]);
                        triggerSummarizationIfNeeded($chatId, $context);
                    }
                    if ($usageMetadata) {
                        $costInfo = TokenCounter::calculateCost($usageMetadata, $usedModel ?? $model);
                        sendSSE($connection, 'usage', json_encode(['tokens' => $costInfo['tokens'], 'cost' => $costInfo['cost'], 'cost_formatted' => TokenCounter::formatCost($costInfo['cost']), 'currency' => $costInfo['currency'], 'model' => $usedModel ?? $model], JSON_UNESCAPED_UNICODE));
                    }
                    sendSSE($connection, 'done', '');
                    $connection->close();
                },
                function ($error) use ($connection) { sendSSE($connection, 'error', $error); $connection->close(); },
                ['enableSearch' => $enableSearch && $engine === 'google', 'enableTools' => $engine === 'mcp']
            );
        };
        
        // RAG 搜索逻辑：使用文件存储
        if ($ragEnabled && defined('DEFAULT_BOOK_CACHE') && file_exists(DEFAULT_BOOK_CACHE)) {
            try {
                $embedder = new EmbeddingClient(GEMINI_API_KEY);
                $queryEmbedding = $embedder->embedQuery($question);
                
                $ragContext = '';
                $ragSources = [];
                $chunkTemplate = $ragPrompts['chunk_template'] ?? "【Passage {index}】\n{text}\n";
                
                $vectorStore = new VectorStore(DEFAULT_BOOK_CACHE);
                $results = $vectorStore->hybridSearch($question, $queryEmbedding, 5, $keywordWeight);
                
                foreach ($results as $i => $result) {
                    $ragContext .= str_replace(['{index}', '{text}'], [$i + 1, $result['chunk']['text']], $chunkTemplate);
                    $ragContext .= "(Relevance: " . round($result['score'] * 100, 1) . "%)\n\n";
                    $ragSources[] = ['text' => mb_substr($result['chunk']['text'], 0, 200) . '...', 'score' => round($result['score'] * 100, 1)];
                }
                $doChat($ragContext, $ragSources);
            } catch (Exception $e) {
                $doChat('', []);
            }
        } else {
            $doChat('', []);
        }
    });
    
    return null;
}

function handleStreamChat(TcpConnection $connection, Request $request): ?array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $message = $body['message'] ?? '';
    $chatId = $body['chat_id'] ?? '';
    $enableSearch = $body['search'] ?? true;  // 默认开启搜索
    $engine = $body['engine'] ?? 'google';    // 默认使用 Google
    $model = $body['model'] ?? 'gemini-2.5-flash';  // 模型选择
    
    if (empty($message)) return ['error' => 'Missing message'];
    
    $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
    
    // 获取对话上下文（包含摘要 + 最近消息）
    CacheService::getChatContext($chatId, function($context) use ($connection, $message, $chatId, $headers, $enableSearch, $engine) {
        $connection->send(new Response(200, $headers, ''));
        
        $prompts = $GLOBALS['config']['prompts'];
        
        // 通用聊天系统提示词
        $systemPrompt = $prompts['chat']['system'] ?? '你是一个友善、博学的 AI 助手，擅长回答各种问题并提供有价值的见解。请用中文回答。';
        
        // 发送系统提示词给前端显示
        sendSSE($connection, 'system_prompt', $systemPrompt);
        
        // 构建消息数组
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        
        // 如果有摘要，添加为系统消息，并通知前端
        if ($context['summary']) {
            $messages[0]['content'] .= "\n\n【对话历史摘要】\n" . $context['summary']['text'];
            sendSSE($connection, 'summary_used', json_encode([
                'rounds_summarized' => $context['summary']['rounds_summarized'],
                'recent_messages' => count($context['messages']) / 2
            ], JSON_UNESCAPED_UNICODE));
        }
        
        // 添加最近消息
        foreach ($context['messages'] as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $message];
        
        // 保存用户消息
        if ($chatId) {
            CacheService::addToChatHistory($chatId, ['role' => 'user', 'content' => $message]);
        }
        
        $asyncGemini = AIService::getAsyncGemini();
        $asyncGemini->chatStreamAsync(
            $messages,
            function ($text, $isThought) use ($connection) { 
                if ($text) sendSSE($connection, $isThought ? 'thinking' : 'content', $text); 
            },
            function ($fullContent) use ($connection, $chatId, $context) { 
                // 保存助手回复
                if ($chatId) {
                    CacheService::addToChatHistory($chatId, ['role' => 'assistant', 'content' => $fullContent]);
                    // 检查是否需要进行上下文压缩
                    triggerSummarizationIfNeeded($chatId, $context);
                }
                sendSSE($connection, 'done', ''); 
                $connection->close(); 
            },
            function ($error) use ($connection) { 
                sendSSE($connection, 'error', $error); 
                $connection->close(); 
            },
            ['enableSearch' => $enableSearch && $engine === 'google', 'enableTools' => $engine === 'mcp']
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
