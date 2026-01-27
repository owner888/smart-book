<?php

namespace SmartBook\RAG;
/**
 * 向量嵌入客户端 - 使用 Google Gemini Embedding API
 */

use SmartBook\Logger;

class EmbeddingClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    
    const MODEL_GEMINI = 'text-embedding-004';
    
    public function __construct(string $apiKey, string $model = self::MODEL_GEMINI)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    }
    
    public function embedQuery(string $text): array
    {
        return $this->embedSingle($text, 'RETRIEVAL_QUERY');
    }
    
    public function embed(string $text): array
    {
        return $this->embedSingle($text, 'RETRIEVAL_DOCUMENT');
    }
    
    private function embedSingle(string $text, string $taskType): array
    {
        $url = "{$this->baseUrl}/models/{$this->model}:embedContent?key={$this->apiKey}";
        $nonce = ' ' . substr(md5(uniqid('', true)), 0, 8);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cache-Control: no-cache'],
            CURLOPT_POSTFIELDS => json_encode([
                'content' => ['parts' => [['text' => $text . $nonce]]],
                'taskType' => $taskType,
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        
        if ($error || empty($response)) return [];
        
        $result = json_decode($response, true);
        if (isset($result['error'])) {
            Logger::error("Embedding API 错误: " . ($result['error']['message'] ?? 'Unknown'));
            return [];
        }
        
        return $result['embedding']['values'] ?? [];
    }
    
    public function embedBatch(array $texts): array
    {
        $url = "{$this->baseUrl}/models/{$this->model}:batchEmbedContents?key={$this->apiKey}";
        
        $requests = array_map(fn($text) => [
            'content' => ['parts' => [['text' => $text]]],
            'taskType' => 'RETRIEVAL_DOCUMENT',
        ], $texts);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['requests' => $requests]),
            CURLOPT_TIMEOUT => 60,
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        
        if ($error) return array_fill(0, count($texts), []);
        
        $result = json_decode($response, true);
        if (isset($result['error'])) return array_fill(0, count($texts), []);
        
        return array_map(fn($e) => $e['values'] ?? [], $result['embeddings'] ?? []);
    }
}
