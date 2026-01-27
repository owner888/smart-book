<?php
/**
 * Gemini Context Cache 管理处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Logger;
use SmartBook\Http\Context;
use SmartBook\Http\ErrorHandler;
use SmartBook\AI\GeminiContextCache;

class ContextCacheHandler
{
    /**
     * 列出所有缓存
     * 
     * 注意：异常会被全局 ExceptionHandler 自动捕获并记录
     */
    public static function list(): array
    {
        Logger::info('[ContextCache] 列出所有缓存');
        
        $cache = new GeminiContextCache(GEMINI_API_KEY);
        $result = $cache->listCaches();
        
        $count = count($result['caches'] ?? []);
        ErrorHandler::logOperation('ContextCache::list', 'success', ['count' => $count]);
        
        return $result;
    }
    
    /**
     * 创建缓存
     */
    public static function create(Context $ctx): array
    {
        $body = $ctx->jsonBody() ?? [];
        ErrorHandler::requireParams($body, ['content']);
        
        $content = $body['content'];
        $displayName = $body['display_name'] ?? null;
        $systemInstruction = $body['system_instruction'] ?? null;
        $ttl = intval($body['ttl'] ?? GeminiContextCache::DEFAULT_TTL);
        $model = $body['model'] ?? 'gemini-2.5-flash';
        
        Logger::info("[ContextCache] 创建缓存", ['model' => $model, 'ttl' => $ttl]);
        
        $cache = new GeminiContextCache(GEMINI_API_KEY, $model);
        
        // 检查 token 数量
        if (!$cache->meetsMinTokens($content)) {
            $estimatedTokens = GeminiContextCache::estimateTokens($content);
            $minRequired = GeminiContextCache::MIN_TOKENS[$model] ?? 1024;
            Logger::warn("[ContextCache] 内容太短", [
                'estimated' => $estimatedTokens,
                'required' => $minRequired
            ]);
            throw new \Exception("内容太短，估算 {$estimatedTokens} tokens，最低要求 {$minRequired} tokens");
        }
        
        $result = $cache->create($content, $displayName, $systemInstruction, $ttl);
        
        if ($result['success']) {
            ErrorHandler::logOperation('ContextCache::create', 'success', [
                'cacheName' => $result['name'] ?? 'unknown'
            ]);
        }
        
        return $result;
    }
    
    /**
     * 为书籍创建缓存
     */
    public static function createForBook(Context $ctx): array
    {
        $body = $ctx->jsonBody() ?? [];
        ErrorHandler::requireParams($body, ['book']);
        
        $bookFile = $body['book'];
        $ttl = intval($body['ttl'] ?? GeminiContextCache::DEFAULT_TTL);
        $model = $body['model'] ?? 'gemini-2.5-flash';
        
        Logger::info("[ContextCache] 为书籍创建缓存", [
            'book' => $bookFile,
            'model' => $model,
            'ttl' => $ttl
        ]);
        
        $bookPath = BOOKS_DIR . '/' . $bookFile;
        
        ErrorHandler::requireFile($bookPath, '书籍文件');
        
        // 提取书籍内容
        $startTime = microtime(true);
        $ext = strtolower(pathinfo($bookFile, PATHINFO_EXTENSION));
        if ($ext === 'epub') {
            $content = \SmartBook\Parser\EpubParser::extractText($bookPath);
        } else {
            $content = file_get_contents($bookPath);
        }
        $extractTime = (microtime(true) - $startTime) * 1000;
        
        if (empty($content)) {
            throw new \Exception('Failed to extract book content');
        }
        
        Logger::info("[ContextCache] 书籍内容提取完成", [
            'length' => mb_strlen($content),
            'time_ms' => round($extractTime, 2)
        ]);
        
        $cache = new GeminiContextCache(GEMINI_API_KEY, $model);
        
        // 检查 token 数量
        if (!$cache->meetsMinTokens($content)) {
            $estimatedTokens = GeminiContextCache::estimateTokens($content);
            $minRequired = GeminiContextCache::MIN_TOKENS[$model] ?? 1024;
            Logger::warn("[ContextCache] 书籍内容太短", [
                'estimated' => $estimatedTokens,
                'required' => $minRequired
            ]);
            throw new \Exception("书籍内容太短，估算 {$estimatedTokens} tokens，最低要求 {$minRequired} tokens");
        }
        
        $result = $cache->createForBook($bookFile, $content, $ttl);
        
        if ($result['success']) {
            $result['book'] = $bookFile;
            $result['contentLength'] = mb_strlen($content);
            $result['estimatedTokens'] = GeminiContextCache::estimateTokens($content);
            
            ErrorHandler::logOperation('ContextCache::createForBook', 'success', [
                'book' => $bookFile,
                'tokens' => $result['estimatedTokens']
            ]);
        }
        
        return $result;
    }
    
    /**
     * 删除缓存
     */
    public static function delete(Context $ctx): array
    {
        $body = $ctx->jsonBody() ?? [];
        ErrorHandler::requireParams($body, ['name']);
        
        $cacheName = $body['name'];
        Logger::info("[ContextCache] 删除缓存", ['name' => $cacheName]);
        
        $cache = new GeminiContextCache(GEMINI_API_KEY);
        $result = $cache->delete($cacheName);
        
        if ($result['success']) {
            ErrorHandler::logOperation('ContextCache::delete', 'success', ['name' => $cacheName]);
        }
        
        return $result;
    }
    
    /**
     * 获取缓存详情
     */
    public static function get(Context $ctx): array
    {
        $body = $ctx->jsonBody() ?? [];
        ErrorHandler::requireParams($body, ['name']);
        
        $cacheName = $body['name'];
        Logger::info("[ContextCache] 获取缓存详情", ['name' => $cacheName]);
        
        $cache = new GeminiContextCache(GEMINI_API_KEY);
        $result = $cache->get($cacheName);
        
        if ($result) {
            ErrorHandler::logOperation('ContextCache::get', 'success', ['name' => $cacheName]);
            return ErrorHandler::success(['cache' => $result]);
        }
        
        Logger::warn("[ContextCache] 缓存未找到", ['name' => $cacheName]);
        throw new \Exception('Cache not found: ' . $cacheName);
    }
}
