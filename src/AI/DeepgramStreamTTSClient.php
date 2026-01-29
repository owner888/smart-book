<?php
/**
 * Deepgram 流式 TTS 客户端
 * 支持实时文本转语音流式输出
 */

namespace SmartBook\AI;

use SmartBook\Logger;
use Workerman\Connection\AsyncTcpConnection;

class DeepgramStreamTTSClient
{
    private string $apiKey;
    private ?AsyncTcpConnection $connection = null;
    private $onAudio;  // 音频数据回调
    private $onError;
    private $onClose;
    private bool $isConnected = false;
    
    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? $_ENV['DEEPGRAM_API_KEY'] ?? '';
        
        if (empty($this->apiKey)) {
            throw new \Exception('Deepgram API key is required');
        }
    }
    
    /**
     * 连接到 Deepgram TTS WebSocket
     */
    public function connect(
        string $model = 'aura-2-asteria-en',  // WebSocket 使用基础模型（不带语言后缀）
        string $encoding = 'linear16',  // WebSocket 流式只支持 linear16/mulaw/alaw
        int $sampleRate = 24000,
        ?callable $onAudio = null,
        ?callable $onError = null,
        ?callable $onClose = null
    ): void {
        $this->onAudio = $onAudio;
        $this->onError = $onError;
        $this->onClose = $onClose;
        
        // 构建 WebSocket URL
        // 注意：MP3 格式不支持 sample_rate 参数
        $params = [
            'model' => $model,
            'encoding' => $encoding,
        ];
        
        // 只有非 MP3 格式才添加 sample_rate
        if ($encoding !== 'mp3') {
            $params['sample_rate'] = $sampleRate;
        }
        
        $queryString = http_build_query($params);
        
        $wsUrl = "ws://api.deepgram.com:443/v1/speak?{$queryString}";
        
        Logger::info('[Deepgram TTS Stream] 连接到 Deepgram TTS WebSocket', [
            'model' => $model,
            'encoding' => $encoding
        ]);
        
        // SSL context
        $context = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]
        ];
        
        // 创建 WebSocket 连接
        $this->connection = new AsyncTcpConnection($wsUrl, $context);
        
        // 设置为 SSL 传输
        $this->connection->transport = 'ssl';
        
        // 设置 WebSocket 类型为二进制
        $this->connection->websocketType = \Workerman\Protocols\Websocket::BINARY_TYPE_ARRAYBUFFER;
        
        // 设置 Authorization header
        $this->connection->headers = [
            'Authorization' => 'Token ' . $this->apiKey,
        ];
        
        // TCP 连接成功
        $this->connection->onConnect = function($connection) {
            Logger::info('[Deepgram TTS Stream] TCP 连接成功');
        };
        
        // WebSocket 握手成功
        $this->connection->onWebSocketConnect = function($connection, $response) {
            $this->isConnected = true;
            Logger::info('[Deepgram TTS Stream] WebSocket 握手成功');
        };
        
        // 接收音频数据
        $this->connection->onMessage = function($connection, $data) {
            $this->handleAudioData($data);
        };
        
        // 连接错误
        $this->connection->onError = function($connection, $code, $msg) {
            Logger::error('[Deepgram TTS Stream] WebSocket 错误', [
                'code' => $code,
                'message' => $msg
            ]);
            
            if ($this->onError) {
                call_user_func($this->onError, $msg);
            }
        };
        
        // 连接关闭
        $this->connection->onClose = function($connection) {
            $this->isConnected = false;
            Logger::info('[Deepgram TTS Stream] WebSocket 连接关闭');
            
            if ($this->onClose) {
                call_user_func($this->onClose);
            }
        };
        
        // 开始连接
        $this->connection->connect();
    }
    
    /**
     * 发送文本（支持流式发送）
     */
    public function sendText(string $text): void
    {
        if (!$this->isConnected || !$this->connection) {
            throw new \Exception('WebSocket not connected');
        }
        
        // 发送 JSON 格式的文本
        $message = json_encode(['type' => 'Speak', 'text' => $text]);
        $this->connection->send($message, true);  // true 表示文本帧
        
        Logger::debug('[Deepgram TTS Stream] 已发送文本', [
            'text_length' => mb_strlen($text)
        ]);
    }
    
    /**
     * 发送文本结束信号
     */
    public function flush(): void
    {
        if (!$this->isConnected || !$this->connection) {
            return;
        }
        
        // 发送 Flush 消息表示文本结束
        $message = json_encode(['type' => 'Flush']);
        $this->connection->send($message, true);
        
        Logger::info('[Deepgram TTS Stream] 已发送 Flush 信号');
    }
    
    /**
     * 处理接收到的音频数据
     */
    private function handleAudioData($data): void
    {
        // 检查是否是 JSON 消息（元数据或错误）
        if (is_string($data) && strlen($data) > 0 && $data[0] === '{') {
            $message = json_decode($data, true);
            
            if (isset($message['type'])) {
                if ($message['type'] === 'Error') {
                    $error = $message['description'] ?? 'Unknown error';
                    Logger::error('[Deepgram TTS Stream] 错误', ['error' => $error]);
                    
                    if ($this->onError) {
                        call_user_func($this->onError, $error);
                    }
                } elseif ($message['type'] === 'Metadata') {
                    Logger::info('[Deepgram TTS Stream] 收到 Metadata');
                }
            }
            return;
        }
        
        // 二进制音频数据
        if (!empty($data)) {
            Logger::debug('[Deepgram TTS Stream] 收到音频数据', [
                'size' => strlen($data)
            ]);
            
            if ($this->onAudio) {
                call_user_func($this->onAudio, $data);
            }
        }
    }
    
    /**
     * 关闭连接
     */
    public function close(): void
    {
        if ($this->connection) {
            // 发送关闭信号
            $message = json_encode(['type' => 'Close']);
            $this->connection->send($message, true);
            $this->connection->close();
            $this->connection = null;
            $this->isConnected = false;
            
            Logger::info('[Deepgram TTS Stream] 连接已关闭');
        }
    }
    
    /**
     * 检查连接状态
     */
    public function isConnected(): bool
    {
        return $this->isConnected;
    }
}
