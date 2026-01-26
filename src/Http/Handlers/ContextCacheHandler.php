<?php
/**
 * Gemini Context Cache 管理处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Http\Context;
use SmartBook\AI\GeminiContextCache;

class ContextCacheHandler
{
    /**
     * 列出所有缓存
     */
    public static function list(): array
    {
        try {
            $cache = new GeminiContextCache(GEMINI_API_KEY);
            return $cache->listCaches();
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 创建缓存
     */
    public static function create(Context $ctx): array
    {
        $body = $ctx->jsonBody() ?? [];
        $content = $body['content'] ?? '';
        $displayName = $body['display_name'] ?? null;
        $systemInstruction = $body['system_instruction'] ?? null;
        $ttl = intval($body['ttl'] ?? GeminiContextCache::DEFAULT_TTL);
        $model = $body['model'] ?? 'gemini-2.5-flash';
        
        if (empty($content)) {
            return ['success' => false, 'error' => 'Missing content'];
        }
        
        try {
            $cache = new GeminiContextCache(GEMINI_API_KEY, $model);
            
            if (!$cache->meetsMinTokens($content)) {
                $estimatedTokens = GeminiContextCache::estimateTokens($content);
                $minRequired = GeminiContextCache::MIN_TOKENS[$model] ?? 1024;
                return [
                    'success' => false, 
                    'error' => "内容太短，估算 {$estimatedTokens} tokens，最低要求 {$minRequired} tokens"
                ];
            }
            
            return $cache->create($content, $displayName, $systemInstruction, $ttl);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 为书籍创建缓存
     */
    public static function createForBook(Context $ctx): array
    {
        $body = $ctx->jsonBody() ?? [];
        $bookFile = $body['book'] ?? '';
        $ttl = intval($body['ttl'] ?? GeminiContextCache::DEFAULT_TTL);
        $model = $body['model'] ?? 'gemini-2.5-flash';
        
        if (empty($bookFile)) {
            return ['success' => false, 'error' => 'Missing book parameter'];
        }
        
        $booksDir = dirname(__DIR__, 3) . '/books';
        $bookPath = $booksDir . '/' . $bookFile;
        
        if (!file_exists($bookPath)) {
            return ['success' => false, 'error' => 'Book not found: ' . $bookFile];
        }
        
        try {
            $ext = strtolower(pathinfo($bookFile, PATHINFO_EXTENSION));
            if ($ext === 'epub') {
                $content = \SmartBook\Parser\EpubParser::extractText($bookPath);
            } else {
                $content = file_get_contents($bookPath);
            }
            
            if (empty($content)) {
                return ['success' => false, 'error' => 'Failed to extract book content'];
            }
            
            $cache = new GeminiContextCache(GEMINI_API_KEY, $model);
            
            if (!$cache->meetsMinTokens($content)) {
                $estimatedTokens = GeminiContextCache::estimateTokens($content);
                $minRequired = GeminiContextCache::MIN_TOKENS[$model] ?? 1024;
                return [
                    'success' => false, 
                    'error' => "书籍内容太短，估算 {$estimatedTokens} tokens，最低要求 {$minRequired} tokens"
                ];
            }
            
            $result = $cache->createForBook($bookFile, $content, $ttl);
            
            if ($result['success']) {
                $result['book'] = $bookFile;
                $result['contentLength'] = mb_strlen($content);
                $result['estimatedTokens'] = GeminiContextCache::estimateTokens($content);
            }
            
            return $result;
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 删除缓存
     */
    public static function delete(Context $ctx): array
    {
        $body = $ctx->jsonBody() ?? [];
        $cacheName = $body['name'] ?? '';
        
        if (empty($cacheName)) {
            return ['success' => false, 'error' => 'Missing cache name'];
        }
        
        try {
            $cache = new GeminiContextCache(GEMINI_API_KEY);
            return $cache->delete($cacheName);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 获取缓存详情
     */
    public static function get(Context $ctx): array
    {
        $body = $ctx->jsonBody() ?? [];
        $cacheName = $body['name'] ?? '';
        
        if (empty($cacheName)) {
            return ['success' => false, 'error' => 'Missing cache name'];
        }
        
        try {
            $cache = new GeminiContextCache(GEMINI_API_KEY);
            $result = $cache->get($cacheName);
            
            if ($result) {
                return ['success' => true, 'cache' => $result];
            }
            
            return ['success' => false, 'error' => 'Cache not found'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
