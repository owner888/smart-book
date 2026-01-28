<?php
/**
 * HTTP/WebSocket 请求处理函数（入口文件）
 * 
 * 已模块化的函数已移至对应的 Handler 类：
 * - ConfigHandler: 配置和模型管理
 * - ChatHandler: 聊天功能
 * - BookHandler: 书籍管理
 * - TTSHandler: 语音合成
 * - ASRHandler: 语音识别
 * - StreamHelper: SSE 工具
 * 
 * 本文件保留：主入口、WebSocket、Context Cache、MCP 等未模块化功能
 */

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use SmartBook\Http\RequestLogger;
use SmartBook\Http\Router;

// 加载路由定义
require_once __DIR__ . '/routes.php';

// ===================================
// HTTP 主入口
// ===================================

function handleHttpRequest(TcpConnection $connection, Request $request): void
{
    $startTime = RequestLogger::start($request);
    
    $path = $request->path();
    $method = $request->method();
    
    $jsonHeaders = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type',
    ];
    
    if ($method === 'OPTIONS') {
        $connection->send(new Response(200, $jsonHeaders, ''));
        RequestLogger::end($request, 200, $startTime, $connection);
        return;
    }
    
    try {
        // CORS headers for all responses
        $corsHeaders = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        ];
        
        if ($path === '/favicon.ico') {
            $icoPath = dirname(__DIR__, 2) . '/static/favicon.ico';
            if (file_exists($icoPath)) {
                $connection->send(new Response(200, array_merge([
                    'Content-Type' => 'image/x-icon', 
                    'Cache-Control' => 'public, max-age=86400'
                ], $corsHeaders), file_get_contents($icoPath)));
                RequestLogger::end($request, 200, $startTime, $connection);
            } else {
                $connection->send(new Response(204, $corsHeaders, ''));
                RequestLogger::end($request, 204, $startTime, $connection);
            }
            return;
        }
        
        if ($path === '/' || $path === '/index.html') {
            $indexHtmlPath = dirname(__DIR__, 2) . '/index.html';
            if (file_exists($indexHtmlPath)) {
                $connection->send(new Response(200, array_merge(['Content-Type' => 'text/html; charset=utf-8'], $corsHeaders), file_get_contents($indexHtmlPath)));
                RequestLogger::end($request, 200, $startTime, $connection);
                return;
            }
        }
        
        if (str_starts_with($path, '/pages/')) {
            $pagePath = dirname(__DIR__, 2) . $path;
            if (file_exists($pagePath)) {
                $connection->send(new Response(200, array_merge(['Content-Type' => 'text/html; charset=utf-8'], $corsHeaders), file_get_contents($pagePath)));
                RequestLogger::end($request, 200, $startTime, $connection);
                return;
            }
        }
        
        if (str_starts_with($path, '/static/')) {
            $filePath = dirname(__DIR__, 2) . $path;
            if (file_exists($filePath)) {
                $ext = pathinfo($filePath, PATHINFO_EXTENSION);
                $mimeTypes = [
                    'css' => 'text/css', 
                    'js' => 'application/javascript', 
                    'map' => 'application/json',
                    'png' => 'image/png', 
                    'jpg' => 'image/jpeg', 
                    'svg' => 'image/svg+xml',
                    'woff2' => 'font/woff2',
                    'woff' => 'font/woff',
                    'ttf' => 'font/ttf',
                    'eot' => 'application/vnd.ms-fontobject',
                ];
                $connection->send(new Response(200, array_merge(['Content-Type' => $mimeTypes[$ext] ?? 'application/octet-stream'], $corsHeaders), file_get_contents($filePath)));
                RequestLogger::end($request, 200, $startTime, $connection);
                return;
            }
        }
        
        // Chrome DevTools Protocol
        if ($path === '/.well-known/appspecific/com.chrome.devtools.json') {
            $connection->send(new Response(200, array_merge(['Content-Type' => 'application/json'], $corsHeaders), json_encode([
                'webSocketDebuggerUrl' => 'ws://' . WS_SERVER_HOST . ':' . WS_SERVER_PORT
            ])));
            RequestLogger::end($request, 200, $startTime, $connection);
            return;
        }
        
        $result = Router::dispatch($connection, $request);
        
        if ($result === null) {
            RequestLogger::end($request, 200, $startTime, $connection);
            return;
        }
        
        $statusCode = isset($result['error']) ? 404 : 200;
        $connection->send(new Response($statusCode, $jsonHeaders, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
        RequestLogger::end($request, $statusCode, $startTime, $connection);
        
    } catch (Exception $e) {
        $connection->send(new Response(500, $jsonHeaders, json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE)));
        RequestLogger::end($request, 500, $startTime, $connection);
    }
}

// ===================================
// 所有业务逻辑已迁移到专业模块
// ===================================
// 
// 已迁移模块列表：
// - ConfigHandler: 配置管理
// - ChatHandler: 聊天功能（HTTP SSE）
// - BookHandler: 书籍管理
// - TTSHandler: 语音合成
// - ASRHandler: 语音识别
// - CacheHandler: 缓存统计
// - ContextCacheHandler: Context Cache 管理
// - EnhancedWriterHandler: 增强版续写
// - MCPHandler: MCP 服务器管理
// - StreamHelper: SSE 流式工具
// 
// 请在 routes.php 中查看路由配置
// ===================================

