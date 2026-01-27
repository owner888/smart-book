<?php
/**
 * AI 服务类 - 统一管理 AI 客户端实例
 */

namespace SmartBook\AI;

use SmartBook\RAG\EmbeddingClient;
use SmartBook\RAG\VectorStore;
use SmartBook\RAG\BookRAGAssistant;
use SmartBook\Http\Handlers\ConfigHandler;

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
            $currentCache = ConfigHandler::getCurrentBookCache();
            $currentPath = ConfigHandler::getCurrentBookPath();
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
