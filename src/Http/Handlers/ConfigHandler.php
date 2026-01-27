<?php
/**
 * 配置和基础信息处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\AI\AIService;
use SmartBook\Parser\EpubParser;

class ConfigHandler
{
    /**
     * 获取服务器配置信息
     */
    public static function getConfig(): array
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
     * 获取可用模型列表
       {
            "name": "models\/gemini-2.5-pro",
            "version": "2.5",
            "displayName": "Gemini 2.5 Pro",
            "description": "Stable release (June 17th, 2025) of Gemini 2.5 Pro",
            "inputTokenLimit": 1048576,
            "outputTokenLimit": 65536,
            "supportedGenerationMethods": [
                "generateContent",
                "countTokens",
                "createCachedContent",
                "batchGenerateContent"
            ],
            "temperature": 1,
            "topP": 0.95,
            "topK": 64,
            "maxTemperature": 2,
            "thinking": true
        }
     */
    public static function getModels(): array
    {
        static $cache = null;
        static $cacheTime = 0;
        
        if ($cache && (time() - $cacheTime) < 300) {
            return $cache;
        }
        
        $models = [];
        $default = 'gemini-2.0-flash';  // 默认使用免费的Auto模型
        
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
                
                // 价格表（美元/百万 tokens）- 2025年1月更新
                $pricing = [
                    // Gemini 3.0 系列
                    'gemini-3-pro-preview' => ['input' => 2.5, 'output' => 15],
                    'gemini-3-flash-preview' => ['input' => 0.3, 'output' => 2.5],

                    // Gemini 2.5 系列
                    'gemini-2.5-pro' => ['input' => 2.5, 'output' => 15],
                    'gemini-2.5-flash' => ['input' => 0.3, 'output' => 2.5],
                    'gemini-2.5-flash-lite' => ['input' => 0.1, 'output' => 0.4],
                    
                    // Gemini 2.0 系列
                    'gemini-2.0-flash' => ['input' => 0, 'output' => 0],
                    'gemini-2.0-flash-001' => ['input' => 0, 'output' => 0],
                    'gemini-2.0-flash-lite' => ['input' => 0, 'output' => 0],
                    'gemini-2.0-flash-lite-001' => ['input' => 0, 'output' => 0],
                ];
                
                // 首先添加固定的4个模型（用于iOS的Heavy/Expert/Fast/Auto按钮）
                $fixedModels = [
                    'gemini-2.5-pro',           // Heavy
                    'gemini-2.5-flash',         // Expert
                    'gemini-2.5-flash-lite',    // Fast
                    'gemini-2.0-flash',         // Auto
                ];
                
                foreach ($data['models'] ?? [] as $model) {
                    $modelId = str_replace('models/', '', $model['name']);
                    
                    // 只保留固定的4个模型 + Gemini 3 系列
                    $isFixed = in_array($modelId, $fixedModels);
                    $isGemini3 = in_array($modelId, ['gemini-3-pro-preview', 'gemini-3-flash-preview']);
                    
                    if (!$isFixed && !$isGemini3) {
                        continue;
                    }
                    
                    $basePrice = $pricing['gemini-2.5-pro']['output'];
                    $modelPrice = $pricing[$modelId]['output'] ?? 2.5;
                    $rate = $modelPrice == 0 ? '0x' : round($modelPrice / $basePrice, 2) . 'x';
                    
                    // 获取定价信息
                    $inputPrice = $pricing[$modelId]['input'] ?? null;
                    $outputPrice = $pricing[$modelId]['output'] ?? null;
                    $maxTokens = $model['inputTokenLimit'] ?? 0;
                    
                    // 构建简洁的描述信息（上下文 + 价格）
                    $descParts = [];
                    
                    // 上下文大小
                    if ($maxTokens > 0) {
                        $tokenDisplay = $maxTokens >= 1000000 
                            ? round($maxTokens / 1000000, 1) . 'M' 
                            : round($maxTokens / 1000) . 'K';
                        $descParts[] = "{$tokenDisplay} tokens";
                    }
                    
                    // 价格
                    if ($inputPrice !== null && $outputPrice !== null) {
                        if ($inputPrice == 0 && $outputPrice == 0) {
                            $descParts[] = 'Free';
                        } else {
                            $descParts[] = "\${$inputPrice}/\${$outputPrice}";
                        }
                    }
                    
                    $description = implode(' • ', $descParts);
                    
                    $models[] = [
                        'id' => $modelId,
                        'name' => $model['displayName'] ?? $modelId,
                        'provider' => 'google',
                        'rate' => $rate,
                        'description' => $description ?: 'Rate: ' . $rate,
                        'max_tokens' => $maxTokens,
                        'cost_per_1m_input' => $inputPrice,
                        'cost_per_1m_output' => $outputPrice,
                    ];
                }
            }
        } catch (\Exception $e) {
            // Fallback
        }
        
        if (empty($models)) {
            $models = [
                [
                    'id' => 'gemini-2.5-flash',
                    'name' => 'Gemini 2.5 Flash',
                    'provider' => 'google',
                    'rate' => '0.33x',
                    'description' => 'Fast and efficient model',
                    'max_tokens' => 1000000,
                    'cost_per_1m_input' => 0.3,
                    'cost_per_1m_output' => 2.5,
                ],
                [
                    'id' => 'gemini-2.5-pro',
                    'name' => 'Gemini 2.5 Pro',
                    'provider' => 'google',
                    'rate' => '1x',
                    'description' => 'Advanced reasoning model',
                    'max_tokens' => 2000000,
                    'cost_per_1m_input' => 2.5,
                    'cost_per_1m_output' => 15.0,
                ],
            ];
        }
        
        $cache = ['list' => $models, 'default' => $default, 'source' => 'gemini_api'];
        $cacheTime = time();
        
        return $cache;
    }
    
    /**
     * 获取所有助手配置
     */
    public static function getAssistants(): array
    {
        $prompts = $GLOBALS['config']['prompts'];
        $libraryPrompts = $prompts['library'];
        
        $bookTitle = $prompts['defaults']['unknown_book'] ?? '未知书籍';
        $bookAuthors = $prompts['defaults']['unknown_author'] ?? '未知作者';
        
        $currentBookPath = self::getCurrentBookPath();
        if ($currentBookPath) {
            $ext = strtolower(pathinfo($currentBookPath, PATHINFO_EXTENSION));
            if ($ext === 'epub') {
                $metadata = EpubParser::extractMetadata($currentBookPath);
                if (!empty($metadata['title'])) {
                    $bookTitle = '《' . $metadata['title'] . '》';
                }
                if (!empty($metadata['authors'])) {
                    $bookAuthors = $metadata['authors'];
                }
            } else {
                $bookTitle = '《' . pathinfo($currentBookPath, PATHINFO_FILENAME) . '》';
            }
        }
        
        // 从配置读取助手列表
        $assistantConfigs = ['chat', 'ask', 'continue'];
        $assistants = [];
        
        foreach ($assistantConfigs as $assistantId) {
            if (!isset($prompts[$assistantId])) continue;
            
            $config = $prompts[$assistantId];
            
            // 替换变量
            $description = str_replace('{title}', $bookTitle, $config['description'] ?? '');
            
            // 构建系统提示词
            $systemPrompt = $config['system_prompt'] ?? '';
            
            if ($assistantId === 'ask') {
                // 书籍问答：system_prompt 是模板，需要动态构建
                $languageInstruction = str_replace('{language}', $prompts['language']['default'], $prompts['language']['instruction']);
                
                $systemPrompt = str_replace(
                    ['{book_intro}', '{book_template}', '{separator}', '{markdown_instruction}', '{unknown_single}', '{language_instruction}'],
                    [
                        $libraryPrompts['book_intro'],
                        str_replace(['{which}', '{title}', '{authors}'], ['', $bookTitle, $bookAuthors], $libraryPrompts['book_template']),
                        $libraryPrompts['separator'],
                        $libraryPrompts['markdown_instruction'],
                        $libraryPrompts['unknown_single'] ?? '',
                        $languageInstruction
                    ],
                    $systemPrompt
                );
            } elseif ($assistantId === 'continue') {
                // 续写助手：替换变量 {title}
                $systemPrompt = str_replace('{title}', $bookTitle, $systemPrompt);
            }
            // chat 助手：不需要处理（system_prompt 为空字符串）
            
            $assistants[] = [
                'id' => $assistantId,
                'name' => $config['name'] ?? ucfirst($assistantId),
                'avatar' => $config['avatar'] ?? '🤖',
                'color' => $config['color'] ?? '#2196f3',
                'description' => $description,
                'system_prompt' => $systemPrompt,
                'action' => $config['action'] ?? 'chat',
            ];
        }
        
        return ['list' => $assistants, 'default' => 'chat'];
    }
    
    /**
     * 获取当前选中的书籍路径
     */
    public static function getCurrentBookPath(): ?string
    {
        if (isset($GLOBALS['selected_book']['path']) && file_exists($GLOBALS['selected_book']['path'])) {
            return $GLOBALS['selected_book']['path'];
        }
        if (defined('DEFAULT_BOOK_PATH') && file_exists(DEFAULT_BOOK_PATH)) {
            return DEFAULT_BOOK_PATH;
        }
        return null;
    }
    
    /**
     * 获取当前选中的书籍索引路径
     */
    public static function getCurrentBookCache(): ?string
    {
        if (isset($GLOBALS['selected_book']['cache']) && file_exists($GLOBALS['selected_book']['cache'])) {
            return $GLOBALS['selected_book']['cache'];
        }
        if (defined('DEFAULT_BOOK_CACHE') && file_exists(DEFAULT_BOOK_CACHE)) {
            return DEFAULT_BOOK_CACHE;
        }
        return null;
    }
}
