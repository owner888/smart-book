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
// Redis 向量存储 (基于 Redis 8.0 vectorset)
// ===================================

class RedisVectorStore
{
    private static ?RedisClient $redis = null;
    private static string $vectorKey = 'smartbook:vectors';
    private static string $chunksKey = 'smartbook:chunks';
    private static int $dimension = 768;
    private static bool $initialized = false;
    
    /**
     * 初始化（在 Worker 启动时调用）
     */
    public static function init(RedisClient $redis): void
    {
        self::$redis = $redis;
        self::$initialized = true;
    }
    
    /**
     * 从 JSON 索引导入向量到 Redis
     */
    public static function importFromJson(string $jsonPath, ?callable $onProgress = null): void
    {
        if (!self::$redis || !file_exists($jsonPath)) {
            return;
        }
        
        $data = json_decode(file_get_contents($jsonPath), true);
        if (!$data || empty($data['chunks'])) {
            return;
        }
        
        $total = count($data['chunks']);
        $imported = 0;
        
        foreach ($data['chunks'] as $i => $chunk) {
            $chunkId = "chunk:{$i}";
            
            // 存储文本内容（Hash）
            self::$redis->hSet(self::$chunksKey, $chunkId, json_encode([
                'text' => $chunk['text'],
                'index' => $i,
            ], JSON_UNESCAPED_UNICODE));
            
            // 存储向量（使用 VADD）
            if (!empty($chunk['embedding'])) {
                $embedding = $chunk['embedding'];
                // 构建 VADD 命令参数
                $args = [self::$vectorKey, $chunkId, 'VALUES'];
                foreach ($embedding as $val) {
                    $args[] = (string)$val;
                }
                
                // 使用 rawCommand 执行 VADD
                call_user_func_array([$_SERVER['REDIS_RAW'] ?? self::$redis, 'rawCommand'], 
                    array_merge(['VADD'], $args));
            }
            
            $imported++;
            if ($onProgress && $imported % 100 === 0) {
                $onProgress($imported, $total);
            }
        }
        
        echo "✅ 向量导入完成: {$imported}/{$total}\n";
    }
    
    /**
     * 检查是否已导入
     */
    public static function isImported(callable $callback): void
    {
        if (!self::$redis) {
            $callback(false, 0);
            return;
        }
        
        self::$redis->rawCommand('VCARD', self::$vectorKey, function($count) use ($callback) {
            $callback($count > 0, $count ?? 0);
        });
    }
    
    /**
     * 异步向量搜索
     */
    public static function search(array $queryVector, int $topK, callable $callback): void
    {
        if (!self::$redis) {
            $callback([]);
            return;
        }
        
        // 构建 VSIM 命令
        $args = ['VSIM', self::$vectorKey];
        foreach ($queryVector as $val) {
            $args[] = (string)$val;
        }
        $args[] = 'COUNT';
        $args[] = (string)$topK;
        
        // 执行向量搜索
        $cb = function($results) use ($callback) {
            if (!$results || !is_array($results)) {
                $callback([]);
                return;
            }
            
            // 解析结果并获取文本
            $chunkIds = [];
            for ($i = 0; $i < count($results); $i += 2) {
                $chunkIds[] = [
                    'id' => $results[$i],
                    'score' => $results[$i + 1] ?? 1.0,
                ];
            }
            
            // 获取文本内容
            self::getChunksText($chunkIds, $callback);
        };
        
        // 使用 call_user_func_array 调用 rawCommand
        $args[] = $cb;
        call_user_func_array([self::$redis, 'rawCommand'], $args);
    }
    
    /**
     * 获取 chunk 文本内容
     */
    private static function getChunksText(array $chunkIds, callable $callback): void
    {
        if (empty($chunkIds)) {
            $callback([]);
            return;
        }
        
        $results = [];
        $pending = count($chunkIds);
        
        foreach ($chunkIds as $item) {
            self::$redis->hGet(self::$chunksKey, $item['id'], function($data) use ($item, &$results, &$pending, $callback) {
                if ($data) {
                    $chunk = json_decode($data, true);
                    $results[] = [
                        'chunk' => $chunk,
                        'score' => floatval($item['score']),
                    ];
                }
                
                $pending--;
                if ($pending === 0) {
                    // 按相似度排序
                    usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
                    $callback($results);
                }
            });
        }
    }
    
    /**
     * 清除所有向量数据
     */
    public static function clear(): void
    {
        if (!self::$redis) {
            return;
        }
        
        self::$redis->del(self::$vectorKey);
        self::$redis->del(self::$chunksKey);
    }
    
    /**
     * 获取统计信息
     */
    public static function getStats(callable $callback): void
    {
        if (!self::$redis) {
            $callback(['initialized' => false]);
            return;
        }
        
        self::$redis->rawCommand('VCARD', self::$vectorKey, function($count) use ($callback) {
            $callback([
                'initialized' => true,
                'vector_count' => $count ?? 0,
            ]);
        });
    }
}

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
    
    /**
     * 获取所有语义缓存的向量
     */
    public static function getSemanticIndex(callable $callback): void
    {
        if (!self::$connected || !self::$redis) {
            $callback([]);
            return;
        }
        
        $indexKey = CACHE_PREFIX . 'semantic_index';
        self::$redis->get($indexKey, function($result) use ($callback) {
            if ($result) {
                $callback(json_decode($result, true) ?? []);
            } else {
                $callback([]);
            }
        });
    }
    
    /**
     * 添加到语义索引
     */
    public static function addToSemanticIndex(string $cacheKey, array $embedding, string $question): void
    {
        if (!self::$connected || !self::$redis) {
            return;
        }
        
        // 验证 embedding
        if (empty($embedding) || !is_array($embedding)) {
            echo "⚠️ 无效的 embedding，跳过添加到语义索引\n";
            return;
        }
        
        // 打印前3个维度用于验证
        $sample = array_slice($embedding, 0, 3);
        echo "📝 添加到语义索引: \"{$question}\" (dim: " . count($embedding) . ", sample: [" . implode(', ', array_map(fn($v) => round($v, 4), $sample)) . "...])\n";
        
        $indexKey = CACHE_PREFIX . 'semantic_index';
        self::$redis->get($indexKey, function($result) use ($indexKey, $cacheKey, $embedding, $question) {
            $index = $result ? (json_decode($result, true) ?? []) : [];
            
            // 添加新项（限制最多100个）
            // 注意：不要只存前几个维度，要存完整的 embedding
            $index[$cacheKey] = [
                'embedding' => $embedding,  // 完整的 embedding 数组
                'question' => $question,
            ];
            
            // 保持最多100个缓存项
            if (count($index) > 100) {
                $index = array_slice($index, -100, 100, true);
            }
            
            $json = json_encode($index);
            if ($json === false) {
                echo "⚠️ JSON 编码失败: " . json_last_error_msg() . "\n";
                return;
            }
            
            echo "📦 语义索引大小: " . strlen($json) . " bytes, 条目数: " . count($index) . "\n";
            self::$redis->setex($indexKey, CACHE_TTL * 2, $json);
        });
    }
    
    /**
     * 查找语义相似的缓存
     * @param float $threshold 相似度阈值，默认 0.96（96%），要求非常高的相似度才命中
     */
    public static function findSimilarCache(array $queryEmbedding, array $index, float $threshold = 0.96): ?array
    {
        // 检查 queryEmbedding 是否有效
        if (empty($queryEmbedding) || !is_array($queryEmbedding)) {
            echo "⚠️ 查询向量无效\n";
            return null;
        }
        
        $queryDim = count($queryEmbedding);
        $querySample = array_slice($queryEmbedding, 0, 3);
        echo "🔎 开始语义搜索，查询向量维度: {$queryDim}，sample: [" . implode(', ', array_map(fn($v) => round($v, 4), $querySample)) . "...]，索引数量: " . count($index) . "\n";
        
        $bestMatch = null;
        $bestScore = -1;
        $bestQuestion = '';
        
        foreach ($index as $cacheKey => $item) {
            // 确保 embedding 存在且为数组
            if (!isset($item['embedding']) || !is_array($item['embedding'])) {
                echo "⚠️ 跳过无效缓存项: {$cacheKey}\n";
                continue;
            }
            
            $itemDim = count($item['embedding']);
            
            // 确保嵌入向量维度匹配
            if ($queryDim !== $itemDim) {
                echo "⚠️ 维度不匹配: {$queryDim} vs {$itemDim} ({$item['question']})\n";
                continue;
            }
            
            $similarity = self::cosineSimilarity($queryEmbedding, $item['embedding']);
            
            // 调试日志
            echo "   📊 相似度: " . round($similarity * 100, 2) . "% - \"{$item['question']}\"\n";
            
            if ($similarity > $threshold && $similarity > $bestScore) {
                $bestScore = $similarity;
                $bestMatch = $cacheKey;
                $bestQuestion = $item['question'] ?? '';
            }
        }
        
        if ($bestMatch) {
            return [
                'key' => $bestMatch,
                'score' => $bestScore,
                'question' => $bestQuestion,
            ];
        }
        
        echo "   ❌ 没有找到相似度 > {$threshold} 的缓存\n";
        return null;
    }
    
    /**
     * 计算余弦相似度
     */
    private static function cosineSimilarity(array $a, array $b): float
    {
        $len = count($a);
        if ($len !== count($b) || $len === 0) {
            return 0.0;
        }
        
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        
        // 使用固定循环避免每次计算 count
        for ($i = 0; $i < $len; $i++) {
            $valA = (float)($a[$i] ?? 0);
            $valB = (float)($b[$i] ?? 0);
            
            $dotProduct += $valA * $valB;
            $normA += $valA * $valA;
            $normB += $valB * $valB;
        }
        
        $normA = sqrt($normA);
        $normB = sqrt($normB);
        
        // 避免除以零
        if ($normA < 1e-10 || $normB < 1e-10) {
            return 0.0;
        }
        
        return $dotProduct / ($normA * $normB);
    }
}

// ===================================
// AI 服务类
// ===================================

class AIService
{
    private static ?BookRAGAssistant $ragAssistant = null;
    private static ?GeminiClient $gemini = null;
    private static ?AsyncGeminiClient $asyncGemini = null;
    
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
    
    public static function getAsyncGemini(): AsyncGeminiClient
    {
        if (self::$asyncGemini === null) {
            self::$asyncGemini = new AsyncGeminiClient(GEMINI_API_KEY, AsyncGeminiClient::MODEL_GEMINI_25_FLASH);
        }
        return self::$asyncGemini;
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

// Worker 启动时初始化 Redis 和 AsyncCurlManager
$httpWorker->onWorkerStart = function ($worker) {
    try {
        CacheService::init();
        
        // 初始化 Redis 向量存储
        $redis = CacheService::getRedis();
        if ($redis) {
            RedisVectorStore::init($redis);
            
            // 只在 Worker 0 中检查是否需要导入向量
            if ($worker->id === 0) {
                RedisVectorStore::isImported(function($imported, $count) {
                    if (!$imported && file_exists(DEFAULT_BOOK_CACHE)) {
                        echo "📥 正在导入向量到 Redis...\n";
                        // 注意：导入是同步的，会阻塞启动
                        // RedisVectorStore::importFromJson(DEFAULT_BOOK_CACHE);
                        echo "💡 提示: 访问 /api/vectors/import 来导入向量\n";
                    } else {
                        echo "📊 Redis 向量数量: {$count}\n";
                    }
                });
            }
        }
    } catch (Exception $e) {
        echo "⚠️  Redis 连接失败: {$e->getMessage()}\n";
        echo "   服务将在无缓存模式下运行\n";
    }
    
    // 初始化异步 curl 管理器
    AsyncCurlManager::init();
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
        
        // 静态文件处理
        if (str_starts_with($path, '/static/')) {
            $filePath = __DIR__ . $path;
            if (file_exists($filePath)) {
                $ext = pathinfo($filePath, PATHINFO_EXTENSION);
                $mimeTypes = [
                    'css' => 'text/css',
                    'js' => 'application/javascript',
                    'png' => 'image/png',
                    'jpg' => 'image/jpeg',
                    'gif' => 'image/gif',
                    'svg' => 'image/svg+xml',
                    'ico' => 'image/x-icon',
                    'woff' => 'font/woff',
                    'woff2' => 'font/woff2',
                ];
                $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
                $connection->send(new Response(200, [
                    'Content-Type' => $contentType,
                    'Cache-Control' => 'max-age=86400',
                ], file_get_contents($filePath)));
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
            '/api/vectors/stats' => handleVectorStats($connection),
            '/api/vectors/import' => handleVectorImport($connection),
            '/api/ask' => handleAskWithCache($connection, $request),
            '/api/chat' => handleChat($request),
            '/api/continue' => handleContinue($request),
            '/api/stream/ask' => AsyncHandleStreamAsk($connection, $request),
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

/**
 * 向量统计
 */
function handleVectorStats(TcpConnection $connection): ?array
{
    $jsonHeaders = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin' => '*',
    ];
    
    RedisVectorStore::getStats(function($stats) use ($connection, $jsonHeaders) {
        $connection->send(new Response(200, $jsonHeaders, json_encode($stats, JSON_UNESCAPED_UNICODE)));
    });
    
    return null;
}

/**
 * 导入向量到 Redis
 */
function handleVectorImport(TcpConnection $connection): ?array
{
    $jsonHeaders = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin' => '*',
    ];
    
    if (!file_exists(DEFAULT_BOOK_CACHE)) {
        $connection->send(new Response(404, $jsonHeaders, json_encode([
            'error' => 'Index file not found',
            'path' => DEFAULT_BOOK_CACHE,
        ], JSON_UNESCAPED_UNICODE)));
        return null;
    }
    
    // 同步导入（会阻塞）
    try {
        $data = json_decode(file_get_contents(DEFAULT_BOOK_CACHE), true);
        $total = count($data['chunks'] ?? []);
        
        RedisVectorStore::importFromJson(DEFAULT_BOOK_CACHE, function($imported, $total) {
            echo "📥 导入进度: {$imported}/{$total}\n";
        });
        
        $connection->send(new Response(200, $jsonHeaders, json_encode([
            'success' => true,
            'message' => '向量导入完成',
            'total' => $total,
        ], JSON_UNESCAPED_UNICODE)));
    } catch (Exception $e) {
        $connection->send(new Response(500, $jsonHeaders, json_encode([
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE)));
    }
    
    return null;
}

// ===================================
// SSE 流式端点
// ===================================

// 同步发送 SSE 消息
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
    
    $cacheKey = CacheService::makeKey('stream_ask', $question . ':' . $topK);
    
    // 尝试从缓存获取
    CacheService::get($cacheKey, function($cached) use ($connection, $question, $topK, $cacheKey, $headers) {
        // 发送 SSE 头
        $connection->send(new Response(200, $headers, ''));
        
        try {
            if ($cached) {
                // 缓存命中：发送缓存的来源和回答
                sendSSE($connection, 'sources', json_encode($cached['sources'], JSON_UNESCAPED_UNICODE));
                sendSSE($connection, 'cached', 'true');
                sendSSE($connection, 'content', $cached['answer']);
                sendSSE($connection, 'done', '');
                $connection->close();
                return;
            }
            
            // 缓存未命中：执行检索和生成
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
            
            // 流式生成回答，同时收集完整内容用于缓存
            $fullAnswer = '';
            $gemini = AIService::getGemini();
            $gemini->chatStream(
                [
                    ['role' => 'system', 'content' => "你是一个书籍分析助手。根据以下内容回答问题，使用中文：\n\n{$context}"],
                    ['role' => 'user', 'content' => $question],
                ],
                function ($text, $chunk, $isThought) use ($connection, &$fullAnswer) {
                    if (!$isThought && $text) {
                        $fullAnswer .= $text;
                        sendSSE($connection, 'content', $text);
                    }
                },
                ['enableSearch' => false]
            );
            
            // 保存到缓存
            CacheService::set($cacheKey, [
                'sources' => $sources,
                'answer' => $fullAnswer,
            ]);
            
            sendSSE($connection, 'done', '');
        } catch (Exception $e) {
            // 发送错误信息给客户端，而不是让 worker 崩溃
            $errorMsg = $e->getMessage();
            echo "⚠️ API 错误: {$errorMsg}\n";
            
            // 确保连接仍然有效
            if ($connection->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
                sendSSE($connection, 'error', $errorMsg);
                sendSSE($connection, 'done', '');
            } else {
                echo "⚠️ 连接已关闭，无法发送错误信息\n";
            }
        }
        
        // 确保连接关闭
        if ($connection->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
            $connection->close();
        }
    });
    
    return null;
}

// 异步发送 SSE 消息
function AsyncHandleStreamAsk(TcpConnection $connection, Request $request): ?array
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
    
    // 获取语义索引，实现语义缓存
    CacheService::getSemanticIndex(function($semanticIndex) use ($connection, $question, $topK, $headers) {
        // 发送 SSE 头
        $connection->send(new Response(200, $headers, ''));
        
        // 生成问题的嵌入向量
        $embedder = new EmbeddingClient(GEMINI_API_KEY);
        $queryEmbedding = $embedder->embedQuery($question);
        
        // 查找语义相似的缓存（相似度 > 96%）
        $similar = CacheService::findSimilarCache($queryEmbedding, $semanticIndex, 0.96);
        
        if ($similar) {
            // 找到语义相似的缓存，获取缓存内容
            $cacheKey = $similar['key'];
            $originalQuestion = $similar['question'];
            $matchScore = round($similar['score'] * 100, 1);
            
            echo "🎯 语义缓存命中 ({$matchScore}%): \"{$question}\" ≈ \"{$originalQuestion}\"\n";
            
            CacheService::get($cacheKey, function($cached) use ($connection, $originalQuestion, $matchScore, $question, $queryEmbedding, $topK) {
                if ($cached) {
                    sendSSE($connection, 'sources', json_encode($cached['sources'], JSON_UNESCAPED_UNICODE));
                    sendSSE($connection, 'cached', json_encode([
                        'hit' => true,
                        'original_question' => $originalQuestion,
                        'similarity' => $matchScore,
                    ], JSON_UNESCAPED_UNICODE));
                    sendSSE($connection, 'content', $cached['answer']);
                    sendSSE($connection, 'done', '');
                    $connection->close();
                    return;
                }
                // 缓存不存在（已过期），继续正常处理
                handleStreamAskGenerate($connection, $question, $queryEmbedding, $topK);
            });
            return;
        }
        
        // 没有相似缓存，执行正常处理
        handleStreamAskGenerate($connection, $question, $queryEmbedding, $topK);
    });
    
    return null;
}

/**
 * 执行检索和生成回答（内部函数）- 异步版本
 */
function handleStreamAskGenerate(TcpConnection $connection, string $question, array $queryEmbedding, int $topK): void
{
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
    
    // 异步流式生成回答
    $asyncGemini = AIService::getAsyncGemini();
    $asyncGemini->chatStreamAsync(
        [
            ['role' => 'system', 'content' => "你是一个书籍分析助手。根据以下内容回答问题，使用中文：\n\n{$context}"],
            ['role' => 'user', 'content' => $question],
        ],
        // onChunk: 每个 token 回调
        function ($text, $isThought) use ($connection) {
            if (!$isThought && $text) {
                sendSSE($connection, 'content', $text);
            }
        },
        // onComplete: 完成回调
        function ($fullAnswer) use ($connection, $question, $queryEmbedding, $topK, $sources) {
            // 生成缓存键并保存
            $cacheKey = CacheService::makeKey('stream_ask', $question . ':' . $topK);
            CacheService::set($cacheKey, [
                'sources' => $sources,
                'answer' => $fullAnswer,
            ]);
            
            // 添加到语义索引（用于语义缓存匹配）
            CacheService::addToSemanticIndex($cacheKey, $queryEmbedding, $question);
            
            sendSSE($connection, 'done', '');
            $connection->close();
        },
        // onError: 错误回调
        function ($error) use ($connection) {
            sendSSE($connection, 'error', $error);
            $connection->close();
        },
        ['enableSearch' => false]
    );
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
    
    // 使用异步版本
    $asyncGemini = AIService::getAsyncGemini();
    $asyncGemini->chatStreamAsync(
        $messages,
        function ($text, $isThought) use ($connection) {
            if (!$isThought && $text) {
                sendSSE($connection, 'content', $text);
            }
        },
        function ($fullContent) use ($connection) {
            sendSSE($connection, 'done', '');
            $connection->close();
        },
        function ($error) use ($connection) {
            sendSSE($connection, 'error', $error);
            $connection->close();
        },
        ['enableSearch' => false]
    );
    
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
    
    // 使用异步版本
    $asyncGemini = AIService::getAsyncGemini();
    $asyncGemini->chatStreamAsync(
        [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        function ($text, $isThought) use ($connection) {
            if (!$isThought && $text) {
                sendSSE($connection, 'content', $text);
            }
        },
        function ($fullContent) use ($connection) {
            sendSSE($connection, 'done', '');
            $connection->close();
        },
        function ($error) use ($connection) {
            sendSSE($connection, 'error', $error);
            $connection->close();
        },
        ['enableSearch' => false]
    );
    
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
