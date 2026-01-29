<?php
/**
 * 路由定义文件
 * 
 * 使用优雅的路由 API 替代 match 语句
 */

use SmartBook\Http\Router;
use SmartBook\Http\Middlewares\ResponseMiddleware;
use SmartBook\Http\ExceptionHandler;
use SmartBook\Http\Handlers\ConfigHandler;
use SmartBook\Http\Handlers\ChatHandler;
use SmartBook\Http\Handlers\BookHandler;
use SmartBook\Http\Handlers\TTSHandler;
use SmartBook\Http\Handlers\ASRHandler;
use SmartBook\Http\Handlers\CacheHandler;
use SmartBook\Http\Handlers\ContextCacheHandler;
use SmartBook\Http\Handlers\EnhancedWriterHandler;
use SmartBook\Http\Handlers\MCPHandler;

// ===================================
// 全局异常处理器
// ===================================
// 开发模式：显示详细错误信息
// 生产模式：隐藏错误细节
Router::setExceptionHandler(new ExceptionHandler(
    debug: true  // 开发环境设为 true，生产环境设为 false
));

// ===================================
// 全局中间件（应用于所有路由）
// ===================================
// 统一响应格式 - 自动包装成 {success, data, error, meta}
Router::middleware(new ResponseMiddleware(true, [
    '/static',      // 排除静态文件
    '/favicon',     // 排除 favicon
]));

// CORS 跨域支持（可选）
// Router::middleware(new CorsMiddleware());

// ===================================
// API 路由组
// ===================================

Router::group('/api', function() {
    
    // ===================================
    // 基础 API
    // ===================================
    Router::get('', fn($ctx) => ['status' => 'ok', 'message' => 'Smart Book AI API']);
    Router::get('/health', fn($ctx) => ['status' => 'ok', 'timestamp' => date('Y-m-d H:i:s'), 'redis' => \SmartBook\Cache\CacheService::isConnected()]);
    
    // ===================================
    // 配置管理 (/api/config/*)
    // ===================================
    Router::group('/config', function() {
        Router::get('', fn($ctx) => ConfigHandler::getConfig());
        Router::get('/models', fn($ctx) => ConfigHandler::getModels());
        Router::get('/assistants', fn($ctx) => ConfigHandler::getAssistants());
    });
    
    // ===================================
    // 书籍管理 (/api/books/*)
    // ===================================
    Router::group('/books', function() {
        Router::get('', fn($ctx) => BookHandler::getBooks());
        Router::post('/select', fn($ctx) => BookHandler::selectBook($ctx));
        Router::post('/upload', fn($ctx) => BookHandler::uploadBook($ctx));
        Router::post('/index', fn($ctx) => BookHandler::indexBook($ctx));
    });
    
    // ===================================
    // 流式聊天 (/api/stream/*)
    // ===================================
    Router::group('/stream', function() {
        // 书籍问答（基于 Context Cache）
        Router::post('/ask', fn($ctx) => ChatHandler::streamAskWithCache($ctx));
        
        // 通用聊天
        Router::post('/chat', fn($ctx) => ChatHandler::streamChat($ctx));
        
        // 小说续写（基于 Context Cache）
        Router::post('/continue', fn($ctx) => ChatHandler::streamContinueWithCache($ctx));
        
        // 增强版续写
        Router::post('/enhanced-continue', fn($ctx) => EnhancedWriterHandler::streamContinue($ctx));
        Router::post('/analyze-characters', fn($ctx) => EnhancedWriterHandler::analyzeCharacters($ctx));
    });
    
    // ===================================
    // 增强版续写 (/api/enhanced-writer/*)
    // ===================================
    Router::group('/enhanced-writer', function() {
        Router::post('/prepare', fn($ctx) => EnhancedWriterHandler::prepare($ctx));
        Router::post('/status', fn($ctx) => EnhancedWriterHandler::getStatus($ctx));
    });
    
    // ===================================
    // TTS 语音合成 (/api/tts/*)
    // ===================================
    Router::group('/tts', function() {
        Router::post('/synthesize', fn($ctx) => TTSHandler::synthesize($ctx));
        Router::get('/voices', fn($ctx) => TTSHandler::getVoices());
        Router::get('/list-api-voices', fn($ctx) => TTSHandler::listAPIVoices());
    });
    
    // ===================================
    // ASR 语音识别 (/api/asr/*)
    // ===================================
    Router::group('/asr', function() {
        Router::post('/recognize', fn($ctx) => ASRHandler::recognize($ctx));
        Router::get('/languages', fn($ctx) => ASRHandler::getLanguages());
        Router::get('/config', fn($ctx) => ASRHandler::getConfig());
    });
    
    // ===================================
    // 缓存管理 (/api/cache/*)
    // ===================================
    Router::group('/cache', function() {
        // Redis 缓存统计
        Router::get('/stats', fn($ctx) => CacheHandler::getStats($ctx));
        
        // Context Cache 管理
        Router::group('/context', function() {
            Router::get('/list', fn($ctx) => ContextCacheHandler::list());
            Router::get('/stats', fn($ctx) => BookHandler::getCacheStatistics($ctx));
            Router::post('/create', fn($ctx) => ContextCacheHandler::create($ctx));
            Router::post('/create-for-book', fn($ctx) => ContextCacheHandler::createForBook($ctx));
            Router::post('/delete', fn($ctx) => ContextCacheHandler::delete($ctx));
            Router::post('/get', fn($ctx) => ContextCacheHandler::get($ctx));
        });
    });
    
    // ===================================
    // MCP 服务器管理 (/api/mcp/*)
    // ===================================
    Router::group('/mcp', function() {
        Router::any('/servers', function($ctx) {
            return $ctx->method() === 'POST' ? MCPHandler::saveServers($ctx->request()) : MCPHandler::getServers();
        });
        Router::get('/status', fn($ctx) => ['enabled' => true, 'url' => 'http://' . MCP_SERVER_HOST . ':' . MCP_SERVER_PORT . '/mcp']);
    });
    
    // ===================================
    // 动态路由示例（带类型验证和安全检查）
    // ===================================
    
    // 示例 1: 整数参数（自动转换为 int，防止 SQL 注入）
    Router::get('/example/user/{id:int}', fn($ctx) => [
        'message' => '获取用户信息（安全）',
        'userId' => $ctx->param('id'),
        'type' => gettype($ctx->param('id')),  // 返回 "integer"
        'safe' => 'ID 已验证为整数，可安全用于数据库查询'
    ]);
    
    // 示例 2: 多个类型参数
    Router::get('/example/user/{userId:int}/post/{postId:int}', fn($ctx) => [
        'message' => '获取用户的文章',
        'userId' => $ctx->param('userId'),     // int
        'postId' => $ctx->param('postId'),     // int
        'safe' => '所有 ID 都已验证为整数'
    ]);
    
    // 示例 3: RESTful API（带类型验证）
    Router::get('/example/books/{id:int}', fn($ctx) => [
        'action' => 'GET',
        'bookId' => $ctx->param('id'),
        'sql_safe' => true
    ]);
    
    Router::put('/example/books/{id:int}', fn($ctx) => [
        'action' => 'PUT (更新)',
        'bookId' => $ctx->param('id')
    ]);
    
    Router::delete('/example/books/{id:int}', fn($ctx) => [
        'action' => 'DELETE (删除)',
        'bookId' => $ctx->param('id')
    ]);
    
    // 示例 4: 字母参数（用户名等）
    Router::get('/example/profile/{username:alpha}', fn($ctx) => [
        'message' => '获取用户资料',
        'username' => $ctx->param('username'),  // 只包含字母
        'safe' => '用户名已过滤，只包含 a-zA-Z'
    ]);
    
    // 示例 5: Slug 参数（URL 友好）
    Router::get('/example/article/{slug:slug}', fn($ctx) => [
        'message' => '获取文章',
        'slug' => $ctx->param('slug'),  // hello-world-123
        'safe' => 'Slug 已标准化为小写字母、数字和连字符'
    ]);
    
    // 示例 6: UUID 参数
    Router::get('/example/order/{orderId:uuid}', fn($ctx) => [
        'message' => '获取订单',
        'orderId' => $ctx->param('orderId'),  // 550e8400-e29b-41d4-a716-446655440000
        'safe' => 'UUID 格式已验证'
    ]);
    
    // 示例 7: 路径参数（文件路径，已防护路径遍历）
    Router::get('/example/file/{path:path}', fn($ctx) => [
        'message' => '获取文件',
        'path' => $ctx->param('path'),  // docs/readme.txt
        'safe' => '路径已清理，防止 ../ 攻击'
    ]);
    
    // 示例 8: 任意参数（HTML 转义，防止 XSS）
    Router::get('/example/search/{query}', fn($ctx) => [
        'message' => '搜索',
        'query' => $ctx->param('query'),  // 自动 HTML 转义
        'safe' => '查询已 HTML 转义，防止 XSS 攻击'
    ]);
});
