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
            // Logger::debug('[ASR Stream] Deepgram 未初始化，忽略音频数据');
            return;
        }
        
        // 检查 Deepgram 是否真正连接成功
        if (!$session['deepgram']->isConnected()) {
            // Logger::debug('[ASR Stream] Deepgram 握手未完成，缓存音频数据');
            return;
        }
        
        try {
            // 转发音频数据到 Deepgram
            $session['deepgram']->sendAudio($data);
            // Logger::debug('[ASR Stream] 音频数据已发送', [
            //     'size' => strlen($data)
            // ]);
        } catch (\Exception $e) {
            Logger::error('[ASR Stream] 发送音频失败', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 启动 Deepgram 连接（每次创建新连接）
     */
    private static function startDeepgram(TcpConnection $connection, string $language, string $model): void
    {
        $connectionId = spl_object_id($connection);
        
        try {
            // 发送"正在连接"状态
            $connection->send(json_encode([
                'type' => 'connecting',
                'message' => 'Connecting to Deepgram...'
            ]));
            
            $deepgram = new DeepgramStreamClient();
            
            // 记录开始连接时间，用于超时检测
            $connectStartTime = time();
            
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
                        // 分析错误类型并提供更友好的提示
                        $friendlyMessage = self::getFriendlyErrorMessage($error);
                        
                        $connection->send(json_encode([
                            'type' => 'error',
                            'message' => $friendlyMessage,
                            'original_error' => $error
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
                            'type' => 'deepgram_closed',
                            'message' => 'Deepgram connection closed'
                        ]));
                    } catch (\Exception $e) {
                        // 忽略发送错误
                    }
                }
            );
            
            self::$sessions[$connectionId]['deepgram'] = $deepgram;
            self::$sessions[$connectionId]['connect_time'] = $connectStartTime;
            
            // 发送连接成功消息
            $connection->send(json_encode([
                'type' => 'started',
                'language' => $language,
                'model' => $model,
                'message' => 'Deepgram connected successfully'
            ]));
            
            Logger::info('[ASR Stream] Deepgram 启动成功', [
                'connection_id' => $connectionId,
                'language' => $language,
                'model' => $model,
                'connect_duration' => time() - $connectStartTime
            ]);
            
        } catch (\Throwable $e) {
            Logger::error('[ASR Stream] Deepgram 启动失败', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // 提供友好的错误信息
            $friendlyMessage = self::getFriendlyErrorMessage($e->getMessage());
            
            $connection->send(json_encode([
                'type' => 'error',
                'message' => $friendlyMessage,
                'original_error' => $e->getMessage()
            ]));
        }
    }
    
    /**
     * 将技术错误转换为用户友好的提示
     */
    private static function getFriendlyErrorMessage(string $error): string
    {
        $error = strtolower($error);
        
        // API Key 相关
        if (strpos($error, 'api key') !== false || strpos($error, 'unauthorized') !== false || strpos($error, '401') !== false) {
            return 'Deepgram API 认证失败，请检查 API Key 配置';
        }
        
        // 网络连接相关
        if (strpos($error, 'connection') !== false || strpos($error, 'timeout') !== false || strpos($error, 'network') !== false) {
            return 'Deepgram 服务器连接超时，请检查网络连接';
        }
        
        // DNS 解析失败
        if (strpos($error, 'dns') !== false || strpos($error, 'resolve') !== false) {
            return 'DNS 解析失败，无法连接到 Deepgram 服务器';
        }
        
        // 服务器错误
        if (strpos($error, '500') !== false || strpos($error, '503') !== false) {
            return 'Deepgram 服务暂时不可用，请稍后再试';
        }
        
        // 速率限制
        if (strpos($error, 'rate limit') !== false || strpos($error, '429') !== false) {
            return 'API 调用频率超限，请稍后再试';
        }
        
        // SSL/TLS 相关
        if (strpos($error, 'ssl') !== false || strpos($error, 'tls') !== false || strpos($error, 'certificate') !== false) {
            return 'SSL 连接失败，请检查服务器网络配置';
        }
        
        // 默认返回原始错误
        return 'Deepgram 错误: ' . $error;
    }
    
    /**
     * 停止录音（主动断开 Deepgram 连接）
     */
    private static function stopDeepgram(TcpConnection $connection): void
    {
        $connectionId = spl_object_id($connection);
        $session = self::$sessions[$connectionId] ?? null;
        
        if ($session && $session['deepgram']) {
            try {
                // 主动断开 Deepgram 连接，避免15秒无音频自动断开
                $session['deepgram']->close();
                self::$sessions[$connectionId]['deepgram'] = null;
                
                $connection->send(json_encode([
                    'type' => 'stopped'
                ]));
                
                Logger::info('[ASR Stream] 录音已停止，Deepgram 连接已关闭', [
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
