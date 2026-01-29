<?php
/**
 * ASR 语音识别处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Logger;
use SmartBook\Http\Context;
use SmartBook\Http\ErrorHandler;
use SmartBook\AI\GoogleASRClient;
use SmartBook\AI\DeepgramASRClient;
use Workerman\Protocols\Http\Response;

class ASRHandler
{
    /**
     * 获取 ASR 客户端实例
     * 
     * @return GoogleASRClient|DeepgramASRClient
     */
    private static function getASRClient()
    {
        $provider = $_ENV['ASR_PROVIDER'] ?? 'google';
        
        switch (strtolower($provider)) {
            case 'deepgram':
                return new DeepgramASRClient();
            case 'google':
            default:
                return new GoogleASRClient();
        }
    }
    
    /**
     * 获取 ASR 提供商信息
     */
    private static function getProviderInfo(): array
    {
        $provider = $_ENV['ASR_PROVIDER'] ?? 'google';
        $model = $_ENV['ASR_MODEL'] ?? null;
        
        return [
            'provider' => $provider,
            'model' => $model,
        ];
    }
    
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
        $model = $body['model'] ?? $_ENV['ASR_MODEL'] ?? null;
        
        $providerInfo = self::getProviderInfo();
        
        Logger::info('[ASR] 语音识别', [
            'provider' => $providerInfo['provider'],
            'encoding' => $encoding,
            'sample_rate' => $sampleRate,
            'language' => $language,
            'model' => $model
        ]);
        
        $asrClient = self::getASRClient();
        
        // 确定默认语言
        if (!$language) {
            if ($asrClient instanceof DeepgramASRClient) {
                $language = DeepgramASRClient::getDefaultLanguage();
            } else {
                $language = GoogleASRClient::getDefaultLanguage();
            }
        }
        
        // 调用识别方法
        if ($asrClient instanceof DeepgramASRClient) {
            $model = $model ?? DeepgramASRClient::getDefaultModel();
            $result = $asrClient->recognize($audio, $encoding, $sampleRate, $language, $model);
        } else {
            $result = $asrClient->recognize($audio, $encoding, $sampleRate, $language);
        }
        
        ErrorHandler::logOperation('ASR::recognize', 'success', [
            'provider' => $result['provider'] ?? $providerInfo['provider'],
            'language' => $result['language'],
            'confidence' => $result['confidence']
        ]);
        
        $jsonHeaders = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ];
        
        // 构建响应数据
        $responseData = [
            'success' => true,
            'transcript' => $result['transcript'],
            'confidence' => $result['confidence'],
            'language' => $result['language'],
            'duration' => $result['duration'] ?? 0,
            'cost' => $result['cost'] ?? 0,
            'costFormatted' => $result['costFormatted'] ?? '',
            'provider' => $result['provider'] ?? $providerInfo['provider'],
        ];
        
        // Deepgram 额外信息
        if (isset($result['words'])) {
            $responseData['words'] = $result['words'];
        }
        if (isset($result['utterances'])) {
            $responseData['utterances'] = $result['utterances'];
        }
        if (isset($result['request_id'])) {
            $responseData['request_id'] = $result['request_id'];
        }
        
        $connection->send(new Response(200, $jsonHeaders, json_encode($responseData, JSON_UNESCAPED_UNICODE)));
        
        return null;
    }
    
    /**
     * 获取支持的语言列表
     */
    public static function getLanguages(): array
    {
        Logger::info('[ASR] 获取语言列表');
        
        $asrClient = self::getASRClient();
        $providerInfo = self::getProviderInfo();
        
        $responseData = [
            'languages' => $asrClient->getLanguages(),
            'provider' => $providerInfo['provider'],
        ];
        
        // 确定默认语言
        if ($asrClient instanceof DeepgramASRClient) {
            $responseData['default'] = DeepgramASRClient::getDefaultLanguage();
            $responseData['models'] = $asrClient->getModels();
            $responseData['defaultModel'] = DeepgramASRClient::getDefaultModel();
        } else {
            $responseData['default'] = GoogleASRClient::getDefaultLanguage();
        }
        
        return $responseData;
    }
    
    /**
     * 获取 ASR 配置信息
     */
    public static function getConfig(): array
    {
        $providerInfo = self::getProviderInfo();
        $asrClient = self::getASRClient();
        
        $config = [
            'provider' => $providerInfo['provider'],
            'languages' => $asrClient->getLanguages(),
        ];
        
        if ($asrClient instanceof DeepgramASRClient) {
            $config['default_language'] = DeepgramASRClient::getDefaultLanguage();
            $config['models'] = $asrClient->getModels();
            $config['default_model'] = $providerInfo['model'] ?? DeepgramASRClient::getDefaultModel();
        } else {
            $config['default_language'] = GoogleASRClient::getDefaultLanguage();
        }
        
        return $config;
    }
}
