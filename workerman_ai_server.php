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

// ===================================
// 配置
// ===================================

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

$httpWorker->onMessage = function (TcpConnection $connection, Request $request) {
    $path = $request->path();
    $method = $request->method();
    
    // CORS 头
    $headers = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type',
    ];
    
    // 处理 OPTIONS 预检请求
    if ($method === 'OPTIONS') {
        $connection->send(new Response(200, $headers, ''));
        return;
    }
    
    try {
        $result = match ($path) {
            '/' => ['status' => 'ok', 'message' => 'AI Book Assistant API', 'endpoints' => [
                'POST /api/ask' => '书籍问答 (RAG)',
                'POST /api/chat' => '通用聊天',
                'POST /api/continue' => '续写章节',
                'GET /api/health' => '健康检查',
            ]],
            '/api/health' => ['status' => 'ok', 'timestamp' => date('Y-m-d H:i:s')],
            '/api/ask' => handleAsk($request),
            '/api/chat' => handleChat($request),
            '/api/continue' => handleContinue($request),
            default => ['error' => 'Not Found', 'path' => $path],
        };
        
        $statusCode = isset($result['error']) ? 404 : 200;
        $connection->send(new Response($statusCode, $headers, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
        
    } catch (Exception $e) {
        $connection->send(new Response(500, $headers, json_encode([
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
echo "HTTP API:    http://localhost:8088\n";
echo "WebSocket:   ws://localhost:8081\n";
echo "=========================================\n";
echo "\n";
echo "API 端点:\n";
echo "  POST /api/ask      - 书籍问答 (RAG)\n";
echo "  POST /api/chat     - 通用聊天\n";
echo "  POST /api/continue - 续写章节\n";
echo "  GET  /api/health   - 健康检查\n";
echo "\n";
echo "WebSocket 操作:\n";
echo "  {\"action\": \"ask\", \"question\": \"问题\"}\n";
echo "  {\"action\": \"chat\", \"messages\": [...]}\n";
echo "  {\"action\": \"continue\", \"prompt\": \"提示\"}\n";
echo "\n";

Worker::runAll();
