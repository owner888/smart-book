<?php
/**
 * TTS 语音合成处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Http\Context;
use SmartBook\Http\ErrorHandler;
use SmartBook\AI\GoogleTTSClient;
use Workerman\Protocols\Http\Response;

class TTSHandler
{
    /**
     * 文本转语音
     */
    public static function synthesize(Context $ctx): ?array
    {
        $connection = $ctx->connection();
        $body = $ctx->jsonBody() ?? [];
        
        ErrorHandler::requireParams($body, ['text']);
        
        $text = $body['text'];
        $voice = $body['voice'] ?? null;
        $rate = floatval($body['rate'] ?? 1.0);
        $pitch = floatval($body['pitch'] ?? 0.0);
        
        \Logger::info('[TTS] 语音合成', [
            'text_length' => mb_strlen($text),
            'voice' => $voice,
            'rate' => $rate,
            'pitch' => $pitch
        ]);
        
        $ttsClient = new GoogleTTSClient();
        
        $languageCode = GoogleTTSClient::detectLanguage($text);
        if (!$voice) {
            $voice = GoogleTTSClient::getDefaultVoice($languageCode);
        }
        
        $result = $ttsClient->synthesize($text, $voice, $languageCode, $rate, $pitch);
        
        ErrorHandler::logOperation('TTS::synthesize', 'success', [
            'language' => $languageCode,
            'char_count' => $result['charCount'] ?? 0
        ]);
        
        $jsonHeaders = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ];
        
        $connection->send(new Response(200, $jsonHeaders, json_encode([
            'success' => true,
            'audio' => $result['audio'],
            'format' => $result['format'],
            'voice' => $voice,
            'language' => $languageCode,
            'charCount' => $result['charCount'] ?? 0,
            'cost' => $result['cost'] ?? 0,
            'costFormatted' => $result['costFormatted'] ?? '',
        ], JSON_UNESCAPED_UNICODE)));
        
        return null;
    }
    
    /**
     * 获取可用语音列表
     */
    public static function getVoices(): array
    {
        \Logger::info('[TTS] 获取语音列表');
        
        $ttsClient = new GoogleTTSClient();
        
        return [
            'voices' => $ttsClient->getVoices(),
            'default' => [
                'zh-CN' => GoogleTTSClient::getDefaultVoice('zh-CN'),
                'en-US' => GoogleTTSClient::getDefaultVoice('en-US'),
            ],
        ];
    }
    
    /**
     * 直接从 Google TTS API 获取语音列表（调试用）
     */
    public static function listAPIVoices(): array
    {
        $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
        if (empty($apiKey)) {
            return ['error' => 'GEMINI_API_KEY 未配置'];
        }
        
        $url = "https://texttospeech.googleapis.com/v1/voices?key={$apiKey}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($error) {
            return ['error' => "curl 错误: {$error}"];
        }
        
        if ($httpCode !== 200) {
            $result = json_decode($response, true);
            $errorMsg = $result['error']['message'] ?? '未知错误';
            return [
                'error' => "API 错误 ({$httpCode}): {$errorMsg}",
                'hint' => '请确保在 Google Cloud Console 中启用了 Text-to-Speech API',
                'enable_url' => 'https://console.cloud.google.com/apis/library/texttospeech.googleapis.com',
            ];
        }
        
        $result = json_decode($response, true);
        
        $voicesByLang = [];
        $allLangs = [];
        foreach ($result['voices'] ?? [] as $voice) {
            foreach ($voice['languageCodes'] ?? [] as $langCode) {
                if (!isset($voicesByLang[$langCode])) {
                    $voicesByLang[$langCode] = [];
                }
                $voicesByLang[$langCode][] = [
                    'name' => $voice['name'],
                    'gender' => $voice['ssmlGender'],
                    'sampleRate' => $voice['naturalSampleRateHertz'],
                ];
                $allLangs[$langCode] = true;
            }
        }
        
        return [
            'status' => 'ok',
            'total_voices' => count($result['voices'] ?? []),
            'all_languages' => array_keys($allLangs),
            'cmn-CN' => $voicesByLang['cmn-CN'] ?? [],
            'cmn-TW' => $voicesByLang['cmn-TW'] ?? [],
            'en-US' => $voicesByLang['en-US'] ?? [],
        ];
    }
}
