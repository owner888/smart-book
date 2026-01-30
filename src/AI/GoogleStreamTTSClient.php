<?php
/**
 * Google 流式 TTS 客户端
 * 虽然 Google TTS 不支持 WebSocket，但实现流式接口兼容
 */

namespace SmartBook\AI;

use SmartBook\Logger;

class GoogleStreamTTSClient
{
    private string $apiKey;
    private string $baseUrl = 'https://texttospeech.googleapis.com/v1';
    private $onAudio;
    private $onReady;
    private $onError;
    private $onClose;
    private bool $isConnected = false;
    private array $textBuffer = [];
    private string $voice = 'cmn-CN-Wavenet-B';  // 男声（B/C 是男声，A/D 是女声）
    private string $language = 'cmn-CN';
    
    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '') ?? $_ENV['GOOGLE_API_KEY'] ?? '';
        
        if (empty($this->apiKey)) {
            throw new \Exception('Google API key is required');
        }
    }
    
    /**
     * 连接（模拟 WebSocket 接口）
     */
    public function connect(
        string $model = 'cmn-CN-Wavenet-D',
        string $encoding = 'mp3',
        int $sampleRate = 24000,
        ?callable $onAudio = null,
        ?callable $onReady = null,
        ?callable $onError = null,
        ?callable $onClose = null
    ): void {
        $this->voice = $model;
        $this->language = $this->getLanguageFromVoice($model);
        $this->onAudio = $onAudio;
        $this->onReady = $onReady;
        $this->onError = $onError;
        $this->onClose = $onClose;
        
        // 标记为已连接
        $this->isConnected = true;
        
        Logger::info('[Google TTS Stream] 模拟连接成功', [
            'voice' => $this->voice,
            'language' => $this->language
        ]);
        
        // 立即触发就绪回调
        if ($this->onReady) {
            call_user_func($this->onReady);
        }
    }
    
    /**
     * 发送文本（累积文本）
     */
    public function sendText(string $text): void
    {
        if (!$this->isConnected) {
            throw new \Exception('Not connected');
        }
        
        // 累积文本
        $this->textBuffer[] = $text;
        
        Logger::debug('[Google TTS Stream] 累积文本', [
            'text_length' => mb_strlen($text),
            'buffer_size' => count($this->textBuffer)
        ]);
    }
    
    /**
     * 发送 Flush（合成并发送音频）
     */
    public function flush(): void
    {
        if (!$this->isConnected || empty($this->textBuffer)) {
            return;
        }
        
        // 合并所有文本
        $fullText = implode('', $this->textBuffer);
        $this->textBuffer = [];
        
        Logger::info('[Google TTS Stream] 开始合成音频', [
            'text_length' => mb_strlen($fullText)
        ]);
        
        try {
            // 调用 Google TTS API
            $audioContent = $this->synthesize($fullText);
            
            // 将音频分块发送（模拟流式）
            $chunkSize = 8192;  // 8KB 每块
            $offset = 0;
            $audioData = base64_decode($audioContent);
            $totalSize = strlen($audioData);
            
            // 输出原始音频的 MD5
            $md5 = md5($audioData);
            Logger::info('[Google TTS Stream] 原始音频 MD5: ' . $md5);
            
            while ($offset < $totalSize) {
                $chunk = substr($audioData, $offset, $chunkSize);
                $offset += $chunkSize;
                
                // 发送音频块
                if ($this->onAudio) {
                    call_user_func($this->onAudio, $chunk);
                }
                
                // 模拟网络延迟
                usleep(10000);  // 10ms
            }
            
            Logger::info('[Google TTS Stream] 音频发送完成', [
                'total_size' => $totalSize,
                'chunks' => ceil($totalSize / $chunkSize)
            ]);
            
            // 不自动关闭，让 Handler 控制
            
        } catch (\Exception $e) {
            Logger::error('[Google TTS Stream] 合成失败', [
                'error' => $e->getMessage()
            ]);
            
            if ($this->onError) {
                call_user_func($this->onError, $e->getMessage());
            }
        }
    }
    
    /**
     * 关闭连接
     */
    public function close(): void
    {
        $this->isConnected = false;
        $this->textBuffer = [];
        
        Logger::info('[Google TTS Stream] 连接已关闭');
        
        if ($this->onClose) {
            call_user_func($this->onClose);
        }
    }
    
    /**
     * 检查连接状态
     */
    public function isConnected(): bool
    {
        return $this->isConnected;
    }
    
    /**
     * 实际调用 Google TTS API
     */
    private function synthesize(string $text): string
    {
        $url = "{$this->baseUrl}/text:synthesize?key={$this->apiKey}";
        
        $data = [
            'input' => ['text' => $text],
            'voice' => [
                'languageCode' => $this->language,
                'name' => $this->voice,
            ],
            'audioConfig' => [
                'audioEncoding' => 'MP3',
                'speakingRate' => 1.0,
                'pitch' => 0.0,
            ],
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($error) {
            throw new \Exception("Google TTS 请求失败: {$error}");
        }
        
        if ($httpCode !== 200) {
            $result = json_decode($response, true);
            $errorMsg = $result['error']['message'] ?? '未知错误';
            throw new \Exception("Google TTS API 错误 ({$httpCode}): {$errorMsg}");
        }
        
        $result = json_decode($response, true);
        
        if (!isset($result['audioContent'])) {
            throw new \Exception('Google TTS 响应中没有音频内容');
        }
        
        return $result['audioContent'];
    }
    
    /**
     * 从语音名称提取语言代码
     */
    private function getLanguageFromVoice(string $voice): string
    {
        if (str_starts_with($voice, 'cmn-CN')) {
            return 'cmn-CN';
        } elseif (str_starts_with($voice, 'en-US')) {
            return 'en-US';
        }
        // 默认
        return 'cmn-CN';
    }
}
