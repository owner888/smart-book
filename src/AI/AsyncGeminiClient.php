<?php
/**
 * 异步 Gemini 客户端（使用 curl_multi）
 * 支持 Function Calling / MCP 工具
 */

namespace SmartBook\AI;

use SmartBook\MCP\ToolManager;

class AsyncGeminiClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    
    const MODEL_GEMINI_25_PRO = 'gemini-2.5-pro';
    const MODEL_GEMINI_25_FLASH = 'gemini-2.5-flash';
    const MODEL_GEMINI_25_FLASH_LITE = 'gemini-2.5-flash-lite';
    
    public function __construct(
        string $apiKey,
        string $model = self::MODEL_GEMINI_25_FLASH,
        string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta'
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    /**
     * 流式聊天（支持 Function Calling）
     */
    public function chatStreamAsync(
        array $messages,
        callable $onChunk,
        callable $onComplete,
        ?callable $onError = null,
        array $options = []
    ): string {
        $model = $options['model'] ?? $this->model;
        $data = $this->buildRequestData($messages, $options);
        $url = "{$this->baseUrl}/models/{$model}:streamGenerateContent?alt=sse&key={$this->apiKey}";
        
        $fullContent = '';
        $buffer = '';
        $functionCalls = [];
        $usageMetadata = null;
        $onToolCall = $options['onToolCall'] ?? null;
        $onUsage = $options['onUsage'] ?? null;
        
        $onData = function($rawData) use (&$fullContent, &$buffer, &$functionCalls, &$usageMetadata, $onChunk, $onToolCall, $onUsage) {
            $buffer .= $rawData;
            
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                
                if (empty($line) || !str_starts_with($line, 'data: ')) continue;
                
                $jsonStr = substr($line, 6);
                $chunk = json_decode($jsonStr, true);
                
                if ($chunk === null && json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }
                
                if (isset($chunk['error'])) {
                    continue;
                }
                
                if (!$chunk || !isset($chunk['candidates'])) {
                    continue;
                }
                
                // 提取 usageMetadata
                if (isset($chunk['usageMetadata'])) {
                    $usageMetadata = $chunk['usageMetadata'];
                    if ($onUsage) {
                        $onUsage($usageMetadata);
                    }
                }
                
                foreach ($chunk['candidates'] as $candidate) {
                    foreach ($candidate['content']['parts'] ?? [] as $part) {
                        // 处理文本
                        if (isset($part['text'])) {
                            $text = $part['text'];
                            $isThought = $part['thought'] ?? false;
                            if ($text) {
                                if (!$isThought) $fullContent .= $text;
                                $onChunk($text, $isThought);
                            }
                        }
                        
                        // 处理 Function Call
                        if (isset($part['functionCall'])) {
                            $fc = $part['functionCall'];
                            $functionCalls[] = [
                                'name' => $fc['name'],
                                'args' => $fc['args'] ?? [],
                            ];
                            // 通知前端有工具调用
                            if ($onToolCall) {
                                $onToolCall($fc['name'], $fc['args'] ?? []);
                            }
                        }
                    }
                }
            }
        };
        
        $onFinish = function($success, $error) use (&$fullContent, &$functionCalls, &$usageMetadata, $onComplete, $onError, $messages, $options, $onChunk, $model) {
            if (!$success) {
                $onError ? $onError($error) : null;
                return;
            }
            
            // 如果有 function calls，执行它们
            if (!empty($functionCalls)) {
                $this->handleFunctionCalls($functionCalls, $messages, $fullContent, $onChunk, $onComplete, $onError, $options);
            } else {
                // 返回完整内容和使用统计
                $onComplete($fullContent, $usageMetadata, $model);
            }
        };
        
        return AsyncCurlManager::request(
            $url,
            [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data), CURLOPT_HTTPHEADER => ['Content-Type: application/json']],
            $onData,
            $onFinish
        );
    }
    
    /**
     * 处理 Function Calls
     */
    private function handleFunctionCalls(
        array $functionCalls,
        array $originalMessages,
        string $currentContent,
        callable $onChunk,
        callable $onComplete,
        ?callable $onError,
        array $options
    ): void {
        $functionResponses = [];
        $model = $options['model'] ?? $this->model;
        
        foreach ($functionCalls as $fc) {
            $name = $fc['name'];
            $args = $fc['args'];
            
            // 通知前端工具开始执行
            $onChunk("\n> 🔧 执行工具: `{$name}`\n", false);
            
            // 执行工具
            try {
                $result = ToolManager::execute($name, $args);
                
                $functionResponses[] = [
                    'name' => $name,
                    'args' => $args,
                    'result' => $result,
                ];
                
                // 显示执行结果简要信息
                if (isset($result['error'])) {
                    $onChunk("> ❌ 工具执行失败: {$result['error']}\n\n", false);
                } else {
                    $onChunk("> ✅ 工具执行成功\n\n", false);
                }
            } catch (\Exception $e) {
                $onChunk("> ❌ 工具异常: {$e->getMessage()}\n\n", false);
                $functionResponses[] = [
                    'name' => $name,
                    'args' => $args,
                    'result' => ['error' => $e->getMessage()],
                ];
            }
        }
        
        // 构建包含工具结果的新消息，让 AI 进行分析总结
        $newMessages = $originalMessages;
        
        // 添加 AI 的 function call
        $newMessages[] = [
            'role' => 'assistant',
            'function_calls' => $functionCalls,
        ];
        
        // 添加工具执行结果
        foreach ($functionResponses as $fr) {
            $responseContent = $fr['result']['result'] ?? $fr['result'];
            $newMessages[] = [
                'role' => 'function',
                'name' => $fr['name'],
                'content' => json_encode($responseContent, JSON_UNESCAPED_UNICODE),
            ];
        }
        
        // 继续对话让 AI 基于工具结果生成回复
        $options['enableTools'] = false; // 避免无限循环
        $options['includeThoughts'] = false;
        
        $this->chatStreamAsync(
            $newMessages,
            $onChunk,
            function($finalContent, $usageMetadata = null, $usedModel = null) use ($currentContent, $onComplete, $model) {
                // 传递完整参数
                $onComplete($currentContent . $finalContent, $usageMetadata, $usedModel ?? $model);
            },
            function($error) use ($functionResponses, $onChunk, $currentContent, $onComplete, $model) {
                // 如果第二次请求失败，直接显示工具结果
                $fallback = $this->formatToolResults($functionResponses);
                $onChunk($fallback, false);
                $onComplete($currentContent . $fallback, null, $model);
            },
            $options
        );
    }
    
    /**
     * 格式化工具结果为自然语言
     */
    private function formatToolResults(array $results): string
    {
        $output = "\n";
        
        foreach ($results as $item) {
            $name = $item['name'];
            $args = $item['args'] ?? [];
            $result = $item['result'];
            
            if (isset($result['error'])) {
                $output .= "> ❌ **{$name}** 执行失败: {$result['error']}\n\n";
                continue;
            }
            
            $data = $result['result'] ?? $result;
            
            switch ($name) {
                case 'get_current_time':
                    $output .= "> 🕐 **{$data['datetime']}** `{$data['timezone']}`\n\n";
                    break;
                    
                case 'calculator':
                    $output .= "> 🔢 `{$data['expression']}` = **{$data['result']}**\n\n";
                    break;
                    
                case 'fetch_webpage':
                    $url = $args['url'] ?? $data['url'] ?? '';
                    $content = $data['content'] ?? '';
                    $output .= "> 🌐 **抓取网页**: [{$url}]({$url})\n\n";
                    $output .= "{$content}\n\n";
                    break;
                    
                case 'search_book':
                    $output .= "> 📚 **书籍搜索** \"{$args['query']}\" - 找到 {$data['count']} 条结果\n\n";
                    foreach ($data['results'] ?? [] as $i => $r) {
                        $output .= "**" . ($i + 1) . ".** {$r['text']}... `{$r['score']}%`\n\n";
                    }
                    break;
                    
                default:
                    $output .= "> 🔧 **{$name}**\n\n";
                    $output .= "```json\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```\n\n";
            }
        }
        
        return $output;
    }
    
    /**
     * 清理网页内容，提取有意义的文本
     */
    private function cleanWebContent(string $content): string
    {
        // 移除多余空白
        $content = preg_replace('/\s+/', ' ', $content);
        // 截取前 800 字符
        $content = mb_substr(trim($content), 0, 800);
        // 尝试在句子结尾截断
        if (preg_match('/^(.{600,}?[。！？.!?])/u', $content, $m)) {
            $content = $m[1];
        }
        return $content . '...';
    }
    
    public function cancel(string $requestId): void { AsyncCurlManager::cancel($requestId); }
    
    private function buildRequestData(array $messages, array $options): array
    {
        $contents = [];
        $systemInstruction = null;
        
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? $msg['type'] ?? 'user';
            $content = $msg['content'] ?? $msg['query'] ?? '';
            
            if ($role === 'system') {
                $systemInstruction = ['parts' => [['text' => $content]]];
            } elseif ($role === 'function') {
                // Function response - 使用 function 角色
                $contents[] = [
                    'role' => 'function',
                    'parts' => [[
                        'functionResponse' => [
                            'name' => $msg['name'],
                            'response' => ['result' => json_decode($content, true) ?? $content],
                        ]
                    ]]
                ];
            } elseif (isset($msg['function_calls'])) {
                // AI's function call
                $parts = [];
                foreach ($msg['function_calls'] as $fc) {
                    $parts[] = [
                        'functionCall' => [
                            'name' => $fc['name'],
                            'args' => $fc['args'],
                        ]
                    ];
                }
                $contents[] = ['role' => 'model', 'parts' => $parts];
            } else {
                $contents[] = ['role' => $role === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $content]]];
            }
        }
        
        $data = [
            'contents' => $contents,
            'generationConfig' => ['thinkingConfig' => ['includeThoughts' => $options['includeThoughts'] ?? true]],
        ];
        
        if ($systemInstruction) $data['system_instruction'] = $systemInstruction;
        
        // 添加工具（Google Search 和 MCP 工具可以同时使用）
        $tools = [];
        if ($options['enableSearch'] ?? false) {
            $tools[] = ['google_search' => new \stdClass()];
        }
        if ($options['enableTools'] ?? false) {
            $declarations = ToolManager::getToolDefinitions();
            if (!empty($declarations)) {
                $tools[] = ['function_declarations' => $declarations];
            }
        }
        if (!empty($tools)) {
            $data['tools'] = $tools;
        }
        
        return $data;
    }
}
