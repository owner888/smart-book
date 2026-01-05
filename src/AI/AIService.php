<?php
/**
 * AI 服务类 - 统一管理 AI 客户端实例
 */

namespace SmartBook\AI;

use SmartBook\RAG\EmbeddingClient;
use SmartBook\RAG\VectorStore;
use SmartBook\RAG\BookRAGAssistant;

class AIService
{
    private static ?BookRAGAssistant $ragAssistant = null;
    private static ?GeminiClient $gemini = null;
    private static ?AsyncGeminiClient $asyncGemini = null;
    
    public static function getRAGAssistant(): BookRAGAssistant
    {
        if (self::$ragAssistant === null) {
            self::$ragAssistant = new BookRAGAssistant(GEMINI_API_KEY);
            // 使用当前选中的书籍
            $currentCache = getCurrentBookCache();
            $currentPath = getCurrentBookPath();
            if ($currentCache && $currentPath) {
                self::$ragAssistant->loadBook($currentPath, $currentCache);
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
    
    public static function getAsyncGemini(?string $model = null): AsyncGeminiClient
    {
        // 如果指定了模型且与缓存的不同，创建新实例
        if ($model) {
            return new AsyncGeminiClient(GEMINI_API_KEY, $model);
        }
        
        if (!self::$asyncGemini) {
            self::$asyncGemini = new AsyncGeminiClient(GEMINI_API_KEY);
        }
        return self::$asyncGemini;
    }
    
    /**
     * RAG 问答（非流式）
     */
    public static function askBook(string $question, int $topK = 8): array
    {
        $currentCache = getCurrentBookCache();
        if (!$currentCache) {
            return ['success' => false, 'error' => 'No book index available'];
        }
        
        $embedder = new EmbeddingClient(GEMINI_API_KEY);
        $queryEmbedding = $embedder->embedQuery($question);
        
        $vectorStore = new VectorStore($currentCache);
        $results = $vectorStore->hybridSearch($question, $queryEmbedding, $topK, 0.6);
        
        // 使用配置文件中的片段标签
        $chunkLabel = $GLOBALS['config']['prompts']['chunk_label'] ?? '【片段 {index}】';
        $context = "";
        foreach ($results as $i => $result) {
            $label = str_replace('{index}', $i + 1, $chunkLabel);
            $context .= "{$label}\n" . $result['chunk']['text'] . "\n\n";
        }
        
        // 使用配置文件中的提示词
        $ragSimplePrompt = $GLOBALS['config']['prompts']['rag_simple']['system'] ?? '你是一个书籍分析助手。根据以下内容回答问题，使用中文：

{context}';
        $systemPrompt = str_replace('{context}', $context, $ragSimplePrompt);
        
        $gemini = self::getGemini();
        $response = $gemini->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $question],
        ]);
        
        $answer = '';
        foreach ($response['candidates'] ?? [] as $candidate) {
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (!($part['thought'] ?? false)) $answer .= $part['text'] ?? '';
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
                if (!($part['thought'] ?? false)) $answer .= $part['text'] ?? '';
            }
        }
        
        return ['success' => true, 'answer' => $answer];
    }
    
    /**
     * 续写章节（非流式）
     */
    public static function continueStory(string $prompt = ''): array
    {
        $systemPrompt = $GLOBALS['config']['prompts']['continue']['system'] ?? '';
        $userPrompt = $prompt ?: ($GLOBALS['config']['prompts']['continue']['default_prompt'] ?? '');
        
        $gemini = self::getGemini();
        $response = $gemini->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ]);
        
        $story = '';
        foreach ($response['candidates'] ?? [] as $candidate) {
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (!($part['thought'] ?? false)) $story .= $part['text'] ?? '';
            }
        }
        
        return ['success' => true, 'story' => $story];
    }
}
