<?php
/**
 * TTS 流式合成 WebSocket 处理器
 * iOS 发送文本，实时接收音频流
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Logger;
use SmartBook\AI\DeepgramStreamTTSClient;
use SmartBook\AI\GoogleStreamTTSClient;
use Workerman\Connection\TcpConnection;

class TTSStreamHandler
{
    private static array $sessions = [];
    
    /**
     * 处理 WebSocket 连接
     */
    public static function onConnect(TcpConnection $connection): void
    {
        $connectionId = spl_object_id($connection);
        
        Logger::info('[TTS Stream] 客户端连接', [
            'connection_id' => $connectionId,
            'remote_ip' => $connection->getRemoteIp()
        ]);
        
        // 初始化会话
        self::$sessions[$connectionId] = [
            'deepgram' => null,
            'model' => 'aura-2-asteria-en',
            'encoding' => 'linear16',
            'text_buffer' => [],  // 文本缓冲区（握手前缓存）
        ];
        
        // 发送欢迎消息
        $connection->send(json_encode([
            'type' => 'connected',
            'message' => 'TTS WebSocket connected'
        ]));
    }
    
    /**
     * 处理 WebSocket 消息
     */
    public static function onMessage(TcpConnection $connection, $data): void
    {
        // 只处理 JSON 控制消息
        if (is_string($data) && $data[0] === '{') {
            self::handleControlMessage($connection, $data);
        }
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
                    // 开始 TTS 会话
                    $model = $message['model'] ?? null;
                    $provider = $message['provider'] ?? 'auto';  // auto/deepgram/google
                    $encoding = $message['encoding'] ?? 'mp3';
                    $sampleRate = intval($message['sample_rate'] ?? 24000);
                    
                    // 自动选择 provider
                    if ($provider === 'auto') {
                        // 如果没有指定模型，检测语言
                        if (!$model) {
                            $provider = 'google';  // 默认 Google（支持中文）
                            $model = 'cmn-CN-Wavenet-D';
                        } elseif (str_contains($model, 'aura')) {
                            $provider = 'deepgram';
                        } elseif (str_contains($model, 'cmn') || str_contains($model, 'zh')) {
                            $provider = 'google';
                        } else {
                            $provider = 'deepgram';  // 默认 Deepgram
                            $model = 'aura-2-asteria-en';
                        }
                    }
                    
                    self::$sessions[$connectionId]['model'] = $model;
                    self::$sessions[$connectionId]['provider'] = $provider;
                    self::$sessions[$connectionId]['encoding'] = $encoding;
                    
                    if ($provider === 'google') {
                        self::startGoogleTTS($connection, $model, $encoding, $sampleRate);
                    } else {
                        self::startDeepgramTTS($connection, $model, $encoding, $sampleRate);
                    }
                    break;
                    
                case 'text':
                    // 接收文本，转换为音频
                    $text = $message['text'] ?? '';
                    if (!empty($text)) {
                        self::synthesizeText($connection, $text);
                    }
                    break;
                    
                case 'flush':
                    // 文本结束，刷新音频
                    self::flushTTS($connection);
                    break;
                    
                case 'stop':
                    // 停止 TTS
                    self::stopDeepgramTTS($connection);
                    break;
                    
                case 'ping':
                    // 心跳
                    $connection->send(json_encode(['type' => 'pong']));
                    break;
            }
            
        } catch (\Exception $e) {
            Logger::error('[TTS Stream] 控制消息处理失败', [
                'error' => $e->getMessage()
            ]);
            
            $connection->send(json_encode([
                'type' => 'error',
                'message' => $e->getMessage()
            ]));
        }
    }
    
    /**
     * 启动 Google TTS 连接
     */
    private static function startGoogleTTS(
        TcpConnection $connection,
        string $model,
        string $encoding,
        int $sampleRate
    ): void {
        $connectionId = spl_object_id($connection);
        
        try {
            $googleTTS = new GoogleStreamTTSClient();
            
            // 连接到 Google TTS（模拟）
            $googleTTS->connect(
                $model,
                $encoding,
                $sampleRate,
                // onAudio - 接收音频数据
                function($audioData) use ($connection, $connectionId) {
                    try {
                        // 转发音频数据给客户端
                        $connection->send($audioData);
                        
                        Logger::debug('[TTS Stream] 音频数据已发送', [
                            'size' => strlen($audioData)
                        ]);
                    } catch (\Exception $e) {
                        Logger::error('[TTS Stream] 发送音频失败', [
                            'error' => $e->getMessage()
                        ]);
                    }
                },
                // onReady - 立即就绪
                function() use ($connection, $connectionId) {
                    Logger::info('[TTS Stream] Google TTS 已就绪');
                    
                    // 发送缓存的文本
                    self::sendBufferedText($connection);
                },
                // onError
                function($error) use ($connection, $connectionId) {
                    Logger::error('[TTS Stream] Google TTS 错误', [
                        'error' => $error
                    ]);
                    
                    $connection->send(json_encode([
                        'type' => 'error',
                        'message' => $error
                    ]));
                },
                // onClose
                function() use ($connection, $connectionId) {
                    Logger::info('[TTS Stream] Google TTS 连接关闭');
                }
            );
            
            self::$sessions[$connectionId]['deepgram'] = $googleTTS;  // 使用同一个字段
            
            // 发送连接成功消息
            $connection->send(json_encode([
                'type' => 'started',
                'model' => $model,
                'provider' => 'google',
                'encoding' => $encoding
            ]));
            
            Logger::info('[TTS Stream] Google TTS 启动成功', [
                'model' => $model
            ]);
            
        } catch (\Throwable $e) {
            Logger::error('[TTS Stream] Google TTS 启动失败', [
                'error' => $e->getMessage()
            ]);
            
            $connection->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to start Google TTS: ' . $e->getMessage()
            ]));
        }
    }
    
    /**
     * 启动 Deepgram TTS 连接
     */
    private static function startDeepgramTTS(
        TcpConnection $connection,
        string $model,
        string $encoding,
        int $sampleRate
    ): void {
        $connectionId = spl_object_id($connection);
        
        try {
            $deepgramTTS = new DeepgramStreamTTSClient();
            
            // 连接到 Deepgram TTS
            $deepgramTTS->connect(
                $model,
                $encoding,
                $sampleRate,
                // onAudio - 接收音频数据
                function($audioData) use ($connection, $connectionId) {
                    try {
                        // 直接转发二进制音频数据给客户端
                        $connection->send($audioData);
                        
                        Logger::debug('[TTS Stream] 音频数据已发送', [
                            'size' => strlen($audioData)
                        ]);
                    } catch (\Exception $e) {
                        Logger::error('[TTS Stream] 发送音频失败', [
                            'error' => $e->getMessage()
                        ]);
                    }
                },
                // onReady - Deepgram 就绪后发送缓存的文本
                function() use ($connection, $connectionId) {
                    Logger::info('[TTS Stream] Deepgram TTS 已就绪');
                    
                    // 发送缓存的文本
                    self::sendBufferedText($connection);
                },
                // onError
                function($error) use ($connection, $connectionId) {
                    Logger::error('[TTS Stream] Deepgram TTS 错误', [
                        'error' => $error
                    ]);
                    
                    $connection->send(json_encode([
                        'type' => 'error',
                        'message' => $error
                    ]));
                },
                // onClose
                function() use ($connection, $connectionId) {
                    Logger::info('[TTS Stream] Deepgram TTS 连接关闭');
                    
                    $connection->send(json_encode([
                        'type' => 'deepgram_closed'
                    ]));
                }
            );
            
            self::$sessions[$connectionId]['deepgram'] = $deepgramTTS;
            
            // 发送连接成功消息
            $connection->send(json_encode([
                'type' => 'started',
                'model' => $model,
                'encoding' => $encoding
            ]));
            
            Logger::info('[TTS Stream] Deepgram TTS 启动成功', [
                'model' => $model
            ]);
            
        } catch (\Throwable $e) {
            Logger::error('[TTS Stream] Deepgram TTS 启动失败', [
                'error' => $e->getMessage()
            ]);
            
            $connection->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to start Deepgram TTS: ' . $e->getMessage()
            ]));
        }
    }
    
    /**
     * 合成文本为音频
     */
    private static function synthesizeText(TcpConnection $connection, string $text): void
    {
        $connectionId = spl_object_id($connection);
        $session = self::$sessions[$connectionId] ?? null;
        
        if (!$session || !$session['deepgram']) {
            Logger::warn('[TTS Stream] Deepgram TTS 未初始化，缓存文本');
            self::$sessions[$connectionId]['text_buffer'][] = $text;
            return;
        }
        
        // 检查 Deepgram TTS 连接状态
        if (!$session['deepgram']->isConnected()) {
            // 缓存文本，等待握手成功后发送
            self::$sessions[$connectionId]['text_buffer'][] = $text;
            Logger::debug('[TTS Stream] Deepgram TTS 未连接，缓存文本', [
                'buffer_size' => count(self::$sessions[$connectionId]['text_buffer'])
            ]);
            return;
        }
        
        try {
            // 发送文本到 Deepgram TTS
            $session['deepgram']->sendText($text);
            
            Logger::debug('[TTS Stream] 文本已发送到 Deepgram', [
                'text_length' => mb_strlen($text)
            ]);
        } catch (\Exception $e) {
            Logger::error('[TTS Stream] 发送文本失败', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 发送缓存的文本
     */
    private static function sendBufferedText(TcpConnection $connection): void
    {
        $connectionId = spl_object_id($connection);
        $session = self::$sessions[$connectionId] ?? null;
        
        if (!$session || empty($session['text_buffer'])) {
            return;
        }
        
        $buffer = $session['text_buffer'];
        self::$sessions[$connectionId]['text_buffer'] = [];
        
        Logger::info('[TTS Stream] 发送缓存的文本', [
            'count' => count($buffer)
        ]);
        
        foreach ($buffer as $text) {
            self::synthesizeText($connection, $text);
        }
    }
    
    /**
     * 刷新 TTS（完成文本输入）
     */
    private static function flushTTS(TcpConnection $connection): void
    {
        $connectionId = spl_object_id($connection);
        $session = self::$sessions[$connectionId] ?? null;
        
        if ($session && $session['deepgram']) {
            $session['deepgram']->flush();
            Logger::info('[TTS Stream] 已刷新 Deepgram TTS');
        }
    }
    
    /**
     * 停止 Deepgram TTS
     */
    private static function stopDeepgramTTS(TcpConnection $connection): void
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
                
                Logger::info('[TTS Stream] Deepgram TTS 已停止');
            } catch (\Exception $e) {
                Logger::error('[TTS Stream] 停止 Deepgram TTS 失败', [
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
        
        Logger::info('[TTS Stream] 客户端断开', [
            'connection_id' => $connectionId
        ]);
        
        // 清理 Deepgram TTS 连接
        $session = self::$sessions[$connectionId] ?? null;
        if ($session && $session['deepgram']) {
            try {
                $session['deepgram']->close();
            } catch (\Exception $e) {
                Logger::error('[TTS Stream] 关闭 Deepgram TTS 失败', [
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
        
        Logger::error('[TTS Stream] 连接错误', [
            'connection_id' => $connectionId,
            'code' => $code,
            'message' => $msg
        ]);
    }
}
