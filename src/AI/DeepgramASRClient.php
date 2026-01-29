<?php
/**
 * Deepgram Speech-to-Text 客户端
 * 
 * Deepgram 提供了高精度、低延迟的语音识别服务
 * 支持多种语言、音频格式，并提供实时和预录音频识别
 */

namespace SmartBook\AI;

class DeepgramASRClient
{
    private string $apiKey;
    private string $baseUrl = 'https://api.deepgram.com/v1';
    
    // 支持的语言
    const LANGUAGES = [
        'zh' => '中文',
        'zh-CN' => '中文（简体）',
        'zh-TW' => '中文（繁体）',
        'en' => 'English',
        'en-US' => 'English (US)',
        'en-GB' => 'English (UK)',
        'en-AU' => 'English (Australia)',
        'en-NZ' => 'English (New Zealand)',
        'en-IN' => 'English (India)',
        'ja' => '日本語',
        'ko' => '한국어',
        'es' => 'Español',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'it' => 'Italiano',
        'pt' => 'Português',
        'ru' => 'Русский',
        'ar' => 'العربية',
        'hi' => 'हिन्दी',
        'th' => 'ไทย',
        'vi' => 'Tiếng Việt',
        'id' => 'Bahasa Indonesia',
        'tr' => 'Türkçe',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'sv' => 'Svenska',
        'no' => 'Norsk',
        'da' => 'Dansk',
        'fi' => 'Suomi',
    ];
    
    // 支持的音频编码
    const ENCODINGS = [
        'WEBM_OPUS' => 'audio/webm',
        'LINEAR16' => 'audio/wav',
        'FLAC' => 'audio/flac',
        'MP3' => 'audio/mp3',
        'OGG_OPUS' => 'audio/ogg',
        'WAV' => 'audio/wav',
        'M4A' => 'audio/m4a',
        'AAC' => 'audio/aac',
    ];
    
    // Deepgram 模型
    const MODELS = [
        'nova-2' => 'Nova-2 (最新、最准确)',
        'nova' => 'Nova (平衡性能)',
        'enhanced' => 'Enhanced (增强)',
        'base' => 'Base (基础)',
        'whisper' => 'Whisper (OpenAI)',
    ];
    
    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? $_ENV['DEEPGRAM_API_KEY'] ?? '';
    }
    
    /**
     * 语音转文本（预录音频识别）
     * 
     * @param string $audioContent Base64 编码的音频数据
     * @param string $encoding 音频编码格式
     * @param int $sampleRateHertz 采样率（可选，Deepgram 会自动检测）
     * @param string $languageCode 语言代码
     * @param string $model 使用的模型
     * @param array $options 额外选项
     * @return array ['transcript' => 识别结果, 'confidence' => 置信度, ...]
     */
    public function recognize(
        string $audioContent,
        string $encoding = 'WEBM_OPUS',
        int $sampleRateHertz = 48000,
        string $languageCode = 'zh-CN',
        string $model = 'nova-2',
        array $options = []
    ): array {
        if (empty($this->apiKey)) {
            throw new \Exception('Deepgram API Key 未配置');
        }
        
        // 构建查询参数
        $queryParams = array_merge([
            'model' => $model,
            'language' => $languageCode,
            'punctuate' => 'true',           // 自动标点
            'smart_format' => 'true',        // 智能格式化
            'utterances' => 'true',          // 分段话语
            'diarize' => 'false',            // 说话人识别
            'detect_language' => 'false',    // 语言检测
        ], $options);
        
        // 构建 URL
        $url = "{$this->baseUrl}/listen?" . http_build_query($queryParams);
        
        // 解码 Base64 音频数据
        $audioData = base64_decode($audioContent);
        
        // 确定 Content-Type
        $contentType = self::ENCODINGS[$encoding] ?? 'application/octet-stream';
        
        // 发起请求
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $audioData,
            CURLOPT_HTTPHEADER => [
                "Authorization: Token {$this->apiKey}",
                "Content-Type: {$contentType}",
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($error) {
            throw new \Exception("Deepgram ASR 请求失败: {$error}");
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMsg = $result['err_msg'] ?? $result['message'] ?? '未知错误';
            throw new \Exception("Deepgram API 错误 ({$httpCode}): {$errorMsg}");
        }
        
        // 解析结果
        return $this->parseResponse($result, $languageCode);
    }
    
    /**
     * 解析 Deepgram 响应
     */
    private function parseResponse(array $result, string $languageCode): array
    {
        $transcript = '';
        $confidence = 0;
        $words = [];
        $utterances = [];
        
        // 获取第一个 channel 的结果
        $channels = $result['results']['channels'] ?? [];
        if (empty($channels)) {
            return [
                'transcript' => '',
                'confidence' => 0,
                'language' => $languageCode,
                'duration' => 0,
                'words' => [],
            ];
        }
        
        $channel = $channels[0];
        $alternatives = $channel['alternatives'] ?? [];
        
        if (!empty($alternatives)) {
            $alternative = $alternatives[0];
            $transcript = $alternative['transcript'] ?? '';
            $confidence = $alternative['confidence'] ?? 0;
            $words = $alternative['words'] ?? [];
        }
        
        // 获取分段信息
        $utterances = $channel['utterances'] ?? [];
        
        // 获取音频元数据
        $metadata = $result['metadata'] ?? [];
        $duration = $metadata['duration'] ?? 0;
        $requestId = $metadata['request_id'] ?? '';
        
        // 计算费用（Deepgram 按音频时长计费）
        // Nova-2: $0.0043/分钟 = $0.000071667/秒
        // Base: $0.0125/分钟 = $0.000208333/秒
        $costPerSecond = 0.000071667; // Nova-2 的费用
        $cost = $duration * $costPerSecond;
        
        return [
            'transcript' => trim($transcript),
            'confidence' => round($confidence * 100, 1),
            'language' => $languageCode,
            'duration' => round($duration, 2),
            'words' => $words,
            'utterances' => $utterances,
            'cost' => $cost,
            'costFormatted' => $cost < 0.01 ? '<$0.01' : '$' . number_format($cost, 4),
            'request_id' => $requestId,
            'provider' => 'deepgram',
        ];
    }
    
    /**
     * 获取支持的语言列表
     */
    public function getLanguages(): array
    {
        return self::LANGUAGES;
    }
    
    /**
     * 获取支持的模型列表
     */
    public function getModels(): array
    {
        return self::MODELS;
    }
    
    /**
     * 检测最佳语言（简单实现）
     */
    public static function detectLanguage(string $text): string
    {
        // 检测是否包含中文字符
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) {
            return 'zh-CN';
        }
        // 检测是否包含日文字符
        if (preg_match('/[\x{3040}-\x{309f}\x{30a0}-\x{30ff}]/u', $text)) {
            return 'ja';
        }
        // 检测是否包含韩文字符
        if (preg_match('/[\x{ac00}-\x{d7af}]/u', $text)) {
            return 'ko';
        }
        // 检测是否包含泰文字符
        if (preg_match('/[\x{0e00}-\x{0e7f}]/u', $text)) {
            return 'th';
        }
        // 检测是否包含阿拉伯文字符
        if (preg_match('/[\x{0600}-\x{06ff}]/u', $text)) {
            return 'ar';
        }
        return 'en-US';
    }
    
    /**
     * 获取默认语言
     */
    public static function getDefaultLanguage(): string
    {
        return 'zh-CN';
    }
    
    /**
     * 获取默认模型
     */
    public static function getDefaultModel(): string
    {
        return 'nova-2';
    }
    
    /**
     * 语音转文本（流式识别）
     * 
     * @param resource $audioStream 音频流
     * @param callable $callback 回调函数，接收识别结果
     * @param string $encoding 音频编码格式
     * @param string $languageCode 语言代码
     * @param string $model 使用的模型
     */
    public function recognizeStream(
        $audioStream,
        callable $callback,
        string $encoding = 'LINEAR16',
        string $languageCode = 'zh-CN',
        string $model = 'nova-2'
    ): void {
        if (empty($this->apiKey)) {
            throw new \Exception('Deepgram API Key 未配置');
        }
        
        // 构建 WebSocket URL（实时识别）
        $queryParams = [
            'model' => $model,
            'language' => $languageCode,
            'punctuate' => 'true',
            'smart_format' => 'true',
            'interim_results' => 'true',
            'encoding' => strtolower($encoding),
        ];
        
        $wsUrl = "wss://api.deepgram.com/v1/listen?" . http_build_query($queryParams);
        
        // 注意：这里需要 WebSocket 客户端库来实现流式识别
        // 可以使用 textalk/websocket 或 ratchet/pawl 等库
        throw new \Exception('流式识别需要 WebSocket 支持，请使用 recognizeStreamHttp 方法或安装 WebSocket 库');
    }
}
