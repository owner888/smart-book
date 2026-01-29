<?php
/**
 * Deepgram Text-to-Speech 客户端
 * 超低延迟，高质量语音合成
 */

namespace SmartBook\AI;

use SmartBook\Logger;

class DeepgramTTSClient
{
    private string $apiKey;
    private string $baseUrl = 'https://api.deepgram.com/v1/speak';
    
    // Deepgram Aura 可用语音模型
    const VOICES = [
        'zh' => [
            'aura-asteria-zh' => ['name' => 'Asteria (女声)', 'gender' => 'FEMALE', 'language' => 'Chinese'],
            'aura-luna-zh' => ['name' => 'Luna (女声)', 'gender' => 'FEMALE', 'language' => 'Chinese'],
            'aura-stella-zh' => ['name' => 'Stella (女声)', 'gender' => 'FEMALE', 'language' => 'Chinese'],
            'aura-athena-zh' => ['name' => 'Athena (女声)', 'gender' => 'FEMALE', 'language' => 'Chinese'],
            'aura-hera-zh' => ['name' => 'Hera (女声)', 'gender' => 'FEMALE', 'language' => 'Chinese'],
            'aura-orion-zh' => ['name' => 'Orion (男声)', 'gender' => 'MALE', 'language' => 'Chinese'],
            'aura-arcas-zh' => ['name' => 'Arcas (男声)', 'gender' => 'MALE', 'language' => 'Chinese'],
            'aura-perseus-zh' => ['name' => 'Perseus (男声)', 'gender' => 'MALE', 'language' => 'Chinese'],
            'aura-angus-zh' => ['name' => 'Angus (男声)', 'gender' => 'MALE', 'language' => 'Chinese'],
            'aura-orpheus-zh' => ['name' => 'Orpheus (男声)', 'gender' => 'MALE', 'language' => 'Chinese'],
            'aura-helios-zh' => ['name' => 'Helios (男声)', 'gender' => 'MALE', 'language' => 'Chinese'],
            'aura-zeus-zh' => ['name' => 'Zeus (男声)', 'gender' => 'MALE', 'language' => 'Chinese'],
        ],
        'en' => [
            'aura-2-asteria-en' => ['name' => 'Asteria (女声)', 'gender' => 'FEMALE', 'language' => 'English'],
            'aura-luna-en' => ['name' => 'Luna (女声)', 'gender' => 'FEMALE', 'language' => 'English'],
            'aura-stella-en' => ['name' => 'Stella (女声)', 'gender' => 'FEMALE', 'language' => 'English'],
            'aura-athena-en' => ['name' => 'Athena (女声)', 'gender' => 'FEMALE', 'language' => 'English'],
            'aura-hera-en' => ['name' => 'Hera (女声)', 'gender' => 'FEMALE', 'language' => 'English'],
            'aura-orion-en' => ['name' => 'Orion (男声)', 'gender' => 'MALE', 'language' => 'English'],
            'aura-arcas-en' => ['name' => 'Arcas (男声)', 'gender' => 'MALE', 'language' => 'English'],
            'aura-perseus-en' => ['name' => 'Perseus (男声)', 'gender' => 'MALE', 'language' => 'English'],
            'aura-angus-en' => ['name' => 'Angus (男声)', 'gender' => 'MALE', 'language' => 'English'],
            'aura-orpheus-en' => ['name' => 'Orpheus (男声)', 'gender' => 'MALE', 'language' => 'English'],
            'aura-helios-en' => ['name' => 'Helios (男声)', 'gender' => 'MALE', 'language' => 'English'],
            'aura-zeus-en' => ['name' => 'Zeus (男声)', 'gender' => 'MALE', 'language' => 'English'],
        ],
    ];
    
    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? $_ENV['DEEPGRAM_API_KEY'] ?? '';
        
        if (empty($this->apiKey)) {
            throw new \Exception('Deepgram API key is required');
        }
    }
    
    /**
     * 文本转语音
     * @return array ['audio' => base64音频, 'format' => 'mp3']
     */
    public function synthesize(
        string $text,
        string $model = 'aura-asteria-zh',
        string $encoding = 'mp3',
        int $sampleRate = 24000
    ): array {
        if (empty($this->apiKey)) {
            throw new \Exception('Deepgram API Key 未配置');
        }
        
        // 限制文本长度
        if (strlen($text) > 10000) {
            $text = mb_substr($text, 0, 9000) . '...';
        }
        
        // 构建请求 URL
        $params = http_build_query([
            'model' => $model,
            'encoding' => $encoding,
            'sample_rate' => $sampleRate,
        ]);
        
        $url = "{$this->baseUrl}?{$params}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['text' => $text]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Token ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("Deepgram TTS 请求失败: {$error}");
        }
        
        if ($httpCode !== 200) {
            $result = json_decode($response, true);
            $errorMsg = $result['error'] ?? $result['err_msg'] ?? '未知错误';
            throw new \Exception("Deepgram TTS API 错误 ({$httpCode}): {$errorMsg}");
        }
        
        // Deepgram 返回的是原始音频数据（不是 JSON）
        $audioContent = base64_encode($response);
        
        // 计算费用
        $charCount = mb_strlen($text);
        // Deepgram Aura 定价：$0.015/1000 字符
        $cost = ($charCount / 1000) * 0.015;
        
        return [
            'audio' => $audioContent,
            'format' => $encoding,
            'charCount' => $charCount,
            'cost' => $cost,
            'costFormatted' => $cost < 0.01 ? '<$0.01' : '$' . number_format($cost, 4),
            'model' => $model,
        ];
    }
    
    /**
     * 获取可用语音列表
     */
    public function getVoices(): array
    {
        return self::VOICES;
    }
    
    /**
     * 检测文本语言
     */
    public static function detectLanguage(string $text): string
    {
        // 检测是否包含中文字符
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) {
            return 'zh';  // 中文
        }
        return 'en';  // 英文
    }
    
    /**
     * 根据语言获取默认语音
     */
    public static function getDefaultVoice(string $languageCode): string
    {
        return match ($languageCode) {
            'zh', 'zh-CN', 'cmn-CN' => 'aura-asteria-zh',  // 中文女声（Asteria）
            'en', 'en-US' => 'aura-2-asteria-en',  // 英文女声
            default => 'aura-asteria-zh',
        };
    }
}
