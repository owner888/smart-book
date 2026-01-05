<?php
/**
 * Google Gemini API 客户端
 */

namespace SmartBook\AI;

class GeminiClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private int $timeout;
    
    const MODEL_GEMINI_25_PRO = 'gemini-2.5-pro';
    const MODEL_GEMINI_25_FLASH = 'gemini-2.5-flash';
    const MODEL_GEMINI_25_FLASH_LITE = 'gemini-2.5-flash-lite';
    
    public function __construct(
        string $apiKey,
        string $model = self::MODEL_GEMINI_25_FLASH,
        string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        int $timeout = 120
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }
    
    public function chat(array $messages, array $options = []): array
    {
        $model = $options['model'] ?? $this->model;
        $data = $this->buildRequestData($messages, $options);
        return $this->request('POST', "/models/{$model}:generateContent", $data);
    }
    
    public function chatStream(array $messages, callable $onChunk, array $options = []): array
    {
        $model = $options['model'] ?? $this->model;
        $data = $this->buildRequestData($messages, $options);
        return $this->requestStream('POST', "/models/{$model}:streamGenerateContent?alt=sse", $data, $onChunk);
    }
    
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
        if ($options['enableSearch'] ?? true) $data['tools'] = [['google_search' => new \stdClass()]];
        
        return $data;
    }
    
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-goog-api-key: ' . $this->apiKey],
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($error) return ['error' => "cURL Error: {$error}"];
        
        $result = json_decode($response, true);
        if ($httpCode >= 400) {
            return ['error' => "Gemini API Error ({$httpCode}): " . ($result['error']['message'] ?? 'Unknown')];
        }
        
        return $result;
    }
    
    private function requestStream(string $method, string $endpoint, array $data, callable $onChunk): array
    {
        $fullContent = '';
        $fullReasoning = '';
        $metadata = null;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . $endpoint,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-goog-api-key: ' . $this->apiKey],
            CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$fullContent, &$fullReasoning, &$metadata, $onChunk) {
                foreach (explode("\n", $data) as $line) {
                    $line = trim($line);
                    if (empty($line) || !str_starts_with($line, 'data: ')) continue;
                    
                    $chunk = json_decode(substr($line, 6), true);
                    if (!$chunk || !isset($chunk['candidates'])) continue;
                    
                    foreach ($chunk['candidates'] as $candidate) {
                        foreach ($candidate['content']['parts'] ?? [] as $part) {
                            $text = $part['text'] ?? '';
                            $isThought = $part['thought'] ?? false;
                            if ($text) {
                                if ($isThought) $fullReasoning .= $text; else $fullContent .= $text;
                                $onChunk($text, $chunk, $isThought);
                            }
                        }
                    }
                    if (isset($chunk['usageMetadata'])) $metadata = $chunk;
                }
                return strlen($data);
            },
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($error) return ['error' => "cURL Error: {$error}", 'content' => $fullContent];
        if ($httpCode >= 400) return ['error' => "Gemini API Error: HTTP {$httpCode}", 'content' => $fullContent];
        
        return ['content' => $fullContent, 'reasoning' => $fullReasoning, 'metadata' => $metadata];
    }
}
