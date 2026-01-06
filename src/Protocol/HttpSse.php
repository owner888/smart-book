<?php
/**
 * 自定义 HTTP-SSE 协议
 * 
 * 继承 Workerman 的 HTTP 协议，但为 SSE 请求禁用自动关闭连接
 * 
 * 问题：Workerman 的 HTTP 协议在发送响应后会自动关闭连接
 * 解决：检测 SSE 请求并阻止自动关闭
 */

namespace SmartBook\Protocol;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Request;

class HttpSse
{
    /**
     * 检查数据包是否完整
     * 直接委托给 HTTP 协议
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        return Http::input($buffer, $connection);
    }
    
    /**
     * 解码请求
     * 解析 HTTP 请求并检测是否是 SSE 请求
     */
    public static function decode(string $buffer, TcpConnection $connection): Request
    {
        $request = Http::decode($buffer, $connection);
        
        // 检测 SSE 请求（GET + Accept: text/event-stream）
        if ($request instanceof Request) {
            $method = $request->method();
            $accept = $request->header('Accept', '');
            
            // 如果是 SSE 请求，标记连接为 SSE 模式
            if ($method === 'GET' && str_contains($accept, 'text/event-stream')) {
                $connection->sseMode = true;
            }
        }
        
        return $request;
    }
    
    /**
     * 编码响应
     * 委托给 HTTP 协议，但 SSE 模式下不关闭连接
     */
    public static function encode(mixed $response, TcpConnection $connection): string
    {
        // 如果是原始 HTTP 头字符串（用于 SSE），直接返回
        if (is_string($response) && str_starts_with($response, 'HTTP/')) {
            // SSE 模式：设置 onBufferFull 回调避免缓冲区问题
            if ($connection->sseMode ?? false) {
                $connection->onBufferFull = function ($conn) {
                    // 缓冲区满时暂停接收数据
                    $conn->pauseRecv();
                };
                $connection->onBufferDrain = function ($conn) {
                    // 缓冲区空时恢复接收
                    $conn->resumeRecv();
                };
            }
            return $response;
        }
        
        return Http::encode($response, $connection);
    }
}
