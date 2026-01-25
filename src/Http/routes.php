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
    
    // ===================================
    // 动态路由示例（带类型验证和安全检查）
    // ===================================
    
    // 示例 1: 整数参数（自动转换为 int，防止 SQL 注入）
    Router::get('/example/user/{id:int}', fn($conn, $req, $params) => [
        'message' => '获取用户信息（安全）',
        'userId' => $params['id'],
        'type' => gettype($params['id']),  // 返回 "integer"
        'safe' => 'ID 已验证为整数，可安全用于数据库查询'
    ]);
    
    // 示例 2: 多个类型参数
    Router::get('/example/user/{userId:int}/post/{postId:int}', fn($conn, $req, $params) => [
        'message' => '获取用户的文章',
        'userId' => $params['userId'],     // int
        'postId' => $params['postId'],     // int
        'safe' => '所有 ID 都已验证为整数'
    ]);
    
    // 示例 3: RESTful API（带类型验证）
    Router::get('/example/books/{id:int}', fn($conn, $req, $params) => [
        'action' => 'GET',
        'bookId' => $params['id'],
        'sql_safe' => true
    ]);
    
    Router::put('/example/books/{id:int}', fn($conn, $req, $params) => [
        'action' => 'PUT (更新)',
        'bookId' => $params['id']
    ]);
    
    Router::delete('/example/books/{id:int}', fn($conn, $req, $params) => [
        'action' => 'DELETE (删除)',
        'bookId' => $params['id']
    ]);
    
    // 示例 4: 字母参数（用户名等）
    Router::get('/example/profile/{username:alpha}', fn($conn, $req, $params) => [
        'message' => '获取用户资料',
        'username' => $params['username'],  // 只包含字母
        'safe' => '用户名已过滤，只包含 a-zA-Z'
    ]);
    
    // 示例 5: Slug 参数（URL 友好）
    Router::get('/example/article/{slug:slug}', fn($conn, $req, $params) => [
        'message' => '获取文章',
        'slug' => $params['slug'],  // hello-world-123
        'safe' => 'Slug 已标准化为小写字母、数字和连字符'
    ]);
    
    // 示例 6: UUID 参数
    Router::get('/example/order/{orderId:uuid}', fn($conn, $req, $params) => [
        'message' => '获取订单',
        'orderId' => $params['orderId'],  // 550e8400-e29b-41d4-a716-446655440000
        'safe' => 'UUID 格式已验证'
    ]);
    
    // 示例 7: 路径参数（文件路径，已防护路径遍历）
    Router::get('/example/file/{path:path}', fn($conn, $req, $params) => [
        'message' => '获取文件',
        'path' => $params['path'],  // docs/readme.txt
        'safe' => '路径已清理，防止 ../ 攻击'
    ]);
    
    // 示例 8: 任意参数（HTML 转义，防止 XSS）
    Router::get('/example/search/{query}', fn($conn, $req, $params) => [
        'message' => '搜索',
        'query' => $params['query'],  // 自动 HTML 转义
        'safe' => '查询已 HTML 转义，防止 XSS 攻击'
    ]);
});
