<?php
/**
 * SSE 流式处理工具
 */

namespace SmartBook\Http\Handlers;

use Workerman\Connection\TcpConnection;

class StreamHelper
{
    /**
     * 发送 SSE 事件（带连接检测）
     * 
     * @param TcpConnection $connection Workerman 连接对象
     * @param string $event 事件类型
     * @param string $data 事件数据
     * @return bool 返回 true 表示发送成功，false 表示连接已断开
     */
    public static function sendSSE(TcpConnection $connection, string $event, string $data): bool
    {
        // 检查连接状态
        if ($connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
            \Logger::info("[SSE] 连接已断开，停止发送事件: {$event}");
            return false;
        }
        
        // 构建 SSE 消息
        $lines = explode("\n", $data);
        $message = "event: {$event}\n";
        foreach ($lines as $line) {
            $message .= "data: {$line}\n";
        }
        $message .= "\n";
        
        // 尝试发送
        try {
            $connection->send($message);
            return true;
        } catch (\Exception $e) {
            \Logger::error("[SSE] 发送失败: {$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * 从原始 TCP 数据解析 HTTP 请求
     * 
     * @param string $data 原始 TCP 数据
     * @param TcpConnection $connection TCP 连接
     * @return \Workerman\Protocols\Http\Request|null
     */
    public static function parseHttpRequest(string $data, TcpConnection $connection): ?\Workerman\Protocols\Http\Request
    {
        // 检查数据是否完整
        $inputLength = \Workerman\Protocols\Http::input($data, $connection);
        
        if ($inputLength === 0) {
            return null;
        }
        
        if ($inputLength < 0) {
            $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n");
            return null;
        }
        
        try {
            return \Workerman\Protocols\Http::decode($data, $connection);
        } catch (\Exception $e) {
            $connection->close("HTTP/1.1 400 Bad Request\r\n\r\nParse error: " . $e->getMessage());
            return null;
        }
    }
}
