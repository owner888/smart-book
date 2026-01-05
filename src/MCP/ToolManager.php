<?php
/**
 * MCP 工具管理器
 * 管理工具定义和执行
 */

namespace SmartBook\MCP;

class ToolManager
{
    private static array $tools = [];
    private static array $handlers = [];
    
    /**
     * 注册工具
     */
    public static function register(string $name, array $definition, callable $handler): void
    {
        self::$tools[$name] = $definition;
        self::$handlers[$name] = $handler;
    }
    
    /**
     * 获取所有工具定义（Gemini function_declarations 格式）
     */
    public static function getToolDefinitions(): array
    {
        $declarations = [];
        foreach (self::$tools as $name => $def) {
            $declarations[] = [
                'name' => $name,
                'description' => $def['description'] ?? '',
                'parameters' => $def['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }
        return $declarations;
    }
    
    /**
     * 执行工具
     */
    public static function execute(string $name, array $args = []): array
    {
        if (!isset(self::$handlers[$name])) {
            return ['error' => "Unknown tool: {$name}"];
        }
        
        try {
            $result = call_user_func(self::$handlers[$name], $args);
            return ['success' => true, 'result' => $result];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * 检查工具是否存在
     */
    public static function has(string $name): bool
    {
        return isset(self::$handlers[$name]);
    }
    
    /**
     * 获取工具列表
     */
    public static function list(): array
    {
        return array_keys(self::$tools);
    }
    
    /**
     * 初始化默认工具
     */
    public static function initDefaultTools(): void
    {
        // 1. 获取当前时间
        self::register('get_current_time', [
            'description' => '获取当前日期和时间',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'timezone' => [
                        'type' => 'string',
                        'description' => '时区，如 Asia/Shanghai',
                    ],
                ],
            ],
        ], function($args) {
            $tz = $args['timezone'] ?? 'Asia/Shanghai';
            $dt = new \DateTime('now', new \DateTimeZone($tz));
            return [
                'datetime' => $dt->format('Y-m-d H:i:s'),
                'timezone' => $tz,
                'timestamp' => $dt->getTimestamp(),
            ];
        });
        
        // 2. 计算器
        self::register('calculator', [
            'description' => '执行数学计算',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'expression' => [
                        'type' => 'string',
                        'description' => '数学表达式，如 2+3*4',
                    ],
                ],
                'required' => ['expression'],
            ],
        ], function($args) {
            $expr = $args['expression'] ?? '';
            // 安全计算（只允许数字和基本运算符）
            if (!preg_match('/^[\d\s\+\-\*\/\(\)\.]+$/', $expr)) {
                throw new \Exception('Invalid expression');
            }
            $result = eval("return {$expr};");
            return ['expression' => $expr, 'result' => $result];
        });
        
        // 3. 网页抓取
        self::register('fetch_webpage', [
            'description' => '抓取网页内容（返回纯文本）',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => '要抓取的网页 URL',
                    ],
                    'max_length' => [
                        'type' => 'integer',
                        'description' => '返回的最大字符数，默认 5000',
                    ],
                ],
                'required' => ['url'],
            ],
        ], function($args) {
            $url = $args['url'] ?? '';
            $maxLength = $args['max_length'] ?? 5000;
            
            if (empty($url)) {
                throw new \Exception('URL is required');
            }
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SmartBook/1.0)',
            ]);
            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($httpCode !== 200) {
                throw new \Exception("HTTP {$httpCode}");
            }
            
            // 提取文本内容
            // 1. 移除 script, style, noscript 标签及其内容
            $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
            $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $text);
            $text = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $text);
            
            // 2. 移除 HTML 注释
            $text = preg_replace('/<!--.*?-->/s', '', $text);
            
            // 3. 移除所有标签
            $text = strip_tags($text);
            
            // 4. 解码 HTML 实体
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            // 5. 清理空白
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim(mb_substr($text, 0, $maxLength));
            
            return ['url' => $url, 'content' => $text, 'length' => mb_strlen($text)];
        });
        
        // 4. 书籍搜索（RAG）
        self::register('search_book', [
            'description' => '在《西游记》书籍中搜索相关内容',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => '搜索关键词或问题',
                    ],
                    'top_k' => [
                        'type' => 'integer',
                        'description' => '返回结果数量，默认 5',
                    ],
                ],
                'required' => ['query'],
            ],
        ], function($args) {
            $query = $args['query'] ?? '';
            $topK = $args['top_k'] ?? 5;
            
            // 使用 RAG 搜索
            if (!file_exists(DEFAULT_BOOK_CACHE)) {
                throw new \Exception('Book index not found');
            }
            
            $embedder = new \SmartBook\RAG\EmbeddingClient(GEMINI_API_KEY);
            $queryEmbedding = $embedder->embedQuery($query);
            
            $vectorStore = new \SmartBook\RAG\VectorStore(DEFAULT_BOOK_CACHE);
            $results = $vectorStore->hybridSearch($query, $queryEmbedding, $topK, 0.5);
            
            $chunks = [];
            foreach ($results as $r) {
                $chunks[] = [
                    'text' => mb_substr($r['chunk']['text'], 0, 500),
                    'score' => round($r['score'] * 100, 1),
                ];
            }
            
            return ['query' => $query, 'results' => $chunks, 'count' => count($chunks)];
        });
        
        echo "📦 MCP 工具已注册: " . implode(', ', self::list()) . "\n";
    }
}
