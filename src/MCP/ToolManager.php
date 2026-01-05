<?php
/**
 * MCP 工具管理器
 * 从 config/mcp.json 加载配置（兼容 Cline 格式）
 */

namespace SmartBook\MCP;

class ToolManager
{
    private static array $tools = [];
    private static array $handlers = [];
    private static array $autoApprove = [];
    private static string $configPath = '';
    
    /**
     * 注册工具
     */
    public static function register(string $name, array $definition, callable $handler, bool $autoApprove = false): void
    {
        self::$tools[$name] = $definition;
        self::$handlers[$name] = $handler;
        if ($autoApprove) {
            self::$autoApprove[] = $name;
        }
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
     * 是否自动批准
     */
    public static function isAutoApprove(string $name): bool
    {
        return in_array($name, self::$autoApprove);
    }
    
    /**
     * 加载配置文件
     */
    public static function loadConfig(): array
    {
        self::$configPath = dirname(__DIR__, 2) . '/config/mcp.json';
        
        if (!file_exists(self::$configPath)) {
            return ['mcpServers' => []];
        }
        
        $content = file_get_contents(self::$configPath);
        return json_decode($content, true) ?? ['mcpServers' => []];
    }
    
    /**
     * 从 JSON 配置初始化工具（兼容 Cline 格式）
     */
    public static function initDefaultTools(): void
    {
        $config = self::loadConfig();
        $enabledTools = [];
        $builtinHandlers = self::getBuiltinHandlers();
        
        foreach ($config['mcpServers'] ?? [] as $serverName => $serverConfig) {
            // 跳过禁用的服务器
            if ($serverConfig['disabled'] ?? false) {
                continue;
            }
            
            $autoApproveList = $serverConfig['autoApprove'] ?? [];
            
            // 处理内置工具服务器
            if (($serverConfig['command'] ?? '') === 'php' && ($serverConfig['args'][0] ?? '') === 'builtin') {
                foreach ($serverConfig['tools'] ?? [] as $toolName => $toolDef) {
                    if (isset($builtinHandlers[$toolName])) {
                        $isAutoApprove = in_array($toolName, $autoApproveList);
                        self::register($toolName, $toolDef, $builtinHandlers[$toolName], $isAutoApprove);
                        $enabledTools[] = $toolName;
                    }
                }
            }
            // TODO: 支持外部 MCP 服务器（通过 stdio 协议）
        }
        
        if (!empty($enabledTools)) {
            echo "📦 MCP 工具已加载: " . implode(', ', $enabledTools) . "\n";
        }
    }
    
    /**
     * 获取内置工具处理器
     */
    private static function getBuiltinHandlers(): array
    {
        return [
            'get_current_time' => function($args) {
                $tz = $args['timezone'] ?? 'Asia/Shanghai';
                $dt = new \DateTime('now', new \DateTimeZone($tz));
                return [
                    'datetime' => $dt->format('Y-m-d H:i:s'),
                    'timezone' => $tz,
                    'timestamp' => $dt->getTimestamp(),
                ];
            },
            
            'calculator' => function($args) {
                $expr = $args['expression'] ?? '';
                if (!preg_match('/^[\d\s\+\-\*\/\(\)\.]+$/', $expr)) {
                    throw new \Exception('Invalid expression');
                }
                $result = eval("return {$expr};");
                return ['expression' => $expr, 'result' => $result];
            },
            
            'fetch_webpage' => function($args) {
                $url = $args['url'] ?? '';
                $maxLength = $args['max_length'] ?? 5000;
                
                if (empty($url)) throw new \Exception('URL is required');
                
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SmartBook/1.0)',
                ]);
                $html = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
                if ($httpCode !== 200) throw new \Exception("HTTP {$httpCode}");
                
                $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
                $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $text);
                $text = strip_tags($text);
                $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = preg_replace('/\s+/', ' ', $text);
                $text = trim(mb_substr($text, 0, $maxLength));
                
                return ['url' => $url, 'content' => $text, 'length' => mb_strlen($text)];
            },
            
            'search_book' => function($args) {
                $query = $args['query'] ?? '';
                $topK = $args['top_k'] ?? 5;
                
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
            },
        ];
    }
}
