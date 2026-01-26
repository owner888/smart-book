<?php
/**
 * ASR 语音识别处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Http\Context;
use SmartBook\Http\ErrorHandler;
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
        
        ErrorHandler::requireParams($body, ['audio']);
        
        $audio = $body['audio'];
        $encoding = $body['encoding'] ?? 'WEBM_OPUS';
        $sampleRate = intval($body['sample_rate'] ?? 48000);
        $language = $body['language'] ?? null;
        
        \Logger::info('[ASR] 语音识别', [
            'encoding' => $encoding,
            'sample_rate' => $sampleRate,
            'language' => $language
        ]);
        
        $asrClient = new GoogleASRClient();
        
        if (!$language) {
            $language = GoogleASRClient::getDefaultLanguage();
        }
        
        $result = $asrClient->recognize($audio, $encoding, $sampleRate, $language);
        
        ErrorHandler::logOperation('ASR::recognize', 'success', [
            'language' => $result['language'],
            'confidence' => $result['confidence']
        ]);
        
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
    }
    
    /**
     * 获取支持的语言列表
     */
    public static function getLanguages(): array
    {
        \Logger::info('[ASR] 获取语言列表');
        
        $asrClient = new GoogleASRClient();
        
        return [
            'languages' => $asrClient->getLanguages(),
            'default' => GoogleASRClient::getDefaultLanguage(),
        ];
    }
}
