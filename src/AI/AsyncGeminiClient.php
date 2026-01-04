<?php
/**
 * 异步 Gemini 客户端（使用 curl_multi）
 */

namespace SmartBook\AI;

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
        
        $onData = function($rawData) use (&$fullContent, &$buffer, $onChunk) {
            $buffer .= $rawData;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                
                if (empty($line) || !str_starts_with($line, 'data: ')) continue;
                
                $chunk = json_decode(substr($line, 6), true);
                if (!$chunk || !isset($chunk['candidates'])) continue;
                
                foreach ($chunk['candidates'] as $candidate) {
                    foreach ($candidate['content']['parts'] ?? [] as $part) {
                        $text = $part['text'] ?? '';
                        $isThought = $part['thought'] ?? false;
                        if ($text) {
                            if (!$isThought) $fullContent .= $text;
                            $onChunk($text, $isThought);
                        }
                    }
                }
            }
        };
        
        $onFinish = function($success, $error) use (&$fullContent, $onComplete, $onError) {
            $success ? $onComplete($fullContent) : ($onError ? $onError($error) : null);
        };
        
        return AsyncCurlManager::request(
            $url,
            [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data), CURLOPT_HTTPHEADER => ['Content-Type: application/json']],
            $onData,
            $onFinish
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
            } else {
                $contents[] = ['role' => $role === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $content]]];
            }
        }
        
        $data = [
            'contents' => $contents,
            'generationConfig' => ['thinkingConfig' => ['includeThoughts' => $options['includeThoughts'] ?? true]],
        ];
        
        if ($systemInstruction) $data['system_instruction'] = $systemInstruction;
        if ($options['enableSearch'] ?? false) $data['tools'] = [['google_search' => new \stdClass()]];
        
        return $data;
    }
}
