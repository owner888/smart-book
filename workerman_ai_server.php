<?php
/**
 * Workerman AI 书籍助手服务
 * 
 * 功能：
 * - HTTP API：/api/ask, /api/chat, /api/continue
 * - WebSocket：实时流式输出
 * 
 * 安装依赖：
 * composer require workerman/workerman
 * 
 * 启动服务：
 * php workerman_ai_server.php start
 * php workerman_ai_server.php start -d  (守护进程模式)
 */

// 加载 Composer autoload
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    echo "请先运行: composer require workerman/workerman\n";
    exit(1);
}

require_once __DIR__ . '/calibre_rag.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Redis\Client as RedisClient;

// ===================================
// 配置
// ===================================

// Redis 配置
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('CACHE_TTL', 3600); // 缓存 1 小时
define('CACHE_PREFIX', 'smartbook:');

// 从 ~/.zprofile 读取 API Key
$zprofile = file_get_contents('/Users/kaka/.zprofile');
preg_match('/GEMINI_API_KEY="([^"]+)"/', $zprofile, $matches);
define('GEMINI_API_KEY', $matches[1] ?? '');

if (empty(GEMINI_API_KEY)) {
    die("错误: 无法获取 GEMINI_API_KEY\n");
}

// 默认书籍索引缓存
define('DEFAULT_BOOK_CACHE', '/Users/kaka/Documents/西游记_index.json');
define('DEFAULT_BOOK_PATH', '/Users/kaka/Documents/西游记.epub');

// ===================================
// Redis 缓存服务
// ===================================

class CacheService
{
    private static ?RedisClient $redis = null;
    private static bool $connected = false;
    
    /**
     * 初始化 Redis 连接（异步）
     */
    public static function init(): void
    {
        if (self::$redis !== null) {
            return;
        }
        
        self::$redis = new RedisClient('redis://' . REDIS_HOST . ':' . REDIS_PORT);
        self::$connected = true;
        echo "✅ Redis 连接成功\n";
    }
    
    /**
     * 获取 Redis 客户端
     */
    public static function getRedis(): ?RedisClient
    {
        return self::$redis;
    }
    
    /**
     * 是否已连接
     */
    public static function isConnected(): bool
    {
        return self::$connected;
    }
    
    /**
     * 生成缓存键
     */
    public static function makeKey(string $type, string $input): string
    {
        return CACHE_PREFIX . $type . ':' . md5($input);
    }
    
    /**
     * 获取缓存（异步回调）
     */
    public static function get(string $key, callable $callback): void
    {
        if (!self::$connected || !self::$redis) {
            $callback(null);
            return;
        }
        
        self::$redis->get($key, function($result) use ($callback) {
            if ($result) {
                $data = json_decode($result, true);
                $callback($data);
            } else {
                $callback(null);
            }
        });
    }
    
    /**
     * 设置缓存（异步）
     */
    public static function set(string $key, mixed $value, int $ttl = CACHE_TTL): void
    {
        if (!self::$connected || !self::$redis) {
            return;
        }
        
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        self::$redis->setex($key, $ttl, $json);
    }
    
    /**
     * 获取缓存统计
     */
    public static function getStats(callable $callback): void
    {
        if (!self::$connected || !self::$redis) {
            $callback(['connected' => false]);
            return;
        }
        
        self::$redis->keys(CACHE_PREFIX . '*', function($keys) use ($callback) {
            $callback([
                'connected' => true,
                'cached_items' => count($keys ?? []),
            ]);
        });
    }
}

// ===================================
// AI 服务类
// ===================================

class AIService
{
    private static ?BookRAGAssistant $ragAssistant = null;
    private static ?GeminiClient $gemini = null;
    
    public static function getRAGAssistant(): BookRAGAssistant
    {
        if (self::$ragAssistant === null) {
            self::$ragAssistant = new BookRAGAssistant(GEMINI_API_KEY);
            if (file_exists(DEFAULT_BOOK_CACHE)) {
                self::$ragAssistant->loadBook(DEFAULT_BOOK_PATH, DEFAULT_BOOK_CACHE);
            }
        }
        return self::$ragAssistant;
    }
    
    public static function getGemini(): GeminiClient
    {
        if (self::$gemini === null) {
            self::$gemini = new GeminiClient(GEMINI_API_KEY, GeminiClient::MODEL_GEMINI_25_FLASH);
        }
        return self::$gemini;
    }
    
    /**
     * RAG 问答（非流式）
     */
    public static function askBook(string $question, int $topK = 8): array
    {
        $assistant = self::getRAGAssistant();
        
        // 生成嵌入向量
        $embedder = new EmbeddingClient(GEMINI_API_KEY);
        $queryEmbedding = $embedder->embedQuery($question);
        
        // 混合检索
        $vectorStore = new VectorStore(DEFAULT_BOOK_CACHE);
        $results = $vectorStore->hybridSearch($question, $queryEmbedding, $topK, 0.6);
        
        // 构建上下文
        $context = "";
        foreach ($results as $i => $result) {
            $context .= "【片段 " . ($i + 1) . "】\n" . $result['chunk']['text'] . "\n\n";
        }
        
        // 调用 LLM
        $gemini = self::getGemini();
        $response = $gemini->chat([
            ['role' => 'system', 'content' => "你是一个书籍分析助手。根据以下内容回答问题，使用中文：\n\n{$context}"],
            ['role' => 'user', 'content' => $question],
        ]);
        
        $answer = '';
        foreach ($response['candidates'] ?? [] as $candidate) {
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (!($part['thought'] ?? false)) {
                    $answer .= $part['text'] ?? '';
                }
            }
        }
        
        return [
            'success' => true,
            'question' => $question,
            'answer' => $answer,
            'sources' => array_map(fn($r) => [
                'text' => mb_substr($r['chunk']['text'], 0, 200) . '...',
                'score' => round($r['score'] * 100, 1),
            ], $results),
        ];
    }
    
    /**
     * 通用聊天（非流式）
     */
    public static function chat(array $messages): array
    {
        $gemini = self::getGemini();
        $response = $gemini->chat($messages);
        
        $answer = '';
        foreach ($response['candidates'] ?? [] as $candidate) {
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (!($part['thought'] ?? false)) {
                    $answer .= $part['text'] ?? '';
                }
            }
        }
        
        return [
            'success' => true,
            'answer' => $answer,
        ];
    }
    
    /**
     * 续写章节（非流式）
     */
    public static function continueStory(string $prompt = ''): array
    {
        $systemPrompt = <<<'EOT'
你是一位精通古典文学的作家，擅长模仿《西游记》的章回体小说风格写作。

请严格模仿《西游记》的写作风格特点：
1. 章回体格式：标题用对仗的两句话
2. 开头常用诗词引入
3. 结尾常用"毕竟不知XXX，且听下回分解"
4. 文言白话混合的语言风格
5. 人物对话生动传神
EOT;

        $userPrompt = $prompt ?: '请为《西游记》续写一个新章节。设定：唐僧师徒四人遇到一个新的妖怪。写一个完整的章回，约1000字。';
        
        $gemini = self::getGemini();
        $response = $gemini->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ]);
        
        $story = '';
        foreach ($response['candidates'] ?? [] as $candidate) {
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (!($part['thought'] ?? false)) {
                    $story .= $part['text'] ?? '';
                }
            }
        }
        
        return [
            'success' => true,
            'story' => $story,
        ];
    }
}

// ===================================
// HTTP 服务器
// ===================================

$httpWorker = new Worker('http://0.0.0.0:8088');
$httpWorker->count = 4;
$httpWorker->name = 'AI-HTTP-Server';

// Worker 启动时初始化 Redis
$httpWorker->onWorkerStart = function ($worker) {
    try {
        CacheService::init();
    } catch (Exception $e) {
        echo "⚠️  Redis 连接失败: {$e->getMessage()}\n";
        echo "   服务将在无缓存模式下运行\n";
    }
};

$httpWorker->onMessage = function (TcpConnection $connection, Request $request) {
    $path = $request->path();
    $method = $request->method();
    
    // CORS 头 (JSON)
    $jsonHeaders = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type',
    ];
    
    // 处理 OPTIONS 预检请求
    if ($method === 'OPTIONS') {
        $connection->send(new Response(200, $jsonHeaders, ''));
        return;
    }
    
    try {
        // 首页返回 chat.html
        if ($path === '/' || $path === '/chat' || $path === '/chat.html') {
            $chatHtmlPath = __DIR__ . '/chat.html';
            if (file_exists($chatHtmlPath)) {
                $html = file_get_contents($chatHtmlPath);
                $connection->send(new Response(200, [
                    'Content-Type' => 'text/html; charset=utf-8',
                ], $html));
                return;
            }
        }
        
        // API 路由
        $result = match ($path) {
            '/api' => ['status' => 'ok', 'message' => 'AI Book Assistant API', 'endpoints' => [
                'POST /api/ask' => '书籍问答 (RAG)',
                'POST /api/chat' => '通用聊天',
                'POST /api/continue' => '续写章节',
                'POST /api/stream/ask' => '书籍问答 (流式)',
                'POST /api/stream/chat' => '通用聊天 (流式)',
                'POST /api/stream/continue' => '续写章节 (流式)',
                'GET /api/health' => '健康检查',
            ]],
            '/api/health' => ['status' => 'ok', 'timestamp' => date('Y-m-d H:i:s'), 'redis' => CacheService::isConnected()],
            '/api/cache/stats' => handleCacheStats($connection),
            '/api/ask' => handleAskWithCache($connection, $request),
            '/api/chat' => handleChat($request),
            '/api/continue' => handleContinue($request),
            '/api/stream/ask' => handleStreamAsk($connection, $request),
            '/api/stream/chat' => handleStreamChat($connection, $request),
            '/api/stream/continue' => handleStreamContinue($connection, $request),
            default => ['error' => 'Not Found', 'path' => $path],
        };
        
        // 如果 SSE 端点返回 null，说明已经处理完毕
        if ($result === null) {
            return;
        }
        
        $statusCode = isset($result['error']) ? 404 : 200;
        $connection->send(new Response($statusCode, $jsonHeaders, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
        
    } catch (Exception $e) {
        $connection->send(new Response(500, $jsonHeaders, json_encode([
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE)));
    }
};

function handleAsk(Request $request): array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $question = $body['question'] ?? '';
    $topK = $body['top_k'] ?? 8;
    
    if (empty($question)) {
        return ['error' => 'Missing question parameter'];
    }
    
    return AIService::askBook($question, $topK);
}

function handleChat(Request $request): array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $messages = $body['messages'] ?? [];
    
    if (empty($messages)) {
        return ['error' => 'Missing messages parameter'];
    }
    
    return AIService::chat($messages);
}

function handleContinue(Request $request): array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $prompt = $body['prompt'] ?? '';
    
    return AIService::continueStory($prompt);
}

/**
 * 带缓存的书籍问答（异步）
 */
function handleAskWithCache(TcpConnection $connection, Request $request): ?array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $question = $body['question'] ?? '';
    $topK = $body['top_k'] ?? 8;
    
    if (empty($question)) {
        return ['error' => 'Missing question parameter'];
    }
    
    $cacheKey = CacheService::makeKey('ask', $question . ':' . $topK);
    $jsonHeaders = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin' => '*',
    ];
    
    // 尝试从缓存获取
    CacheService::get($cacheKey, function($cached) use ($connection, $question, $topK, $cacheKey, $jsonHeaders) {
        if ($cached) {
            // 缓存命中
            $cached['cached'] = true;
            $connection->send(new Response(200, $jsonHeaders, json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
            return;
        }
        
        // 缓存未命中，执行查询
        $result = AIService::askBook($question, $topK);
        $result['cached'] = false;
        
        // 保存到缓存
        CacheService::set($cacheKey, $result);
        
        $connection->send(new Response(200, $jsonHeaders, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
    });
    
    return null; // 异步处理，返回 null
}

/**
 * 缓存统计
 */
function handleCacheStats(TcpConnection $connection): ?array
{
    $jsonHeaders = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin' => '*',
    ];
    
    CacheService::getStats(function($stats) use ($connection, $jsonHeaders) {
        $connection->send(new Response(200, $jsonHeaders, json_encode($stats, JSON_UNESCAPED_UNICODE)));
    });
    
    return null; // 异步处理
}

// ===================================
// SSE 流式端点
// ===================================

function handleStreamAsk(TcpConnection $connection, Request $request): ?array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $question = $body['question'] ?? '';
    $topK = $body['top_k'] ?? 8;
    
    if (empty($question)) {
        return ['error' => 'Missing question'];
    }
    
    // SSE 头
    $headers = [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'Connection' => 'keep-alive',
        'Access-Control-Allow-Origin' => '*',
    ];
    
    // 发送 SSE 头
    $connection->send(new Response(200, $headers, ''));
    
    // 检索相关内容
    $embedder = new EmbeddingClient(GEMINI_API_KEY);
    $queryEmbedding = $embedder->embedQuery($question);
    
    $vectorStore = new VectorStore(DEFAULT_BOOK_CACHE);
    $results = $vectorStore->hybridSearch($question, $queryEmbedding, $topK, 0.6);
    
    // 发送检索来源
    $sources = array_map(fn($r) => [
        'text' => mb_substr($r['chunk']['text'], 0, 200) . '...',
        'score' => round($r['score'] * 100, 1),
    ], $results);
    sendSSE($connection, 'sources', json_encode($sources, JSON_UNESCAPED_UNICODE));
    
    // 构建上下文
    $context = "";
    foreach ($results as $i => $result) {
        $context .= "【片段 " . ($i + 1) . "】\n" . $result['chunk']['text'] . "\n\n";
    }
    
    // 流式生成回答
    $gemini = AIService::getGemini();
    $gemini->chatStream(
        [
            ['role' => 'system', 'content' => "你是一个书籍分析助手。根据以下内容回答问题，使用中文：\n\n{$context}"],
            ['role' => 'user', 'content' => $question],
        ],
        function ($text, $chunk, $isThought) use ($connection) {
            if (!$isThought && $text) {
                sendSSE($connection, 'content', $text);
            }
        },
        ['enableSearch' => false]
    );
    
    sendSSE($connection, 'done', '');
    $connection->close();
    return null;
}

function handleStreamChat(TcpConnection $connection, Request $request): ?array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $messages = $body['messages'] ?? [];
    
    if (empty($messages)) {
        return ['error' => 'Missing messages'];
    }
    
    // SSE 头
    $headers = [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'Connection' => 'keep-alive',
        'Access-Control-Allow-Origin' => '*',
    ];
    
    $connection->send(new Response(200, $headers, ''));
    
    $gemini = AIService::getGemini();
    $gemini->chatStream(
        $messages,
        function ($text, $chunk, $isThought) use ($connection) {
            if (!$isThought && $text) {
                sendSSE($connection, 'content', $text);
            }
        },
        ['enableSearch' => false]
    );
    
    sendSSE($connection, 'done', '');
    $connection->close();
    return null;
}

function handleStreamContinue(TcpConnection $connection, Request $request): ?array
{
    $body = json_decode($request->rawBody(), true) ?? [];
    $prompt = $body['prompt'] ?? '';
    
    // SSE 头
    $headers = [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'Connection' => 'keep-alive',
        'Access-Control-Allow-Origin' => '*',
    ];
    
    $connection->send(new Response(200, $headers, ''));
    
    $systemPrompt = <<<'EOT'
你是一位精通古典文学的作家，擅长模仿《西游记》的章回体小说风格写作。
请严格模仿《西游记》的写作风格特点。
EOT;

    $userPrompt = $prompt ?: '请为《西游记》续写一个新章节。设定：唐僧师徒四人遇到一个新的妖怪。写一个完整的章回，约1000字。';
    
    $gemini = AIService::getGemini();
    $gemini->chatStream(
        [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        function ($text, $chunk, $isThought) use ($connection) {
            if (!$isThought && $text) {
                sendSSE($connection, 'content', $text);
            }
        },
        ['enableSearch' => false]
    );
    
    sendSSE($connection, 'done', '');
    $connection->close();
    return null;
}

/**
 * 发送 SSE 事件
 */
function sendSSE(TcpConnection $connection, string $event, string $data): void
{
    $message = "event: {$event}\ndata: {$data}\n\n";
    $connection->send($message);
}

// ===================================
// WebSocket 服务器（流式输出）
// ===================================

$wsWorker = new Worker('websocket://0.0.0.0:8081');
$wsWorker->count = 4;
$wsWorker->name = 'AI-WebSocket-Server';

$wsWorker->onConnect = function (TcpConnection $connection) {
    echo "WebSocket 连接: {$connection->id}\n";
};

$wsWorker->onMessage = function (TcpConnection $connection, $data) {
    $request = json_decode($data, true);
    if (!$request) {
        $connection->send(json_encode(['error' => 'Invalid JSON']));
        return;
    }
    
    $action = $request['action'] ?? '';
    
    try {
        switch ($action) {
            case 'ask':
                streamAsk($connection, $request);
                break;
            case 'chat':
                streamChat($connection, $request);
                break;
            case 'continue':
                streamContinue($connection, $request);
                break;
            default:
                $connection->send(json_encode(['error' => 'Unknown action', 'action' => $action]));
        }
    } catch (Exception $e) {
        $connection->send(json_encode(['error' => $e->getMessage()]));
    }
};

$wsWorker->onClose = function (TcpConnection $connection) {
    echo "WebSocket 断开: {$connection->id}\n";
};

/**
 * 流式书籍问答
 */
function streamAsk(TcpConnection $connection, array $request): void
{
    $question = $request['question'] ?? '';
    $topK = $request['top_k'] ?? 8;
    
    if (empty($question)) {
        $connection->send(json_encode(['error' => 'Missing question']));
        return;
    }
    
    // 检索相关内容
    $embedder = new EmbeddingClient(GEMINI_API_KEY);
    $queryEmbedding = $embedder->embedQuery($question);
    
    $vectorStore = new VectorStore(DEFAULT_BOOK_CACHE);
    $results = $vectorStore->hybridSearch($question, $queryEmbedding, $topK, 0.6);
    
    // 发送检索结果
    $connection->send(json_encode([
        'type' => 'sources',
        'sources' => array_map(fn($r) => [
            'text' => mb_substr($r['chunk']['text'], 0, 200) . '...',
            'score' => round($r['score'] * 100, 1),
        ], $results),
    ]));
    
    // 构建上下文
    $context = "";
    foreach ($results as $i => $result) {
        $context .= "【片段 " . ($i + 1) . "】\n" . $result['chunk']['text'] . "\n\n";
    }
    
    // 流式生成回答
    $gemini = AIService::getGemini();
    $gemini->chatStream(
        [
            ['role' => 'system', 'content' => "你是一个书籍分析助手。根据以下内容回答问题，使用中文：\n\n{$context}"],
            ['role' => 'user', 'content' => $question],
        ],
        function ($text, $chunk, $isThought) use ($connection) {
            if (!$isThought && $text) {
                $connection->send(json_encode([
                    'type' => 'content',
                    'content' => $text,
                ]));
            }
        },
        ['enableSearch' => false]
    );
    
    $connection->send(json_encode(['type' => 'done']));
}

/**
 * 流式通用聊天
 */
function streamChat(TcpConnection $connection, array $request): void
{
    $messages = $request['messages'] ?? [];
    
    if (empty($messages)) {
        $connection->send(json_encode(['error' => 'Missing messages']));
        return;
    }
    
    $gemini = AIService::getGemini();
    $gemini->chatStream(
        $messages,
        function ($text, $chunk, $isThought) use ($connection) {
            if (!$isThought && $text) {
                $connection->send(json_encode([
                    'type' => 'content',
                    'content' => $text,
                ]));
            }
        },
        ['enableSearch' => false]
    );
    
    $connection->send(json_encode(['type' => 'done']));
}

/**
 * 流式续写章节
 */
function streamContinue(TcpConnection $connection, array $request): void
{
    $prompt = $request['prompt'] ?? '';
    
    $systemPrompt = <<<'EOT'
你是一位精通古典文学的作家，擅长模仿《西游记》的章回体小说风格写作。
请严格模仿《西游记》的写作风格特点。
EOT;

    $userPrompt = $prompt ?: '请为《西游记》续写一个新章节。设定：唐僧师徒四人遇到一个新的妖怪。写一个完整的章回，约1000字。';
    
    $gemini = AIService::getGemini();
    $gemini->chatStream(
        [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        function ($text, $chunk, $isThought) use ($connection) {
            if (!$isThought && $text) {
                $connection->send(json_encode([
                    'type' => 'content',
                    'content' => $text,
                ]));
            }
        },
        ['enableSearch' => false]
    );
    
    $connection->send(json_encode(['type' => 'done']));
}

// ===================================
// 启动服务
// ===================================

echo "=========================================\n";
echo "   AI 书籍助手 Workerman 服务\n";
echo "=========================================\n";
echo "\n";
echo "🌐 打开浏览器访问: http://localhost:8088\n";
echo "\n";
echo "=========================================\n";
echo "HTTP API:    http://localhost:8088/api\n";
echo "WebSocket:   ws://localhost:8081\n";
echo "=========================================\n";
echo "\n";
echo "API 端点:\n";
echo "  GET  /               - 聊天界面\n";
echo "  GET  /api            - API 列表\n";
echo "  GET  /api/health     - 健康检查 (含 Redis 状态)\n";
echo "  GET  /api/cache/stats- 缓存统计\n";
echo "  POST /api/ask        - 书籍问答 (带缓存)\n";
echo "  POST /api/chat       - 通用聊天\n";
echo "  POST /api/continue   - 续写章节\n";
echo "  POST /api/stream/*   - 流式端点 (SSE)\n";
echo "\n";
echo "📦 Redis 缓存: " . REDIS_HOST . ":" . REDIS_PORT . "\n";
echo "⏱️  缓存时长: " . CACHE_TTL . " 秒\n";
echo "\n";

Worker::runAll();
