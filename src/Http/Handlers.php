<?php
/**
 * HTTP/WebSocket 请求处理函数
 */

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use SmartBook\Http\Context;
use SmartBook\Http\RequestLogger;
use SmartBook\Http\Router;
use SmartBook\AI\AIService;
use SmartBook\AI\TokenCounter;
use SmartBook\AI\GoogleTTSClient;
use SmartBook\AI\GoogleASRClient;
use SmartBook\Cache\CacheService;
use SmartBook\RAG\EmbeddingClient;
use SmartBook\RAG\VectorStore;
use SmartBook\AI\GeminiContextCache;
use SmartBook\AI\EnhancedStoryWriter;

// 加载路由定义
require_once __DIR__ . '/routes.php';

// ===================================
// HTTP 主入口
// ===================================

function handleHttpRequest(TcpConnection $connection, Request $request): void
{
    // 记录请求开始时间
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
        // favicon.ico
        if ($path === '/favicon.ico') {
            $icoPath = dirname(__DIR__, 2) . '/static/favicon.ico';
            if (file_exists($icoPath)) {
                $connection->send(new Response(200, [
                    'Content-Type' => 'image/x-icon', 
                    'Cache-Control' => 'public, max-age=86400'
                ], file_get_contents($icoPath)));
                RequestLogger::end($request, 200, $startTime, $connection);
            } else {
                $connection->send(new Response(204, [], ''));
                RequestLogger::end($request, 204, $startTime, $connection);
            }
            return;
        }
        
        // 首页
        if ($path === '/' || $path === '/index.html') {
            $indexHtmlPath = dirname(__DIR__, 2) . '/index.html';
            if (file_exists($indexHtmlPath)) {
                $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], file_get_contents($indexHtmlPath)));
                RequestLogger::end($request, 200, $startTime, $connection);
                return;
            }
        }
        
        // pages 目录下的页面
        if (str_starts_with($path, '/pages/')) {
            $pagePath = dirname(__DIR__, 2) . $path;
            if (file_exists($pagePath)) {
                $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], file_get_contents($pagePath)));
                RequestLogger::end($request, 200, $startTime, $connection);
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
                RequestLogger::end($request, 200, $startTime, $connection);
                return;
            }
        }
        
        // API 路由（使用新路由系统）
        $result = Router::dispatch($connection, $request);
        
        // 流式 API 返回 null，记录日志后直接返回
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
// API 处理函数
// ===================================

/**
 * 获取服务器配置信息
 */
function handleGetConfig(): array
{
    return [
        'webServer' => [
            'url' => 'http://' . WEB_SERVER_HOST . ':' . WEB_SERVER_PORT,
        ],
        'mcpServer' => [
            'url' => 'http://' . MCP_SERVER_HOST . ':' . MCP_SERVER_PORT . '/mcp',
        ],
        'wsServer' => [
            'url' => 'ws://' . WS_SERVER_HOST . ':' . WS_SERVER_PORT,
        ],
    ];
}

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
 * 获取当前选中的书籍路径
 */
function getCurrentBookPath(): ?string
{
    // 优先使用运行时选择的书籍
    if (isset($GLOBALS['selected_book']['path']) && file_exists($GLOBALS['selected_book']['path'])) {
        return $GLOBALS['selected_book']['path'];
    }
    // 回退到默认配置
    if (defined('DEFAULT_BOOK_PATH') && file_exists(DEFAULT_BOOK_PATH)) {
        return DEFAULT_BOOK_PATH;
    }
    return null;
}

/**
 * 获取当前选中的书籍索引路径
 */
function getCurrentBookCache(): ?string
{
    // 优先使用运行时选择的书籍
    if (isset($GLOBALS['selected_book']['cache']) && file_exists($GLOBALS['selected_book']['cache'])) {
        return $GLOBALS['selected_book']['cache'];
    }
    // 回退到默认配置
    if (defined('DEFAULT_BOOK_CACHE') && file_exists(DEFAULT_BOOK_CACHE)) {
        return DEFAULT_BOOK_CACHE;
    }
    return null;
}

/**
 * 获取所有助手配置（包含系统提示词）
 */
function handleGetAssistants(): array
{
    $prompts = $GLOBALS['config']['prompts'];
    $libraryPrompts = $prompts['library'];
    
    // 从当前选中的书籍读取元数据
    $bookTitle = $prompts['defaults']['unknown_book'] ?? '未知书籍';
    $bookAuthors = $prompts['defaults']['unknown_author'] ?? '未知作者';
    
    $currentBookPath = getCurrentBookPath();
    if ($currentBookPath) {
        $ext = strtolower(pathinfo($currentBookPath, PATHINFO_EXTENSION));
        if ($ext === 'epub') {
            $metadata = \SmartBook\Parser\EpubParser::extractMetadata($currentBookPath);
            if (!empty($metadata['title'])) {
                $bookTitle = '《' . $metadata['title'] . '》';
            }
            if (!empty($metadata['authors'])) {
                $bookAuthors = $metadata['authors'];
            }
        } else {
            // TXT 文件使用文件名作为标题
            $bookTitle = '《' . pathinfo($currentBookPath, PATHINFO_FILENAME) . '》';
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
    
    // 构建书籍助手描述（使用模板替换书名）
    $bookDescription = str_replace('{title}', $bookTitle, $prompts['book']['description'] ?? '我是书籍问答助手，可以帮你分析{title}的内容。你可以问我关于书中人物、情节、主题等问题。');
    
    return [
        'book' => [
            'name' => '书籍问答助手',
            'avatar' => '📚',
            'color' => '#4caf50',
            'description' => $bookDescription,
            'systemPrompt' => $bookSystemPrompt,
            'action' => 'ask',
        ],
        'continue' => [
            'name' => '续写小说',
            'avatar' => '✍️',
            'color' => '#ff9800',
            'description' => str_replace('{title}', $bookTitle, $prompts['continue']['description'] ?? '我是小说续写助手，可以帮你续写{title}的内容。告诉我你想要的情节设定，我会为你创作新章节。'),
            'systemPrompt' => str_replace('{title}', $bookTitle, $prompts['continue']['system'] ?? ''),
            'action' => 'continue',
        ],
        'chat' => [
            'name' => '通用聊天',
            'avatar' => '💬',
            'color' => '#2196f3',
            'description' => $prompts['chat']['description'] ?? '我是通用聊天助手，可以和你讨论任何话题。',
            'systemPrompt' => $prompts['chat']['system'] ?? '',
            'action' => 'chat',
        ],
        'default' => [
            'name' => 'Default Assistant',
            'avatar' => '⭐',
            'color' => '#9c27b0',
            'description' => $prompts['default']['description'] ?? '我是默认助手，有什么可以帮你的吗？',
            'systemPrompt' => $prompts['default']['system'] ?? '你是一个通用 AI 助手，请友善地帮助用户。',
            'action' => 'chat',
        ],
    ];
}

function handleChat(Context $ctx): array
{
    $body = $ctx->jsonBody() ?? [];
    $messages = $body['messages'] ?? [];
    if (empty($messages)) return ['error' => 'Missing messages'];
    return AIService::chat($messages);
}

function handleContinue(Context $ctx): array
{
    $body = $ctx->jsonBody() ?? [];
    return AIService::continueStory($body['prompt'] ?? '');
}

function handleAskWithCache(Context $ctx): ?array
{
    $connection = $ctx->connection();
    $body = $ctx->jsonBody() ?? [];
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

function handleCacheStats(Context $ctx): ?array
{
    $connection = $ctx->connection();
    $jsonHeaders = ['Content-Type' => 'application/json; charset=utf-8', 'Access-Control-Allow-Origin' => '*'];
    CacheService::getStats(fn($stats) => $connection->send(new Response(200, $jsonHeaders, json_encode($stats))));
    return null;
}

// ===================================
// 书籍管理
// ===================================

/**
 * 获取所有可用书籍列表
 */
function handleGetBooks(): array
{
    $booksDir = dirname(__DIR__, 2) . '/books';
    $books = [];
    $currentBook = null;
    
    // 获取当前选中的书籍
    $currentBookPath = getCurrentBookPath();
    if ($currentBookPath) {
        $currentBook = basename($currentBookPath);
    }
    
    // 扫描 books 目录
    if (is_dir($booksDir)) {
        $files = scandir($booksDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $filePath = $booksDir . '/' . $file;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            
            // 支持 epub 和 txt 格式
            if (!in_array($ext, ['epub', 'txt'])) continue;
            
            $baseName = pathinfo($file, PATHINFO_FILENAME);
            $indexFile = $booksDir . '/' . $baseName . '_index.json';
            $hasIndex = file_exists($indexFile);
            
            // 获取书籍元数据
            $title = $baseName;
            $author = '';
            $fileSize = filesize($filePath);
            $indexSize = $hasIndex ? filesize($indexFile) : 0;
            $chunkCount = 0;
            
            if ($ext === 'epub') {
                try {
                    $metadata = \SmartBook\Parser\EpubParser::extractMetadata($filePath);
                    $title = $metadata['title'] ?? $baseName;
                    $author = $metadata['authors'] ?? '';
                } catch (Exception $e) {}
            }
            
            // 如果有索引，读取块数量
            if ($hasIndex) {
                try {
                    $indexData = json_decode(file_get_contents($indexFile), true);
                    $chunkCount = count($indexData['chunks'] ?? []);
                } catch (Exception $e) {}
            }
            
            $books[] = [
                'file' => $file,
                'title' => $title,
                'author' => $author,
                'format' => strtoupper($ext),
                'fileSize' => formatFileSize($fileSize),
                'hasIndex' => $hasIndex,
                'indexSize' => $hasIndex ? formatFileSize($indexSize) : null,
                'chunkCount' => $chunkCount,
                'isSelected' => ($file === $currentBook),
            ];
        }
    }
    
    // 按标题排序
    usort($books, fn($a, $b) => strcmp($a['title'], $b['title']));
    
    return [
        'books' => $books,
        'currentBook' => $currentBook,
        'booksDir' => $booksDir,
    ];
}

/**
 * 选择当前书籍
 */
function handleSelectBook(Context $ctx): array
{
    $body = $ctx->jsonBody() ?? [];
    $bookFile = $body['book'] ?? '';
    
    if (empty($bookFile)) {
        return ['error' => 'Missing book parameter'];
    }
    
    $booksDir = dirname(__DIR__, 2) . '/books';
    $bookPath = $booksDir . '/' . $bookFile;
    
    if (!file_exists($bookPath)) {
        return ['error' => 'Book not found: ' . $bookFile];
    }
    
    $baseName = pathinfo($bookFile, PATHINFO_FILENAME);
    $indexPath = $booksDir . '/' . $baseName . '_index.json';
    
    // 更新全局配置（运行时）
    $GLOBALS['selected_book'] = [
        'path' => $bookPath,
        'cache' => $indexPath,
        'hasIndex' => file_exists($indexPath),
    ];
    
    // 返回选择结果
    return [
        'success' => true,
        'book' => $bookFile,
        'path' => $bookPath,
        'hasIndex' => file_exists($indexPath),
        'message' => file_exists($indexPath) 
            ? "已选择书籍: {$baseName}" 
            : "已选择书籍: {$baseName}（需要先创建索引）",
    ];
}

/**
 * 为书籍创建向量索引（SSE 流式返回进度）
 */
function handleIndexBook(Context $ctx): ?array
{
    $connection = $ctx->connection();
    $body = $ctx->jsonBody() ?? [];
    $bookFile = $body['book'] ?? '';
    
    if (empty($bookFile)) {
        return ['error' => 'Missing book parameter'];
    }
    
    $booksDir = dirname(__DIR__, 2) . '/books';
    $bookPath = $booksDir . '/' . $bookFile;
    
    if (!file_exists($bookPath)) {
        return ['error' => 'Book not found: ' . $bookFile];
    }
    
    $baseName = pathinfo($bookFile, PATHINFO_FILENAME);
    $ext = strtolower(pathinfo($bookFile, PATHINFO_EXTENSION));
    $indexPath = $booksDir . '/' . $baseName . '_index.json';
    
    // SSE 响应
    $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
    $connection->send(new Response(200, $headers, ''));
    
    try {
        sendSSE($connection, 'progress', json_encode(['step' => 'start', 'message' => "开始处理: {$baseName}"]));
        
        // 提取文本
        sendSSE($connection, 'progress', json_encode(['step' => 'extract', 'message' => '正在提取文本...']));
        
        if ($ext === 'epub') {
            $text = \SmartBook\Parser\EpubParser::extractText($bookPath);
        } else {
            // TXT 文件直接读取
            $text = file_get_contents($bookPath);
        }
        
        $textLength = mb_strlen($text);
        sendSSE($connection, 'progress', json_encode(['step' => 'extract_done', 'message' => "提取完成: {$textLength} 字符"]));
        
        // 分块
        sendSSE($connection, 'progress', json_encode(['step' => 'chunk', 'message' => '正在分块...']));
        
        $chunker = new \SmartBook\RAG\DocumentChunker(chunkSize: 800, chunkOverlap: 150);
        $chunks = $chunker->chunk($text);
        $chunkCount = count($chunks);
        
        sendSSE($connection, 'progress', json_encode(['step' => 'chunk_done', 'message' => "分块完成: {$chunkCount} 个块"]));
        
        // 生成向量嵌入
        sendSSE($connection, 'progress', json_encode(['step' => 'embed', 'message' => '正在生成向量嵌入...']));
        
        $embedder = new EmbeddingClient(GEMINI_API_KEY);
        $vectorStore = new VectorStore();
        
        $batchSize = 20;
        $totalBatches = ceil($chunkCount / $batchSize);
        
        for ($i = 0; $i < $chunkCount; $i += $batchSize) {
            $batch = array_slice($chunks, $i, $batchSize);
            $embeddings = $embedder->embedBatch(array_column($batch, 'text'));
            $vectorStore->addBatch($batch, $embeddings);
            
            $currentBatch = floor($i / $batchSize) + 1;
            $progress = round(($currentBatch / $totalBatches) * 100);
            sendSSE($connection, 'progress', json_encode([
                'step' => 'embed_batch', 
                'batch' => $currentBatch, 
                'total' => $totalBatches,
                'progress' => $progress,
                'message' => "向量化进度: {$currentBatch}/{$totalBatches} ({$progress}%)"
            ]));
        }
        
        // 保存索引
        sendSSE($connection, 'progress', json_encode(['step' => 'save', 'message' => '正在保存索引...']));
        $vectorStore->save($indexPath);
        
        $indexSize = formatFileSize(filesize($indexPath));
        sendSSE($connection, 'done', json_encode([
            'success' => true,
            'book' => $bookFile,
            'chunkCount' => $chunkCount,
            'indexSize' => $indexSize,
            'message' => "索引创建完成！共 {$chunkCount} 个块，索引大小 {$indexSize}"
        ]));
        
    } catch (Exception $e) {
        sendSSE($connection, 'error', $e->getMessage());
    }
    
    $connection->close();
    return null;
}

/**
 * 格式化文件大小
 */
function formatFileSize(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
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
            $prompts = $GLOBALS['config']['prompts'];
            $summarizeConfig = $prompts['summarize'] ?? [];
            $roleNames = $prompts['role_names'] ?? ['user' => '用户', 'assistant' => 'AI'];
            
            $conversationText = "";
            if ($context['summary']) {
                $prevLabel = $summarizeConfig['previous_summary_label'] ?? '【之前的摘要】';
                $newLabel = $summarizeConfig['new_conversation_label'] ?? '【新对话】';
                $conversationText .= "{$prevLabel}\n" . $context['summary']['text'] . "\n\n{$newLabel}\n";
            }
            foreach ($history as $msg) {
                $role = $roleNames[$msg['role']] ?? ($msg['role'] === 'user' ? '用户' : 'AI');
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
                        Logger::info("对话 {$chatId} 已自动摘要");
                    }
                },
                function ($error) use ($chatId) {
                    Logger::error("摘要生成失败 ({$chatId}): {$error}");
                },
                ['enableSearch' => false]
            );
        });
    });
}

// ===================================
// SSE 流式处理
// ===================================

/**
 * 发送 SSE 事件（带连接检测）
 * 
 * @param TcpConnection $connection Workerman 连接对象
 * @param string $event 事件类型
 * @param string $data 事件数据
 * @return bool 返回 true 表示发送成功，false 表示连接已断开
 */
function sendSSE(TcpConnection $connection, string $event, string $data): bool
{
    // 检查连接状态
    if ($connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
        Logger::info("[SSE] 连接已断开，停止发送事件: {$event}");
        return false;
    }
    
    // 构建 SSE 消息
    $lines = explode("\n", $data);
    $message = "event: {$event}\n";
    foreach ($lines as $line) {
        $message .= "data: {$line}\n";
    }
    $message .= "\n";
    
    // 尝试发送
    try {
        $connection->send($message);
        return true;
    } catch (Exception $e) {
        Logger::error("[SSE] 发送失败: {$e->getMessage()}");
        return false;
    }
}

function handleStreamAskAsync(Context $ctx): ?array
{
    $connection = $ctx->connection();
    $body = $ctx->jsonBody() ?? [];
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
        
        $currentBookPath = getCurrentBookPath();
        if ($currentBookPath) {
            $ext = strtolower(pathinfo($currentBookPath, PATHINFO_EXTENSION));
            if ($ext === 'epub') {
                $metadata = \SmartBook\Parser\EpubParser::extractMetadata($currentBookPath);
                if (!empty($metadata['title'])) $bookTitle = '《' . $metadata['title'] . '》';
                if (!empty($metadata['authors'])) $bookAuthors = $metadata['authors'];
            } else {
                // TXT 文件使用文件名作为标题
                $bookTitle = '《' . pathinfo($currentBookPath, PATHINFO_FILENAME) . '》';
            }
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
                $sourceTexts = $prompts['source_texts'] ?? ['google' => 'AI 预训练知识 + Google Search', 'mcp' => 'AI 预训练知识 + MCP 工具', 'off' => 'AI 预训练知识（搜索已关闭）'];
                sendSSE($connection, 'sources', json_encode([['text' => $sourceTexts[$engine] ?? $sourceTexts['off'], 'score' => 100]], JSON_UNESCAPED_UNICODE));
            }
            
            if ($context['summary']) {
                $historyLabel = $prompts['summarize']['history_label'] ?? '【对话历史摘要】';
                $systemPrompt .= "\n\n{$historyLabel}\n" . $context['summary']['text'];
                sendSSE($connection, 'summary_used', json_encode(['rounds_summarized' => $context['summary']['rounds_summarized'], 'recent_messages' => count($context['messages']) / 2], JSON_UNESCAPED_UNICODE));
            }
            
            $messages = [['role' => 'system', 'content' => $systemPrompt]];
            foreach ($context['messages'] as $msg) {
                if (isset($msg['role']) && isset($msg['content'])) $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
            $messages[] = ['role' => 'user', 'content' => $question];
            
            if ($chatId) CacheService::addToChatHistory($chatId, ['role' => 'user', 'content' => $question]);
            
            $asyncGemini = AIService::getAsyncGemini($model);
            $isConnectionAlive = true;
            $requestId = $asyncGemini->chatStreamAsync(
                $messages,
                function ($text, $isThought) use ($connection, &$isConnectionAlive, &$requestId, $asyncGemini) {
                    if (!$isConnectionAlive) return;
                    if ($text) {
                        if (!sendSSE($connection, $isThought ? 'thinking' : 'content', $text)) {
                            $isConnectionAlive = false;
                            if ($requestId) $asyncGemini->cancel($requestId);
                        }
                    }
                },
                function ($fullAnswer, $usageMetadata = null, $usedModel = null) use ($connection, $chatId, $context, $model, &$isConnectionAlive) {
                    if (!$isConnectionAlive) return;
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
                function ($error) use ($connection, &$isConnectionAlive) {
                    if (!$isConnectionAlive) return;
                    sendSSE($connection, 'error', $error);
                    $connection->close();
                },
                ['enableSearch' => $enableSearch && $engine === 'google', 'enableTools' => $engine === 'mcp']
            );
        };
        
        // RAG 搜索逻辑：使用当前选中的书籍
        $currentCache = getCurrentBookCache();
        if ($ragEnabled && $currentCache) {
            try {
                $embedder = new EmbeddingClient(GEMINI_API_KEY);
                $queryEmbedding = $embedder->embedQuery($question);
                
                $ragContext = '';
                $ragSources = [];
                $chunkTemplate = $ragPrompts['chunk_template'] ?? "【Passage {index}】\n{text}\n";
                
                $vectorStore = new VectorStore($currentCache);
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

function handleStreamChat(Context $ctx): ?array
{
    $connection = $ctx->connection();
    $body = $ctx->jsonBody() ?? [];
    $message = $body['message'] ?? '';
    $chatId = $body['chat_id'] ?? '';
    $enableSearch = $body['search'] ?? true;  // 默认开启搜索
    $engine = $body['engine'] ?? 'google';    // 默认使用 Google
    $model = $body['model'] ?? 'gemini-2.5-flash';  // 模型选择
    
    // 接收 iOS 客户端传递的上下文参数
    $clientSummary = $body['summary'] ?? null; // 之前对话的摘要（已经摘要的部分）
    $clientHistory = $body['history'] ?? null; // 最近的未摘要消息（最近10条）
    
    if (empty($message)) return ['error' => 'Missing message'];
    
    $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
    
    // 如果客户端传递了上下文参数，直接使用；否则从 Redis 获取
    if ($clientSummary !== null || $clientHistory !== null) {
        // iOS 客户端已经处理好上下文，直接使用
        $connection->send(new Response(200, $headers, ''));
        
        $prompts = $GLOBALS['config']['prompts'];
        $sourceTexts = $prompts['source_texts'] ?? ['google' => 'AI 预训练知识 + Google Search', 'mcp' => 'AI 预训练知识 + MCP 工具', 'off' => 'AI 预训练知识（搜索已关闭）'];
        sendSSE($connection, 'sources', json_encode([['text' => $sourceTexts[$engine] ?? $sourceTexts['off'], 'score' => 100]], JSON_UNESCAPED_UNICODE));
        
        $systemPrompt = $prompts['chat']['system'] ?? '你是一个友善、博学的 AI 助手，擅长回答各种问题并提供有价值的见解。请用中文回答。';
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        
        // 添加客户端传递的摘要
        if ($clientSummary) {
            $historyLabel = $prompts['summarize']['history_label'] ?? '【对话历史摘要】';
            $messages[0]['content'] .= "\n\n{$historyLabel}\n" . $clientSummary;
            sendSSE($connection, 'summary_used', json_encode(['source' => 'ios_client', 'has_summary' => true], JSON_UNESCAPED_UNICODE));
        }
        
        // 添加客户端传递的历史消息
        if (is_array($clientHistory)) {
            foreach ($clientHistory as $msg) {
                if (isset($msg['role']) && isset($msg['content'])) {
                    $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
                }
            }
        }
        
        $messages[] = ['role' => 'user', 'content' => $message];
        
        // 打印完整的 prompt 用于调试
        Logger::info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        Logger::info("📋 提交给 Gemini 的完整 Prompt");
        Logger::info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        Logger::info("🤖 模型: {$model}");
        Logger::info("📊 消息数量: " . count($messages));
        Logger::info("");
        
        foreach ($messages as $index => $msg) {
            $role = match($msg['role']) {
                'system' => '⚙️ System',
                'user' => '👤 User',
                'assistant' => '🤖 Assistant',
                default => '❓ Unknown'
            };
            
            $content = $msg['content'];
            $length = mb_strlen($content);
            
            Logger::info("[消息 " . ($index + 1) . "] {$role} ({$length} 字符)");
            Logger::info("---");
            Logger::info($content);
            Logger::info("---");
            Logger::info("");
        }
        
        $totalLength = array_reduce($messages, fn($sum, $msg) => $sum + mb_strlen($msg['content']), 0);
        $estimatedTokens = intval($totalLength / 3);
        
        Logger::info("📊 统计信息:");
        Logger::info("  • 总消息数: " . count($messages));
        Logger::info("  • 总字符数: {$totalLength}");
        Logger::info("  • 估算 Tokens: ~{$estimatedTokens}");
        Logger::info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        
        $asyncGemini = AIService::getAsyncGemini($model);
        $isConnectionAlive = true;
        $requestId = $asyncGemini->chatStreamAsync(
            $messages,
            function ($text, $isThought) use ($connection, &$isConnectionAlive, &$requestId, $asyncGemini) {
                if (!$isConnectionAlive) return;
                if ($text) {
                    if (!sendSSE($connection, $isThought ? 'thinking' : 'content', $text)) {
                        $isConnectionAlive = false;
                        if ($requestId) $asyncGemini->cancel($requestId);
                    }
                }
            },
            function ($fullContent, $usageMetadata = null, $usedModel = null) use ($connection, $model, &$isConnectionAlive) {
                if (!$isConnectionAlive) return;
                if ($usageMetadata) {
                    $costInfo = TokenCounter::calculateCost($usageMetadata, $usedModel ?? $model);
                    sendSSE($connection, 'usage', json_encode([
                        'tokens' => $costInfo['tokens'], 
                        'cost' => $costInfo['cost'], 
                        'cost_formatted' => TokenCounter::formatCost($costInfo['cost']), 
                        'currency' => $costInfo['currency'], 
                        'model' => $usedModel ?? $model
                    ], JSON_UNESCAPED_UNICODE));
                }
                sendSSE($connection, 'done', ''); 
                $connection->close(); 
            },
            function ($error) use ($connection, &$isConnectionAlive) {
                if (!$isConnectionAlive) return;
                sendSSE($connection, 'error', $error); 
                $connection->close(); 
            },
            ['enableSearch' => $enableSearch && $engine === 'google', 'enableTools' => $engine === 'mcp']
        );
        
        return null;
    }
    
    // 获取对话上下文（包含摘要 + 最近消息）- 兼容 Web 客户端
    CacheService::getChatContext($chatId, function($context) use ($connection, $message, $chatId, $headers, $enableSearch, $engine, $model) {
        $connection->send(new Response(200, $headers, ''));
        
        $prompts = $GLOBALS['config']['prompts'];
        
        // 发送知识来源信息
        $sourceTexts = $prompts['source_texts'] ?? ['google' => 'AI 预训练知识 + Google Search', 'mcp' => 'AI 预训练知识 + MCP 工具', 'off' => 'AI 预训练知识（搜索已关闭）'];
        sendSSE($connection, 'sources', json_encode([['text' => $sourceTexts[$engine] ?? $sourceTexts['off'], 'score' => 100]], JSON_UNESCAPED_UNICODE));
        
        // 通用聊天系统提示词
        $systemPrompt = $prompts['chat']['system'] ?? '你是一个友善、博学的 AI 助手，擅长回答各种问题并提供有价值的见解。请用中文回答。';
        
        // 构建消息数组
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        
        // 如果有摘要，添加为系统消息，并通知前端
        if ($context['summary']) {
            $historyLabel = $prompts['summarize']['history_label'] ?? '【对话历史摘要】';
            $messages[0]['content'] .= "\n\n{$historyLabel}\n" . $context['summary']['text'];
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
        
        $asyncGemini = AIService::getAsyncGemini($model);
        $isConnectionAlive = true;
        $requestId = $asyncGemini->chatStreamAsync(
            $messages,
            function ($text, $isThought) use ($connection, &$isConnectionAlive, &$requestId, $asyncGemini) {
                if (!$isConnectionAlive) return;
                if ($text) {
                    if (!sendSSE($connection, $isThought ? 'thinking' : 'content', $text)) {
                        $isConnectionAlive = false;
                        if ($requestId) $asyncGemini->cancel($requestId);
                    }
                }
            },
            function ($fullContent, $usageMetadata = null, $usedModel = null) use ($connection, $chatId, $context, $model, &$isConnectionAlive) {
                if (!$isConnectionAlive) return;
                // 保存助手回复
                if ($chatId) {
                    CacheService::addToChatHistory($chatId, ['role' => 'assistant', 'content' => $fullContent]);
                    // 检查是否需要进行上下文压缩
                    triggerSummarizationIfNeeded($chatId, $context);
                }
                // 发送 token 使用统计
                if ($usageMetadata) {
                    $costInfo = TokenCounter::calculateCost($usageMetadata, $usedModel ?? $model);
                    sendSSE($connection, 'usage', json_encode([
                        'tokens' => $costInfo['tokens'], 
                        'cost' => $costInfo['cost'], 
                        'cost_formatted' => TokenCounter::formatCost($costInfo['cost']), 
                        'currency' => $costInfo['currency'], 
                        'model' => $usedModel ?? $model
                    ], JSON_UNESCAPED_UNICODE));
                }
                sendSSE($connection, 'done', ''); 
                $connection->close(); 
            },
            function ($error) use ($connection, &$isConnectionAlive) {
                if (!$isConnectionAlive) return;
                sendSSE($connection, 'error', $error); 
                $connection->close(); 
            },
            ['enableSearch' => $enableSearch && $engine === 'google', 'enableTools' => $engine === 'mcp']
        );
    });
    
    return null;
}

function handleStreamContinue(Context $ctx): ?array
{
    $connection = $ctx->connection();
    $body = $ctx->jsonBody() ?? [];
    $prompt = $body['prompt'] ?? '';
    $enableSearch = $body['search'] ?? false;  // 续写默认关闭搜索
    $engine = $body['engine'] ?? 'off';        // 默认关闭
    $ragEnabled = $body['rag'] ?? false;       // 续写默认关闭 RAG
    $keywordWeight = floatval($body['keyword_weight'] ?? 0.5);
    $model = $body['model'] ?? 'gemini-2.5-flash';
    
    $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
    $connection->send(new Response(200, $headers, ''));
    
    $prompts = $GLOBALS['config']['prompts'];
    $ragPrompts = $prompts['rag'];
    
    // 获取当前书籍名称
    $bookTitle = '当前书籍';
    $currentBookPath = getCurrentBookPath();
    if ($currentBookPath) {
        $ext = strtolower(pathinfo($currentBookPath, PATHINFO_EXTENSION));
        if ($ext === 'epub') {
            $metadata = \SmartBook\Parser\EpubParser::extractMetadata($currentBookPath);
            if (!empty($metadata['title'])) $bookTitle = '《' . $metadata['title'] . '》';
        } else {
            $bookTitle = '《' . pathinfo($currentBookPath, PATHINFO_FILENAME) . '》';
        }
    }
    
    $baseSystemPrompt = str_replace('{title}', $bookTitle, $prompts['continue']['system'] ?? '');
    $userPrompt = $prompt ?: str_replace('{title}', $bookTitle, $prompts['continue']['default_prompt'] ?? '');
    
    // RAG 搜索函数
    $continuePrompts = $prompts['continue'];
    $doChat = function($ragContext, $ragSources) use (
        $connection, $baseSystemPrompt, $userPrompt, $enableSearch, $engine, $model, $prompts, $ragEnabled, $continuePrompts
    ) {
        $systemPrompt = $baseSystemPrompt;
        
        if ($ragEnabled && !empty($ragContext)) {
            // 使用配置文件中的 RAG 参考说明，明确告知 AI 不要复述
            $ragInstruction = $continuePrompts['rag_instruction'] ?? '';
            $systemPrompt .= str_replace('{context}', $ragContext, $ragInstruction);
            sendSSE($connection, 'sources', json_encode($ragSources, JSON_UNESCAPED_UNICODE));
        } else {
            // 发送知识来源信息
            $sourceTexts = $prompts['source_texts'] ?? ['google' => 'AI 预训练知识 + Google Search', 'mcp' => 'AI 预训练知识 + MCP 工具', 'off' => 'AI 预训练知识（搜索已关闭）'];
            sendSSE($connection, 'sources', json_encode([['text' => $sourceTexts[$engine] ?? $sourceTexts['off'], 'score' => 100]], JSON_UNESCAPED_UNICODE));
        }
        
        $asyncGemini = AIService::getAsyncGemini($model);
        $isConnectionAlive = true;
        $requestId = $asyncGemini->chatStreamAsync(
            [['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => $userPrompt]],
            function ($text, $isThought) use ($connection, &$isConnectionAlive, &$requestId, $asyncGemini) {
                if (!$isConnectionAlive) return;
                if (!$isThought && $text) {
                    if (!sendSSE($connection, 'content', $text)) {
                        $isConnectionAlive = false;
                        if ($requestId) $asyncGemini->cancel($requestId);
                    }
                }
            },
            function ($fullContent, $usageMetadata = null, $usedModel = null) use ($connection, $model, &$isConnectionAlive) {
                if (!$isConnectionAlive) return;
                // 发送 token 使用统计
                if ($usageMetadata) {
                    $costInfo = TokenCounter::calculateCost($usageMetadata, $usedModel ?? $model);
                    sendSSE($connection, 'usage', json_encode([
                        'tokens' => $costInfo['tokens'], 
                        'cost' => $costInfo['cost'], 
                        'cost_formatted' => TokenCounter::formatCost($costInfo['cost']), 
                        'currency' => $costInfo['currency'], 
                        'model' => $usedModel ?? $model
                    ], JSON_UNESCAPED_UNICODE));
                }
                sendSSE($connection, 'done', ''); 
                $connection->close(); 
            },
            function ($error) use ($connection, &$isConnectionAlive) {
                if (!$isConnectionAlive) return;
                sendSSE($connection, 'error', $error);
                $connection->close();
            },
            ['enableSearch' => $enableSearch && $engine === 'google', 'enableTools' => $engine === 'mcp']
        );
    };
    
    // RAG 搜索逻辑：使用当前选中的书籍
    $currentCache = getCurrentBookCache();
    if ($ragEnabled && $currentCache) {
        try {
            $embedder = new EmbeddingClient(GEMINI_API_KEY);
            $queryEmbedding = $embedder->embedQuery($userPrompt);
            
            $ragContext = '';
            $ragSources = [];
            $chunkTemplate = $ragPrompts['chunk_template'] ?? "【Passage {index}】\n{text}\n";
            
            $vectorStore = new VectorStore($currentCache);
            $results = $vectorStore->hybridSearch($userPrompt, $queryEmbedding, 5, $keywordWeight);
            
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
    
    $currentCache = getCurrentBookCache();
    if (!$currentCache) { $connection->send(json_encode(['error' => 'No book index available'])); return; }
    
    $embedder = new EmbeddingClient(GEMINI_API_KEY);
    $queryEmbedding = $embedder->embedQuery($question);
    
    $vectorStore = new VectorStore($currentCache);
    $results = $vectorStore->hybridSearch($question, $queryEmbedding, $topK, 0.6);
    
    $connection->send(json_encode(['type' => 'sources', 'sources' => array_map(fn($r) => ['text' => mb_substr($r['chunk']['text'], 0, 200) . '...', 'score' => round($r['score'] * 100, 1)], $results)]));
    
    // 使用配置文件中的片段标签
    $chunkLabel = $GLOBALS['config']['prompts']['chunk_label'] ?? '【片段 {index}】';
    $context = "";
    foreach ($results as $i => $result) {
        $label = str_replace('{index}', $i + 1, $chunkLabel);
        $context .= "{$label}\n" . $result['chunk']['text'] . "\n\n";
    }
    
    // 使用配置文件中的提示词
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
// TTS 语音合成
// ===================================

/**
 * 文本转语音
 */
function handleTTSSynthesize(Context $ctx): ?array
{
    $connection = $ctx->connection();
    $body = $ctx->jsonBody() ?? [];
    $text = $body['text'] ?? '';
    $voice = $body['voice'] ?? null;
    $rate = floatval($body['rate'] ?? 1.0);
    $pitch = floatval($body['pitch'] ?? 0.0);
    
    if (empty($text)) {
        return ['error' => 'Missing text'];
    }
    
    try {
        $ttsClient = new GoogleTTSClient();
        
        // 自动检测语言并选择默认语音
        $languageCode = GoogleTTSClient::detectLanguage($text);
        if (!$voice) {
            $voice = GoogleTTSClient::getDefaultVoice($languageCode);
        }
        
        $result = $ttsClient->synthesize($text, $voice, $languageCode, $rate, $pitch);
        
        // 返回 base64 音频数据
        $jsonHeaders = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ];
        
        $connection->send(new Response(200, $jsonHeaders, json_encode([
            'success' => true,
            'audio' => $result['audio'],
            'format' => $result['format'],
            'voice' => $voice,
            'language' => $languageCode,
            'charCount' => $result['charCount'] ?? 0,
            'cost' => $result['cost'] ?? 0,
            'costFormatted' => $result['costFormatted'] ?? '',
        ], JSON_UNESCAPED_UNICODE)));
        
        return null;
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * 获取可用语音列表
 */
function handleTTSVoices(): array
{
    try {
        $ttsClient = new GoogleTTSClient();
        return [
            'voices' => $ttsClient->getVoices(),
            'default' => [
                'zh-CN' => GoogleTTSClient::getDefaultVoice('zh-CN'),
                'en-US' => GoogleTTSClient::getDefaultVoice('en-US'),
            ],
        ];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * 直接从 Google TTS API 获取语音列表（调试用）
 */
function handleTTSListAPIVoices(): array
{
    $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if (empty($apiKey)) {
        return ['error' => 'GEMINI_API_KEY 未配置'];
    }
    
    // 调用 Google TTS voices API（不传 languageCode，获取所有语音）
    $url = "https://texttospeech.googleapis.com/v1/voices?key={$apiKey}";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    if ($error) {
        return ['error' => "curl 错误: {$error}"];
    }
    
    if ($httpCode !== 200) {
        $result = json_decode($response, true);
        $errorMsg = $result['error']['message'] ?? '未知错误';
        return [
            'error' => "API 错误 ({$httpCode}): {$errorMsg}",
            'hint' => '请确保在 Google Cloud Console 中启用了 Text-to-Speech API',
            'enable_url' => 'https://console.cloud.google.com/apis/library/texttospeech.googleapis.com',
        ];
    }
    
    $result = json_decode($response, true);
    
    // 按语言分组（注意：中文是 cmn-CN/cmn-TW，不是 zh-CN）
    $voicesByLang = [];
    $allLangs = [];
    foreach ($result['voices'] ?? [] as $voice) {
        foreach ($voice['languageCodes'] ?? [] as $langCode) {
            if (!isset($voicesByLang[$langCode])) {
                $voicesByLang[$langCode] = [];
            }
            $voicesByLang[$langCode][] = [
                'name' => $voice['name'],
                'gender' => $voice['ssmlGender'],
                'sampleRate' => $voice['naturalSampleRateHertz'],
            ];
            $allLangs[$langCode] = true;
        }
    }
    
    // 返回所有语言代码和中英文语音
    return [
        'status' => 'ok',
        'total_voices' => count($result['voices'] ?? []),
        'all_languages' => array_keys($allLangs),
        'cmn-CN' => $voicesByLang['cmn-CN'] ?? [],  // 普通话（中国大陆）
        'cmn-TW' => $voicesByLang['cmn-TW'] ?? [],  // 普通话（台湾）
        'en-US' => $voicesByLang['en-US'] ?? [],
    ];
}

// ===================================
// ASR 语音识别
// ===================================

/**
 * 语音转文本
 */
function handleASRRecognize(Context $ctx): ?array
{
    $connection = $ctx->connection();
    $body = $ctx->jsonBody() ?? [];
    $audio = $body['audio'] ?? '';  // Base64 编码的音频
    $encoding = $body['encoding'] ?? 'WEBM_OPUS';
    $sampleRate = intval($body['sample_rate'] ?? 48000);
    $language = $body['language'] ?? null;
    
    if (empty($audio)) {
        return ['error' => 'Missing audio data'];
    }
    
    try {
        $asrClient = new GoogleASRClient();
        
        // 如果没有指定语言，使用默认语言
        if (!$language) {
            $language = GoogleASRClient::getDefaultLanguage();
        }
        
        $result = $asrClient->recognize($audio, $encoding, $sampleRate, $language);
        
        // 返回识别结果
        $jsonHeaders = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ];
        
        $connection->send(new Response(200, $jsonHeaders, json_encode([
            'success' => true,
            'transcript' => $result['transcript'],
            'confidence' => $result['confidence'],
            'language' => $result['language'],
            'duration' => $result['duration'] ?? 0,
            'cost' => $result['cost'] ?? 0,
            'costFormatted' => $result['costFormatted'] ?? '',
        ], JSON_UNESCAPED_UNICODE)));
        
        return null;
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * 获取支持的语言列表
 */
function handleASRLanguages(): array
{
    try {
        $asrClient = new GoogleASRClient();
        return [
            'languages' => $asrClient->getLanguages(),
            'default' => GoogleASRClient::getDefaultLanguage(),
        ];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ===================================
// Gemini Context Cache 管理
// ===================================

/**
 * 列出所有 Gemini 上下文缓存
 */
function handleContextCacheList(): array
{
    try {
        $cache = new GeminiContextCache(GEMINI_API_KEY);
        return $cache->listCaches();
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 创建上下文缓存
 */
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
        
        // 检查是否满足最低 token 要求
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

/**
 * 为书籍创建上下文缓存
 */
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
        // 提取书籍内容
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
        
        // 检查是否满足最低 token 要求
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

/**
 * 删除上下文缓存
 */
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

/**
 * 获取上下文缓存详情
 */
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
// 增强版续写（Context Cache + Few-shot）
// ===================================

/**
 * 为书籍准备续写环境（创建缓存 + 提取风格样本）
 */
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
        // 提取书籍内容
        $ext = strtolower(pathinfo($bookFile, PATHINFO_EXTENSION));
        if ($ext === 'epub') {
            $content = \SmartBook\Parser\EpubParser::extractText($bookPath);
        } else {
            $content = file_get_contents($bookPath);
        }
        
        if (empty($content)) {
            return ['success' => false, 'error' => 'Failed to extract book content'];
        }
        
        // 使用增强版续写服务
        $writer = new EnhancedStoryWriter(GEMINI_API_KEY, $model);
        return $writer->prepareForBook($bookFile, $content);
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 获取书籍的续写状态
 */
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

/**
 * 增强版续写（使用 Context Cache + Few-shot）
 */
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
        // 首先查找书籍的缓存
        $cacheClient = new GeminiContextCache(GEMINI_API_KEY, $requestedModel);
        $bookCache = $cacheClient->getBookCache($bookFile);
        
        // 如果缓存不存在，自动创建
        if (!$bookCache) {
            sendSSE($connection, 'sources', json_encode([
                ['text' => "正在为《{$bookFile}》创建 Context Cache，请稍候...", 'score' => 0]
            ], JSON_UNESCAPED_UNICODE));
            
            // 提取书籍内容
            $booksDir = dirname(__DIR__, 2) . '/books';
            $bookPath = $booksDir . '/' . $bookFile;
            
            if (!file_exists($bookPath)) {
                sendSSE($connection, 'error', "书籍文件不存在: {$bookFile}");
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
                sendSSE($connection, 'error', "无法提取书籍内容");
                $connection->close();
                return null;
            }
            
            // 创建缓存
            $createResult = $cacheClient->createForBook($bookFile, $content, 7200);
            
            if (!$createResult['success']) {
                sendSSE($connection, 'error', "创建缓存失败: " . ($createResult['error'] ?? '未知错误'));
                $connection->close();
                return null;
            }
            
            // 重新获取缓存
            $bookCache = $cacheClient->getBookCache($bookFile);
            
            if (!$bookCache) {
                sendSSE($connection, 'error', "创建缓存后仍无法获取");
                $connection->close();
                return null;
            }
            
            sendSSE($connection, 'sources', json_encode([
                ['text' => "✅ Context Cache 创建成功！", 'score' => 100]
            ], JSON_UNESCAPED_UNICODE));
        }
        
        // 检查模型是否匹配
        $cacheModel = str_replace('models/', '', $bookCache['model'] ?? '');
        if ($cacheModel !== $requestedModel) {
            $errorMsg = "⚠️ 模型不匹配！\n\n" .
                "• 当前选择: {$requestedModel}\n" .
                "• 缓存要求: {$cacheModel}\n\n" .
                "请切换到 {$cacheModel} 模型后重试。";
            sendSSE($connection, 'error', $errorMsg);
            $connection->close();
            return null;
        }
        
        $model = $cacheModel;  // 使用缓存对应的模型
        
        $writer = new EnhancedStoryWriter(GEMINI_API_KEY, $model);
        
        // 发送知识来源
        $tokenCount = $bookCache['usageMetadata']['totalTokenCount'] ?? 0;
        sendSSE($connection, 'sources', json_encode([
            ['text' => "Context Cache（{$tokenCount} tokens）+ Few-shot（{$model}）", 'score' => 100]
        ], JSON_UNESCAPED_UNICODE));
        
        $isConnectionAlive = true;
        $writer->continueStory(
            $bookFile,
            $prompt,
            function ($text, $isThought) use ($connection, &$isConnectionAlive) {
                if (!$isConnectionAlive) return;
                if ($text && !$isThought) {
                    if (!sendSSE($connection, 'content', $text)) {
                        $isConnectionAlive = false;
                    }
                }
            },
            function ($fullContent, $usageMetadata = null, $usedModel = null) use ($connection, $model, &$isConnectionAlive) {
                if (!$isConnectionAlive) return;
                if ($usageMetadata) {
                    $costInfo = TokenCounter::calculateCost($usageMetadata, $usedModel ?? $model);
                    sendSSE($connection, 'usage', json_encode([
                        'tokens' => $costInfo['tokens'],
                        'cost' => $costInfo['cost'],
                        'cost_formatted' => TokenCounter::formatCost($costInfo['cost']),
                        'currency' => $costInfo['currency'],
                        'model' => $usedModel ?? $model
                    ], JSON_UNESCAPED_UNICODE));
                }
                sendSSE($connection, 'done', '');
                $connection->close();
            },
            function ($error) use ($connection, &$isConnectionAlive) {
                if (!$isConnectionAlive) return;
                sendSSE($connection, 'error', $error);
                $connection->close();
            },
            [
                'custom_instructions' => $customInstructions,
                'token_count' => $tokenCount,
            ]
        );
        
    } catch (Exception $e) {
        sendSSE($connection, 'error', $e->getMessage());
        $connection->close();
    }
    
    return null;
}

/**
 * 分析书籍人物
 */
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
        
        sendSSE($connection, 'sources', json_encode([
            ['text' => '使用 Context Cache 分析人物', 'score' => 100]
        ], JSON_UNESCAPED_UNICODE));
        
        $writer->analyzeCharacters(
            $bookFile,
            function ($text, $isThought) use ($connection) {
                if ($text && !$isThought) {
                    sendSSE($connection, 'content', $text);
                }
            },
            function ($fullContent, $usageMetadata = null, $usedModel = null) use ($connection, $model) {
                if ($usageMetadata) {
                    $costInfo = TokenCounter::calculateCost($usageMetadata, $usedModel ?? $model);
                    sendSSE($connection, 'usage', json_encode([
                        'tokens' => $costInfo['tokens'],
                        'cost' => $costInfo['cost'],
                        'cost_formatted' => TokenCounter::formatCost($costInfo['cost']),
                        'currency' => $costInfo['currency'],
                        'model' => $usedModel ?? $model
                    ], JSON_UNESCAPED_UNICODE));
                }
                sendSSE($connection, 'done', '');
                $connection->close();
            },
            function ($error) use ($connection) {
                sendSSE($connection, 'error', $error);
                $connection->close();
            }
        );
        
    } catch (Exception $e) {
        sendSSE($connection, 'error', $e->getMessage());
        $connection->close();
    }
    
    return null;
}

// ===================================
// MCP Servers 管理
// ===================================

/**
 * 获取 MCP 服务器列表
 */
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

/**
 * 保存 MCP 服务器配置
 */
function handleSaveMCPServers(Request $request): array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $servers = $body['servers'] ?? [];
    
    $configPath = dirname(__DIR__, 2) . '/config/mcp.json';
    
    // 读取现有配置
    $config = [];
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true) ?? [];
    }
    
    // 转换为 MCP 配置格式
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
    
    // 保存配置
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
// MCP Server 请求处理（端口 8089）
// 使用 Streamable HTTP 协议（不使用 SSE）
// ===================================

// MCP Server 实例（延迟初始化）
$mcpServer = null;

/**
 * 获取 MCP Server 实例
 */
function getMCPServer(): \SmartBook\MCP\StreamableHttpServer
{
    global $mcpServer;
    
    if ($mcpServer === null) {
        $booksDir = dirname(__DIR__, 2) . '/books';
        // 开启 debug 模式，输出详细的 SSE 连接日志（设为 false 可关闭）
        $mcpServer = new \SmartBook\MCP\StreamableHttpServer($booksDir, debug: true);
    }
    
    return $mcpServer;
}

/**
 * 处理 MCP 请求（Streamable HTTP 协议）
 * 
 * 协议特点：
 * - POST /mcp: JSON-RPC 请求端点
 * - GET /mcp: 服务器信息
 * - DELETE /mcp: 终止会话
 * - 支持 Mcp-Session-Id header 进行会话管理
 */
function handleMCPRequest(TcpConnection $connection, Request $request): void
{
    $server = getMCPServer();
    $server->handleRequest($connection, $request);
}

// ===================================
// TCP 手动 HTTP 解析（支持 SSE 长连接）
// ===================================

/**
 * 从原始 TCP 数据解析 HTTP 请求
 * 
 * 这允许我们使用 TCP 协议而不是 HTTP 协议来处理 MCP 端点，
 * 从而支持 SSE 长连接（HTTP 协议会在响应后自动关闭连接）
 * 
 * @param string $data 原始 TCP 数据
 * @param TcpConnection $connection TCP 连接
 * @return Request|null 解析后的 HTTP 请求，解析失败返回 null
 */
function parseHttpRequest(string $data, TcpConnection $connection): ?Request
{
    // 检查数据是否完整（使用 Workerman 的 HTTP 协议检测）
    $inputLength = \Workerman\Protocols\Http::input($data, $connection);
    
    if ($inputLength === 0) {
        // 数据不完整，等待更多数据
        return null;
    }
    
    if ($inputLength < 0) {
        // 解析错误，关闭连接
        $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n");
        return null;
    }
    
    // 解析 HTTP 请求
    try {
        $request = \Workerman\Protocols\Http::decode($data, $connection);
        return $request;
    } catch (\Exception $e) {
        $connection->close("HTTP/1.1 400 Bad Request\r\n\r\nParse error: " . $e->getMessage());
        return null;
    }
}
