<?php
/**
 * Google Cloud Text-to-Speech 客户端
 */

namespace SmartBook\AI;

class GoogleTTSClient
{
    private string $apiKey;
    private string $baseUrl = 'https://texttospeech.googleapis.com/v1';
    
    // 可用的语音（注意：中文语言代码是 cmn-CN，不是 zh-CN）
    const VOICES = [
        'cmn-CN' => [
            'cmn-CN-Wavenet-D' => ['name' => 'Wavenet-D (女声)', 'gender' => 'FEMALE'],
            'cmn-CN-Wavenet-A' => ['name' => 'Wavenet-A (女声)', 'gender' => 'FEMALE'],
            'cmn-CN-Wavenet-B' => ['name' => 'Wavenet-B (男声)', 'gender' => 'MALE'],
            'cmn-CN-Wavenet-C' => ['name' => 'Wavenet-C (男声)', 'gender' => 'MALE'],
            'cmn-CN-Standard-A' => ['name' => 'Standard-A (女声)', 'gender' => 'FEMALE'],
            'cmn-CN-Standard-B' => ['name' => 'Standard-B (男声)', 'gender' => 'MALE'],
            'cmn-CN-Standard-C' => ['name' => 'Standard-C (男声)', 'gender' => 'MALE'],
            'cmn-CN-Standard-D' => ['name' => 'Standard-D (女声)', 'gender' => 'FEMALE'],
        ],
        'en-US' => [
            'en-US-Neural2-C' => ['name' => 'Neural2-C (女声)', 'gender' => 'FEMALE'],
            'en-US-Neural2-E' => ['name' => 'Neural2-E (女声)', 'gender' => 'FEMALE'],
            'en-US-Neural2-A' => ['name' => 'Neural2-A (男声)', 'gender' => 'MALE'],
            'en-US-Neural2-D' => ['name' => 'Neural2-D (男声)', 'gender' => 'MALE'],
            'en-US-Wavenet-C' => ['name' => 'Wavenet-C (女声)', 'gender' => 'FEMALE'],
            'en-US-Wavenet-E' => ['name' => 'Wavenet-E (女声)', 'gender' => 'FEMALE'],
            'en-US-Wavenet-A' => ['name' => 'Wavenet-A (男声)', 'gender' => 'MALE'],
            'en-US-Wavenet-D' => ['name' => 'Wavenet-D (男声)', 'gender' => 'MALE'],
        ],
    ];
    
    public function __construct(?string $apiKey = null)
    {
        // 使用传入的 key，或者 GEMINI_API_KEY 常量（与 Gemini 共享同一个 Google API Key）
        $this->apiKey = $apiKey ?? (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '') ?? $_ENV['GOOGLE_API_KEY'] ?? '';
    }
    
    /**
     * 文本转语音
     * @return array ['audio' => base64音频, 'format' => 'mp3']
     */
    public function synthesize(
        string $text,
        string $voiceId = 'cmn-CN-Wavenet-D',
        string $languageCode = 'cmn-CN',
        float $speakingRate = 1.0,
        float $pitch = 0.0
    ): array {
        if (empty($this->apiKey)) {
            throw new \Exception('Google API Key 未配置');
        }
        
        // 限制文本长度（Google TTS 限制 5000 字节）
        if (strlen($text) > 4500) {
            $text = mb_substr($text, 0, 4000) . '...';
        }
        
        $url = "{$this->baseUrl}/text:synthesize?key={$this->apiKey}";
        
        $data = [
            'input' => ['text' => $text],
            'voice' => [
                'languageCode' => $languageCode,
                'name' => $voiceId,
            ],
            'audioConfig' => [
                'audioEncoding' => 'MP3',
                'speakingRate' => $speakingRate,  // 0.25 - 4.0
                'pitch' => $pitch,  // -20.0 - 20.0
                'effectsProfileId' => ['headphone-class-device'],
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
        // curl_close 在 PHP 8.0+ 中自动处理
        
        if ($error) {
            throw new \Exception("TTS 请求失败: {$error}");
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMsg = $result['error']['message'] ?? '未知错误';
            throw new \Exception("TTS API 错误 ({$httpCode}): {$errorMsg}");
        }
        
        if (!isset($result['audioContent'])) {
            throw new \Exception('TTS 响应中没有音频内容');
        }
        
        return [
            'audio' => $result['audioContent'],  // base64 编码的 MP3
            'format' => 'mp3',
        ];
    }
    
    /**
     * 获取可用语音列表（从 API 获取）
     */
    public function getVoices(): array
    {
        // 尝试从 API 获取真实可用的语音
        $apiVoices = $this->listVoicesFromAPI();
        if (!empty($apiVoices)) {
            return $apiVoices;
        }
        // 如果 API 调用失败，返回预设列表
        return self::VOICES;
    }
    
    /**
     * 从 Google Cloud TTS API 获取语音列表
     */
    public function listVoicesFromAPI(): array
    {
        if (empty($this->apiKey)) {
            return [];
        }
        
        $url = "{$this->baseUrl}/voices?key={$this->apiKey}&languageCode=zh-CN";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            return [];
        }
        
        $result = json_decode($response, true);
        $voices = [];
        
        foreach ($result['voices'] ?? [] as $voice) {
            $langCode = $voice['languageCodes'][0] ?? 'unknown';
            $voiceName = $voice['name'] ?? '';
            $gender = $voice['ssmlGender'] ?? 'NEUTRAL';
            
            // 提取简短名称（如 Standard-A, Wavenet-B 等）
            $shortName = $voiceName;
            if (preg_match('/(Standard|Wavenet|Neural2|Journey|Studio|Polyglot|News|Casual)-([A-Z])/', $voiceName, $m)) {
                $genderLabel = $gender === 'FEMALE' ? '女声' : ($gender === 'MALE' ? '男声' : '');
                $shortName = "{$m[1]}-{$m[2]} ({$genderLabel})";
            }
            
            if (!isset($voices[$langCode])) {
                $voices[$langCode] = [];
            }
            
            $voices[$langCode][$voiceName] = [
                'name' => $shortName,
                'gender' => $gender,
                'naturalSampleRateHertz' => $voice['naturalSampleRateHertz'] ?? 24000,
            ];
        }
        
        return $voices;
    }
    
    /**
     * 检测文本语言（简单实现）
     */
    public static function detectLanguage(string $text): string
    {
        // 检测是否包含中文字符
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) {
            return 'cmn-CN';  // 普通话（中国大陆）
        }
        return 'en-US';
    }
    
    /**
     * 根据语言获取默认语音
     */
    public static function getDefaultVoice(string $languageCode): string
    {
        return match ($languageCode) {
            'cmn-CN', 'zh-CN' => 'cmn-CN-Wavenet-D',  // 中文女声（Wavenet 更自然）
            'en-US' => 'en-US-Neural2-C',  // 英文女声
            default => 'cmn-CN-Wavenet-D',
        };
    }
}
