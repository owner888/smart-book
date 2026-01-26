<?php
/**
 * ASR 语音识别处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Http\Context;
use SmartBook\AI\GoogleASRClient;
use Workerman\Protocols\Http\Response;

class ASRHandler
{
    /**
     * 语音转文本
     */
    public static function recognize(Context $ctx): ?array
    {
        $connection = $ctx->connection();
        $body = $ctx->jsonBody() ?? [];
        $audio = $body['audio'] ?? '';
        $encoding = $body['encoding'] ?? 'WEBM_OPUS';
        $sampleRate = intval($body['sample_rate'] ?? 48000);
        $language = $body['language'] ?? null;
        
        if (empty($audio)) {
            return ['error' => 'Missing audio data'];
        }
        
        try {
            $asrClient = new GoogleASRClient();
            
            if (!$language) {
                $language = GoogleASRClient::getDefaultLanguage();
            }
            
            $result = $asrClient->recognize($audio, $encoding, $sampleRate, $language);
            
            $jsonHeaders = [
                'Content-Type' => 'application/json; charset=utf-8',
                'Access-Control-Allow-Origin' => '*',
            ];
            
            $connection->send(new Response(200, $jsonHeaders, json_encode([
                'success' => true,
                'transcript' => $result['transcript'],
                'confidence' => $result['confidence'],
                'language' => $result['language'],
                'duration' => $result['duration'] ?? 0,
                'cost' => $result['cost'] ?? 0,
                'costFormatted' => $result['costFormatted'] ?? '',
            ], JSON_UNESCAPED_UNICODE)));
            
            return null;
            
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * 获取支持的语言列表
     */
    public static function getLanguages(): array
    {
        try {
            $asrClient = new GoogleASRClient();
            return [
                'languages' => $asrClient->getLanguages(),
                'default' => GoogleASRClient::getDefaultLanguage(),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
