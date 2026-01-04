<?php
/**
 * AI 服务类 - 统一管理 AI 客户端实例
 */

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
        $embedder = new EmbeddingClient(GEMINI_API_KEY);
        $queryEmbedding = $embedder->embedQuery($question);
        
        $vectorStore = new VectorStore(DEFAULT_BOOK_CACHE);
        $results = $vectorStore->hybridSearch($question, $queryEmbedding, $topK, 0.6);
        
        $context = "";
        foreach ($results as $i => $result) {
            $context .= "【片段 " . ($i + 1) . "】\n" . $result['chunk']['text'] . "\n\n";
        }
        
        $gemini = self::getGemini();
        $response = $gemini->chat([
            ['role' => 'system', 'content' => "你是一个书籍分析助手。根据以下内容回答问题，使用中文：\n\n{$context}"],
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
