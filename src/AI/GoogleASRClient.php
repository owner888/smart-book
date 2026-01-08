<?php
/**
 * Google Cloud Speech-to-Text 客户端
 */

namespace SmartBook\AI;

class GoogleASRClient
{
    private string $apiKey;
    private string $baseUrl = 'https://speech.googleapis.com/v1';
    
    // 支持的语言
    const LANGUAGES = [
        'cmn-Hans-CN' => '普通话（中国大陆）',
        'cmn-Hant-TW' => '普通话（台湾）',
        'yue-Hant-HK' => '粤语（香港）',
        'en-US' => 'English (US)',
        'en-GB' => 'English (UK)',
        'ja-JP' => '日本語',
        'ko-KR' => '한국어',
    ];
    
    // 音频编码
    const ENCODINGS = [
        'WEBM_OPUS' => 'audio/webm;codecs=opus',
        'LINEAR16' => 'audio/l16',
        'FLAC' => 'audio/flac',
        'MP3' => 'audio/mp3',
        'OGG_OPUS' => 'audio/ogg;codecs=opus',
    ];
    
    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '') ?? $_ENV['GOOGLE_API_KEY'] ?? '';
    }
    
    /**
     * 语音转文本（同步识别）
     * 
     * @param string $audioContent Base64 编码的音频数据
     * @param string $encoding 音频编码格式
     * @param int $sampleRateHertz 采样率
     * @param string $languageCode 语言代码
     * @return array ['transcript' => 识别结果, 'confidence' => 置信度]
     */
    public function recognize(
        string $audioContent,
        string $encoding = 'WEBM_OPUS',
        int $sampleRateHertz = 48000,
        string $languageCode = 'cmn-Hans-CN'
    ): array {
        if (empty($this->apiKey)) {
            throw new \Exception('Google API Key 未配置');
        }
        
        $url = "{$this->baseUrl}/speech:recognize?key={$this->apiKey}";
        
        // 构建请求数据
        $data = [
            'config' => [
                'encoding' => $encoding,
                'sampleRateHertz' => $sampleRateHertz,
                'languageCode' => $languageCode,
                'enableAutomaticPunctuation' => true,
                'model' => 'latest_long',  // 使用最新的长音频模型
                'useEnhanced' => true,
                // 多语言识别备选
                'alternativeLanguageCodes' => $this->getAlternativeLanguages($languageCode),
            ],
            'audio' => [
                'content' => $audioContent,  // Base64 编码的音频
            ],
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 60,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("ASR 请求失败: {$error}");
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMsg = $result['error']['message'] ?? '未知错误';
            throw new \Exception("ASR API 错误 ({$httpCode}): {$errorMsg}");
        }
        
        // 解析结果
        $transcript = '';
        $confidence = 0;
        $results = $result['results'] ?? [];
        
        foreach ($results as $res) {
            $alternatives = $res['alternatives'] ?? [];
            if (!empty($alternatives)) {
                $transcript .= $alternatives[0]['transcript'] ?? '';
                $confidence = max($confidence, $alternatives[0]['confidence'] ?? 0);
            }
        }
        
        // 计算费用（每 15 秒计费一次，$0.006/15秒 = $0.024/分钟）
        // 音频时长估算：采样率 * 位深 * 时长 ≈ 文件大小
        $audioBytes = strlen(base64_decode($audioContent));
        $estimatedSeconds = $audioBytes / ($sampleRateHertz * 2);  // 假设 16-bit 音频
        $billableUnits = ceil($estimatedSeconds / 15);
        $cost = $billableUnits * 0.006;  // $0.006 per 15 seconds
        
        return [
            'transcript' => $transcript,
            'confidence' => round($confidence * 100, 1),
            'language' => $languageCode,
            'duration' => round($estimatedSeconds, 1),
            'cost' => $cost,
            'costFormatted' => $cost < 0.01 ? '<$0.01' : '$' . number_format($cost, 4),
        ];
    }
    
    /**
     * 获取备选语言代码
     */
    private function getAlternativeLanguages(string $primaryLanguage): array
    {
        // 如果主语言是中文，添加英文作为备选
        if (str_starts_with($primaryLanguage, 'cmn') || str_starts_with($primaryLanguage, 'yue')) {
            return ['en-US'];
        }
        // 如果主语言是英文，添加中文作为备选
        if (str_starts_with($primaryLanguage, 'en')) {
            return ['cmn-Hans-CN'];
        }
        return [];
    }
    
    /**
     * 获取支持的语言列表
     */
    public function getLanguages(): array
    {
        return self::LANGUAGES;
    }
    
    /**
     * 检测最佳语言（简单实现）
     */
    public static function detectLanguage(string $text): string
    {
        // 检测是否包含中文字符
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) {
            return 'cmn-Hans-CN';
        }
        return 'en-US';
    }
    
    /**
     * 获取默认语言
     */
    public static function getDefaultLanguage(): string
    {
        return 'cmn-Hans-CN';
    }
}
