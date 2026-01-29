<?php
/**
 * Deepgram 流式语音识别客户端
 * 使用 WebSocket 实时接收语音并返回识别结果
 */

namespace SmartBook\AI;

use SmartBook\Logger;
use Workerman\Connection\AsyncTcpConnection;

class DeepgramStreamClient
{
    private string $apiKey;
    private ?AsyncTcpConnection $connection = null;
    private $onTranscript;
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
     * 连接到 Deepgram WebSocket
     */
    public function connect(
        string $language = 'zh-CN',
        string $model = 'nova-2',
        ?callable $onTranscript = null,
        ?callable $onError = null,
        ?callable $onClose = null
    ): void {
        $this->onTranscript = $onTranscript;
        $this->onError = $onError;
        $this->onClose = $onClose;
        
        // 构建 WebSocket URL（不包含 token）
        $params = http_build_query([
            'model' => $model,
            'language' => $language,
            'encoding' => 'linear16',
            'sample_rate' => 16000,
            'channels' => 1,
            'punctuate' => 'true',
            'smart_format' => 'true',
            'interim_results' => 'true',
            'endpointing' => '300',  // 300ms 静音自动断句
            'utterance_end_ms' => '1000',  // 1秒静音结束语句
        ]);
        
        // 按照官方文档的 wss 客户端方式：ws:// + port 443 + transport ssl
        $wsUrl = "ws://api.deepgram.com:443/v1/listen?{$params}";
        
        Logger::info('[Deepgram Stream] 连接到 Deepgram WebSocket', [
            'language' => $language,
            'model' => $model
        ]);
        
        // SSL context options
        $context = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]
        ];
        
        // 创建 WebSocket 连接
        $this->connection = new AsyncTcpConnection($wsUrl, $context);
        
        // 设置为 SSL 传输（wss）
        $this->connection->transport = 'ssl';
        
        // 设置 WebSocket 类型为二进制（ArrayBuffer）
        $this->connection->websocketType = \Workerman\Protocols\Websocket::BINARY_TYPE_ARRAYBUFFER;
        
        // 设置 Authorization header（虽然被标记为 deprecated，但 WebSocket 客户端仍需使用）
        $this->connection->headers = [
            'Authorization' => 'Token ' . $this->apiKey,
        ];
        
        // TCP 连接成功（三次握手完成）
        $this->connection->onConnect = function($connection) {
            Logger::info('[Deepgram Stream] TCP 连接成功');
        };
        
        // WebSocket 握手成功
        $this->connection->onWebSocketConnect = function($connection, $response) {
            $this->isConnected = true;
            Logger::info('[Deepgram Stream] WebSocket 握手成功');
        };
        
        // 接收消息
        $this->connection->onMessage = function($connection, $data) {
            $this->handleMessage($data);
        };
        
        // 连接错误
        $this->connection->onError = function($connection, $code, $msg) {
            Logger::error('[Deepgram Stream] WebSocket 错误', [
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
            Logger::info('[Deepgram Stream] WebSocket 连接关闭');
            
            if ($this->onClose) {
                call_user_func($this->onClose);
            }
        };
        
        // 开始连接
        $this->connection->connect();
    }
    
    /**
     * 发送音频数据
     */
    public function sendAudio(string $audioData): void
    {
        if (!$this->isConnected || !$this->connection) {
            throw new \Exception('WebSocket not connected');
        }
        
        // 发送二进制音频数据（WebSocket binary frame）
        // Workerman: send($data) 默认发送文本帧
        // 需要使用 send($data, false) 发送二进制帧，或者直接 send($data)
        $this->connection->send($audioData);
        
        Logger::debug('[Deepgram Stream] 已发送音频数据', [
            'size' => strlen($audioData)
        ]);
    }
    
    /**
     * 处理接收到的消息
     */
    private function handleMessage(string $data): void
    {
        try {
            // 记录原始消息（直接在消息中）
            $dataPreview = substr($data, 0, 200);
            Logger::info('[Deepgram Stream] 收到消息: ' . $dataPreview);
            
            $message = json_decode($data, true);
            
            if (!$message) {
                Logger::warn('[Deepgram Stream] 无法解析消息为 JSON: ' . $dataPreview);
                return;
            }
            
            // 记录消息类型
            $messageType = $message['type'] ?? 'unknown';
            Logger::info('[Deepgram Stream] 消息类型: ' . $messageType);
            
            // Metadata 消息（连接建立成功）
            if ($messageType === 'Metadata') {
                Logger::info('[Deepgram Stream] 收到 Metadata，连接已建立', [
                    'request_id' => $message['request_id'] ?? 'unknown',
                    'model_info' => $message['model_info'] ?? [],
                    'full_data' => $data // 记录完整数据
                ]);
                return; // 不需要进一步处理
            }
            
            // 识别结果
            if ($messageType === 'Results') {
                $channel = $message['channel'] ?? [];
                $alternatives = $channel['alternatives'] ?? [];
                
                if (!empty($alternatives)) {
                    $alternative = $alternatives[0];
                    $transcript = $alternative['transcript'] ?? '';
                    $confidence = $alternative['confidence'] ?? 0;
                    $isFinal = ($message['is_final'] ?? false) || ($message['speech_final'] ?? false);
                    
                    if (!empty($transcript)) {
                        Logger::info('[Deepgram Stream] 识别结果', [
                            'transcript' => $transcript,
                            'confidence' => $confidence,
                            'is_final' => $isFinal
                        ]);
                        
                        if ($this->onTranscript) {
                            call_user_func($this->onTranscript, [
                                'transcript' => $transcript,
                                'confidence' => $confidence,
                                'is_final' => $isFinal,
                                'words' => $alternative['words'] ?? []
                            ]);
                        }
                    }
                }
            }
            
            // 错误消息
            if (isset($message['type']) && $message['type'] === 'Error') {
                $errorMsg = $message['description'] ?? 'Unknown error';
                Logger::error('[Deepgram Stream] 错误消息', ['error' => $errorMsg]);
                
                if ($this->onError) {
                    call_user_func($this->onError, $errorMsg);
                }
            }
            
        } catch (\Exception $e) {
            Logger::error('[Deepgram Stream] 消息处理失败', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 关闭连接
     */
    public function close(): void
    {
        if ($this->connection) {
            // 发送关闭信号
            $this->connection->send(json_encode(['type' => 'CloseStream']), true);
            $this->connection->close();
            $this->connection = null;
            $this->isConnected = false;
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
