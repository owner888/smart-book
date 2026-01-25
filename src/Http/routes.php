<?php
/**
 * 路由定义文件
 * 
 * 使用优雅的路由 API 替代 match 语句
 */

use SmartBook\Http\Router;

// ===================================
// API 路由组
// ===================================

Router::group('/api', function() {
    
    // 基础信息
    Router::get('', fn() => ['status' => 'ok', 'message' => 'Smart Book AI API']);
    Router::get('/health', fn() => ['status' => 'ok', 'timestamp' => date('Y-m-d H:i:s'), 'redis' => \SmartBook\Cache\CacheService::isConnected()]);
    Router::get('/config', fn($conn, $req) => handleGetConfig());
    
    // 模型和助手
    Router::get('/models', fn($conn, $req) => handleGetModels());
    Router::get('/assistants', fn($conn, $req) => handleGetAssistants());
    
    // 书籍管理
    Router::get('/books', fn($conn, $req) => handleGetBooks());
    Router::post('/books/select', fn($conn, $req) => handleSelectBook($req));
    Router::post('/books/index', fn($conn, $req) => handleIndexBook($conn, $req));
    
    // MCP 服务器
    Router::any('/mcp/servers', function($conn, $req) {
        return $req->method() === 'POST' ? handleSaveMCPServers($req) : handleGetMCPServers();
    });
    Router::get('/mcp/status', fn() => ['enabled' => true, 'url' => 'http://' . MCP_SERVER_HOST . ':' . MCP_SERVER_PORT . '/mcp']);
    
    // 缓存
    Router::get('/cache/stats', fn($conn, $req) => handleCacheStats($conn));
    
    // 问答 API
    Router::post('/ask', fn($conn, $req) => handleAskWithCache($conn, $req));
    Router::post('/chat', fn($conn, $req) => handleChat($req));
    Router::post('/continue', fn($conn, $req) => handleContinue($req));
    
    // 流式 API
    Router::post('/stream/ask', fn($conn, $req) => handleStreamAskAsync($conn, $req));
    Router::post('/stream/chat', fn($conn, $req) => handleStreamChat($conn, $req));
    Router::post('/stream/continue', fn($conn, $req) => handleStreamContinue($conn, $req));
    Router::post('/stream/enhanced-continue', fn($conn, $req) => handleStreamEnhancedContinue($conn, $req));
    Router::post('/stream/analyze-characters', fn($conn, $req) => handleStreamAnalyzeCharacters($conn, $req));
    
    // TTS 语音合成
    Router::post('/tts/synthesize', fn($conn, $req) => handleTTSSynthesize($conn, $req));
    Router::get('/tts/voices', fn($conn, $req) => handleTTSVoices());
    Router::get('/tts/list-api-voices', fn($conn, $req) => handleTTSListAPIVoices());
    
    // ASR 语音识别
    Router::post('/asr/recognize', fn($conn, $req) => handleASRRecognize($conn, $req));
    Router::get('/asr/languages', fn($conn, $req) => handleASRLanguages());
    
    // Context Cache 管理
    Router::get('/context-cache/list', fn($conn, $req) => handleContextCacheList());
    Router::post('/context-cache/create', fn($conn, $req) => handleContextCacheCreate($req));
    Router::post('/context-cache/create-for-book', fn($conn, $req) => handleContextCacheCreateForBook($req));
    Router::post('/context-cache/delete', fn($conn, $req) => handleContextCacheDelete($req));
    Router::post('/context-cache/get', fn($conn, $req) => handleContextCacheGet($req));
    
    // 增强版续写
    Router::post('/enhanced-writer/prepare', fn($conn, $req) => handleEnhancedWriterPrepare($req));
    Router::post('/enhanced-writer/status', fn($conn, $req) => handleEnhancedWriterStatus($req));
});
