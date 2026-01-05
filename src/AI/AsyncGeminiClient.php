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
        $onToolCall = $options['onToolCall'] ?? null;
        
        $onData = function($rawData) use (&$fullContent, &$buffer, &$functionCalls, $onChunk, $onToolCall) {
            $buffer .= $rawData;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                
                if (empty($line) || !str_starts_with($line, 'data: ')) continue;
                
                $chunk = json_decode(substr($line, 6), true);
                if (!$chunk || !isset($chunk['candidates'])) continue;
                
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
        
        $onFinish = function($success, $error) use (&$fullContent, &$functionCalls, $onComplete, $onError, $messages, $options, $onChunk) {
            if (!$success) {
                $onError ? $onError($error) : null;
                return;
            }
            
            // 如果有 function calls，执行它们
            if (!empty($functionCalls)) {
                $this->handleFunctionCalls($functionCalls, $messages, $fullContent, $onChunk, $onComplete, $onError, $options);
            } else {
                $onComplete($fullContent);
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
        
        foreach ($functionCalls as $fc) {
            $name = $fc['name'];
            $args = $fc['args'];
            
            // 通知前端工具开始执行
            $onChunk("\n\n🔧 执行工具: {$name}\n", false);
            
            // 执行工具
            $result = ToolManager::execute($name, $args);
            
            // 通知前端工具结果
            $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $onChunk("```json\n{$resultJson}\n```\n\n", false);
            
            $functionResponses[] = [
                'name' => $name,
                'response' => $result,
            ];
        }
        
        // 构建包含工具结果的新消息
        $newMessages = $originalMessages;
        
        // 添加 AI 的 function call 响应
        $newMessages[] = [
            'role' => 'assistant',
            'function_calls' => $functionCalls,
        ];
        
        // 添加工具执行结果
        foreach ($functionResponses as $fr) {
            $newMessages[] = [
                'role' => 'function',
                'name' => $fr['name'],
                'content' => json_encode($fr['response'], JSON_UNESCAPED_UNICODE),
            ];
        }
        
        // 继续对话获取最终回复
        $options['enableTools'] = false; // 避免无限循环
        $this->chatStreamAsync(
            $newMessages,
            $onChunk,
            function($finalContent) use ($currentContent, $onComplete) {
                $onComplete($currentContent . $finalContent);
            },
            $onError,
            $options
        );
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
                // Function response
                $contents[] = [
                    'role' => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name' => $msg['name'],
                            'response' => json_decode($content, true) ?? $content,
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
