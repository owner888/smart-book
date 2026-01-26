<?php
/**
 * HTTP/WebSocket 主入口处理器
 */

namespace SmartBook\Http\Handlers;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use SmartBook\Http\Router;
use SmartBook\Http\RequestLogger;

class RequestHandler
{
    /**
     * 处理 HTTP 请求
     */
    public static function handleHttp(TcpConnection $connection, Request $request): void
    {
        // 记录请求开始时间
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
            // favicon.ico
            if ($path === '/favicon.ico') {
                $icoPath = dirname(__DIR__, 3) . '/static/favicon.ico';
                if (file_exists($icoPath)) {
                    $connection->send(new Response(200, [
                        'Content-Type' => 'image/x-icon', 
                        'Cache-Control' => 'public, max-age=86400'
                    ], file_get_contents($icoPath)));
                    RequestLogger::end($request, 200, $startTime, $connection);
                } else {
                    $connection->send(new Response(204, [], ''));
                    RequestLogger::end($request, 204, $startTime, $connection);
                }
                return;
            }
            
            // 首页
            if ($path === '/' || $path === '/index.html') {
                $indexHtmlPath = dirname(__DIR__, 3) . '/index.html';
                if (file_exists($indexHtmlPath)) {
                    $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], file_get_contents($indexHtmlPath)));
                    RequestLogger::end($request, 200, $startTime, $connection);
                    return;
                }
            }
            
            // pages 目录下的页面
            if (str_starts_with($path, '/pages/')) {
                $pagePath = dirname(__DIR__, 3) . $path;
                if (file_exists($pagePath)) {
                    $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], file_get_contents($pagePath)));
                    RequestLogger::end($request, 200, $startTime, $connection);
                    return;
                }
            }
            
            // 静态文件
            if (str_starts_with($path, '/static/')) {
                $filePath = dirname(__DIR__, 3) . $path;
                if (file_exists($filePath)) {
                    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
                    $mimeTypes = [
                        'css' => 'text/css', 
                        'js' => 'application/javascript', 
                        'png' => 'image/png', 
                        'jpg' => 'image/jpeg', 
                        'svg' => 'image/svg+xml',
                        'woff2' => 'font/woff2',
                        'woff' => 'font/woff',
                        'ttf' => 'font/ttf',
                        'eot' => 'application/vnd.ms-fontobject',
                    ];
                    $connection->send(new Response(200, ['Content-Type' => $mimeTypes[$ext] ?? 'application/octet-stream'], file_get_contents($filePath)));
                    RequestLogger::end($request, 200, $startTime, $connection);
                    return;
                }
            }
            
            // API 路由（使用新路由系统）
            $result = Router::dispatch($connection, $request);
            
            // 流式 API 返回 null，记录日志后直接返回
            if ($result === null) {
                RequestLogger::end($request, 200, $startTime, $connection);
                return;
            }
            
            $statusCode = isset($result['error']) ? 404 : 200;
            $connection->send(new Response($statusCode, $jsonHeaders, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
            RequestLogger::end($request, $statusCode, $startTime, $connection);
            
        } catch (\Exception $e) {
            $connection->send(new Response(500, $jsonHeaders, json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE)));
            RequestLogger::end($request, 500, $startTime, $connection);
        }
    }
    
    /**
     * 处理 WebSocket 消息
     */
    public static function handleWebSocket(TcpConnection $connection, string $data): void
    {
        $request = json_decode($data, true);
        if (!$request) {
            $connection->send(json_encode(['error' => 'Invalid JSON']));
            return;
        }
        
        $action = $request['action'] ?? '';
        
        try {
            match ($action) {
                'ask' => WebSocketHandler::streamAsk($connection, $request),
                'chat' => WebSocketHandler::streamChat($connection, $request),
                'continue' => WebSocketHandler::streamContinue($connection, $request),
                default => $connection->send(json_encode(['error' => 'Unknown action']))
            };
        } catch (\Exception $e) {
            $connection->send(json_encode(['error' => $e->getMessage()]));
        }
    }
}
