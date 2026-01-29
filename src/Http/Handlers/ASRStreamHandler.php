<?php
/**
 * ASR 流式识别 WebSocket 处理器
 * iOS 客户端通过 WebSocket 连接，实时发送音频数据，实时接收识别结果
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Logger;
use SmartBook\AI\DeepgramStreamClient;
use Workerman\Connection\TcpConnection;

class ASRStreamHandler
{
    private static array $sessions = [];
    
    /**
     * 处理 WebSocket 连接
     */
    public static function onConnect(TcpConnection $connection): void
    {
        $connectionId = spl_object_id($connection);
        
        Logger::info('[ASR Stream] 客户端连接', [
            'connection_id' => $connectionId,
            'remote_ip' => $connection->getRemoteIp(),
            'remote_port' => $connection->getRemotePort()
        ]);
        
        // 初始化会话
        self::$sessions[$connectionId] = [
            'deepgram' => null,
            'language' => 'zh-CN',
            'model' => 'nova-2'
        ];
        
        // 发送欢迎消息
        $connection->send(json_encode([
            'type' => 'connected',
            'message' => 'WebSocket connected successfully'
        ]));
    }
    
    /**
     * 处理 WebSocket 消息
     */
    public static function onMessage(TcpConnection $connection, $data): void
    {
        $connectionId = spl_object_id($connection);
        
        // 检查是否是 JSON 控制消息
        if (is_string($data) && $data[0] === '{') {
            self::handleControlMessage($connection, $data);
            return;
        }
        
        // 二进制音频数据
        self::handleAudioData($connection, $data);
    }
    
    /**
     * 处理控制消息
     */
    private static function handleControlMessage(TcpConnection $connection, string $data): void
    {
        $connectionId = spl_object_id($connection);
        
        try {
            $message = json_decode($data, true);
            
            if (!$message) {
                return;
            }
            
            $type = $message['type'] ?? '';
            
            switch ($type) {
                case 'start':
                    // 开始识别
                    $language = $message['language'] ?? 'zh-CN';
                    $model = $message['model'] ?? 'nova-2';
                    
                    self::$sessions[$connectionId]['language'] = $language;
                    self::$sessions[$connectionId]['model'] = $model;
                    
                    self::startDeepgram($connection, $language, $model);
                    break;
                    
                case 'stop':
                    // 停止识别
                    self::stopDeepgram($connection);
                    break;
                    
                case 'ping':
                    // 心跳
                    $connection->send(json_encode(['type' => 'pong']));
                    break;
            }
            
        } catch (\Exception $e) {
            Logger::error('[ASR Stream] 控制消息处理失败', [
                'error' => $e->getMessage()
            ]);
            
            $connection->send(json_encode([
                'type' => 'error',
                'message' => $e->getMessage()
            ]));
        }
    }
    
    /**
     * 处理音频数据
     */
    private static function handleAudioData(TcpConnection $connection, $data): void
    {
        $connectionId = spl_object_id($connection);
        $session = self::$sessions[$connectionId] ?? null;
        
        if (!$session || !$session['deepgram']) {
            Logger::warn('[ASR Stream] Deepgram 未连接，忽略音频数据');
            return;
        }
        
        try {
            // 转发音频数据到 Deepgram
            $session['deepgram']->sendAudio($data);
        } catch (\Exception $e) {
            Logger::error('[ASR Stream] 发送音频失败', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 启动 Deepgram 连接
     */
    private static function startDeepgram(TcpConnection $connection, string $language, string $model): void
    {
        $connectionId = spl_object_id($connection);
        
        try {
            $deepgram = new DeepgramStreamClient();
            
            // 连接到 Deepgram
            $deepgram->connect(
                $language,
                $model,
                // onTranscript - 接收识别结果
                function($result) use ($connection, $connectionId) {
                    try {
                        $connection->send(json_encode([
                            'type' => 'transcript',
                            'transcript' => $result['transcript'],
                            'confidence' => $result['confidence'],
                            'is_final' => $result['is_final'],
                            'words' => $result['words'] ?? []
                        ]));
                    } catch (\Exception $e) {
                        Logger::error('[ASR Stream] 发送识别结果失败', [
                            'error' => $e->getMessage()
                        ]);
                    }
                },
                // onError - 错误处理
                function($error) use ($connection, $connectionId) {
                    Logger::error('[ASR Stream] Deepgram 错误', [
                        'connection_id' => $connectionId,
                        'error' => $error
                    ]);
                    
                    try {
                        $connection->send(json_encode([
                            'type' => 'error',
                            'message' => $error
                        ]));
                    } catch (\Exception $e) {
                        // 忽略发送错误
                    }
                },
                // onClose - 连接关闭
                function() use ($connection, $connectionId) {
                    Logger::info('[ASR Stream] Deepgram 连接关闭', [
                        'connection_id' => $connectionId
                    ]);
                    
                    try {
                        $connection->send(json_encode([
                            'type' => 'deepgram_closed'
                        ]));
                    } catch (\Exception $e) {
                        // 忽略发送错误
                    }
                }
            );
            
            self::$sessions[$connectionId]['deepgram'] = $deepgram;
            
            // 发送连接成功消息
            $connection->send(json_encode([
                'type' => 'started',
                'language' => $language,
                'model' => $model
            ]));
            
            Logger::info('[ASR Stream] Deepgram 启动成功', [
                'connection_id' => $connectionId,
                'language' => $language,
                'model' => $model
            ]);
            
        } catch (\Throwable $e) {
            Logger::error('[ASR Stream] Deepgram 启动失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $connection->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to start Deepgram: ' . $e->getMessage()
            ]));
        }
    }
    
    /**
     * 停止 Deepgram 连接
     */
    private static function stopDeepgram(TcpConnection $connection): void
    {
        $connectionId = spl_object_id($connection);
        $session = self::$sessions[$connectionId] ?? null;
        
        if ($session && $session['deepgram']) {
            try {
                $session['deepgram']->close();
                self::$sessions[$connectionId]['deepgram'] = null;
                
                $connection->send(json_encode([
                    'type' => 'stopped'
                ]));
                
                Logger::info('[ASR Stream] Deepgram 已停止', [
                    'connection_id' => $connectionId
                ]);
            } catch (\Exception $e) {
                Logger::error('[ASR Stream] 停止 Deepgram 失败', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    /**
     * 处理 WebSocket 关闭
     */
    public static function onClose(TcpConnection $connection): void
    {
        $connectionId = spl_object_id($connection);
        
        Logger::info('[ASR Stream] 客户端断开', [
            'connection_id' => $connectionId
        ]);
        
        // 清理 Deepgram 连接
        $session = self::$sessions[$connectionId] ?? null;
        if ($session && $session['deepgram']) {
            try {
                $session['deepgram']->close();
            } catch (\Exception $e) {
                Logger::error('[ASR Stream] 关闭 Deepgram 失败', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // 清理会话
        unset(self::$sessions[$connectionId]);
    }
    
    /**
     * 处理 WebSocket 错误
     */
    public static function onError(TcpConnection $connection, $code, $msg): void
    {
        $connectionId = spl_object_id($connection);
        
        Logger::error('[ASR Stream] 连接错误', [
            'connection_id' => $connectionId,
            'code' => $code,
            'message' => $msg
        ]);
    }
}
