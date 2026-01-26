<?php
/**
 * 增强版续写处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Http\Context;
use SmartBook\Http\ErrorHandler;
use SmartBook\AI\EnhancedStoryWriter;
use SmartBook\AI\GeminiContextCache;
use SmartBook\AI\TokenCounter;
use Workerman\Protocols\Http\Response;

class EnhancedWriterHandler
{
    /**
     * 准备续写环境（创建缓存 + 提取风格样本）
     */
    public static function prepare(Context $ctx): array
    {
        $body = $ctx->jsonBody() ?? [];
        ErrorHandler::requireParams($body, ['book']);
        
        $bookFile = $body['book'];
        $model = $body['model'] ?? 'gemini-2.5-flash';
        
        \Logger::info('[EnhancedWriter] 准备续写环境', ['book' => $bookFile, 'model' => $model]);
        
        $booksDir = dirname(__DIR__, 3) . '/books';
        $bookPath = $booksDir . '/' . $bookFile;
        
        ErrorHandler::requireFile($bookPath, '书籍文件');
        
        $ext = strtolower(pathinfo($bookFile, PATHINFO_EXTENSION));
        if ($ext === 'epub') {
            $content = \SmartBook\Parser\EpubParser::extractText($bookPath);
        } else {
            $content = file_get_contents($bookPath);
        }
        
        if (empty($content)) {
            throw new \Exception('Failed to extract book content');
        }
        
        $writer = new EnhancedStoryWriter(GEMINI_API_KEY, $model);
        $result = $writer->prepareForBook($bookFile, $content);
        
        ErrorHandler::logOperation('EnhancedWriter::prepare', 'success', ['book' => $bookFile]);
        
        return $result;
    }
    
    /**
     * 获取续写状态
     */
    public static function getStatus(Context $ctx): array
    {
        $body = $ctx->jsonBody() ?? [];
        ErrorHandler::requireParams($body, ['book']);
        
        $bookFile = $body['book'];
        
        \Logger::info('[EnhancedWriter] 获取续写状态', ['book' => $bookFile]);
        
        $writer = new EnhancedStoryWriter(GEMINI_API_KEY);
        return $writer->getWriterStatus($bookFile);
    }
    
    /**
     * 增强版续写（使用 Context Cache + Few-shot）
     */
    public static function streamContinue(Context $ctx): ?array
    {
        $connection = $ctx->connection();
        $body = $ctx->jsonBody() ?? [];
        $bookFile = $body['book'] ?? '';
        $prompt = $body['prompt'] ?? '';
        $customInstructions = $body['custom_instructions'] ?? '';
        $requestedModel = $body['model'] ?? 'gemini-2.5-flash';
        
        if (empty($bookFile)) {
            return ['error' => 'Missing book parameter'];
        }
        
        if (empty($prompt)) {
            return ['error' => 'Missing prompt'];
        }
        
        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Access-Control-Allow-Origin' => '*'
        ];
        $connection->send(new Response(200, $headers, ''));
        
        try {
            $cacheClient = new GeminiContextCache(GEMINI_API_KEY, $requestedModel);
            $bookCache = $cacheClient->getBookCache($bookFile);
            
            if (!$bookCache) {
                StreamHelper::sendSSE($connection, 'sources', json_encode([
                    ['text' => "正在为《{$bookFile}》创建 Context Cache，请稍候...", 'score' => 0]
                ], JSON_UNESCAPED_UNICODE));
                
                $booksDir = dirname(__DIR__, 3) . '/books';
                $bookPath = $booksDir . '/' . $bookFile;
                
                if (!file_exists($bookPath)) {
                    StreamHelper::sendSSE($connection, 'error', "书籍文件不存在: {$bookFile}");
                    $connection->close();
                    return null;
                }
                
                $ext = strtolower(pathinfo($bookFile, PATHINFO_EXTENSION));
                if ($ext === 'epub') {
                    $content = \SmartBook\Parser\EpubParser::extractText($bookPath);
                } else {
                    $content = file_get_contents($bookPath);
                }
                
                if (empty($content)) {
                    StreamHelper::sendSSE($connection, 'error', "无法提取书籍内容");
                    $connection->close();
                    return null;
                }
                
                $createResult = $cacheClient->createForBook($bookFile, $content, 7200);
                
                if (!$createResult['success']) {
                    StreamHelper::sendSSE($connection, 'error', "创建缓存失败: " . ($createResult['error'] ?? '未知错误'));
                    $connection->close();
                    return null;
                }
                
                $bookCache = $cacheClient->getBookCache($bookFile);
                
                if (!$bookCache) {
                    StreamHelper::sendSSE($connection, 'error', "创建缓存后仍无法获取");
                    $connection->close();
                    return null;
                }
                
                StreamHelper::sendSSE($connection, 'sources', json_encode([
                    ['text' => "✅ Context Cache 创建成功！", 'score' => 100]
                ], JSON_UNESCAPED_UNICODE));
            }
            
            $cacheModel = str_replace('models/', '', $bookCache['model'] ?? '');
            if ($cacheModel !== $requestedModel) {
                $errorMsg = "⚠️ 模型不匹配！\n\n" .
                    "• 当前选择: {$requestedModel}\n" .
                    "• 缓存要求: {$cacheModel}\n\n" .
                    "请切换到 {$cacheModel} 模型后重试。";
                StreamHelper::sendSSE($connection, 'error', $errorMsg);
                $connection->close();
                return null;
            }
            
            $model = $cacheModel;
            
            $writer = new EnhancedStoryWriter(GEMINI_API_KEY, $model);
            
            $tokenCount = $bookCache['usageMetadata']['totalTokenCount'] ?? 0;
            StreamHelper::sendSSE($connection, 'sources', json_encode([
                ['text' => "Context Cache（{$tokenCount} tokens）+ Few-shot（{$model}）", 'score' => 100]
            ], JSON_UNESCAPED_UNICODE));
            
            $isConnectionAlive = true;
            $writer->continueStory(
                $bookFile,
                $prompt,
                function ($text, $isThought) use ($connection, &$isConnectionAlive) {
                    if (!$isConnectionAlive) return;
                    if ($text && !$isThought) {
                        if (!StreamHelper::sendSSE($connection, 'content', $text)) {
                            $isConnectionAlive = false;
                        }
                    }
                },
                function ($fullContent, $usageMetadata = null, $usedModel = null) use ($connection, $model, &$isConnectionAlive) {
                    if (!$isConnectionAlive) return;
                    if ($usageMetadata) {
                        $costInfo = TokenCounter::calculateCost($usageMetadata, $usedModel ?? $model);
                        StreamHelper::sendSSE($connection, 'usage', json_encode([
                            'tokens' => $costInfo['tokens'],
                            'cost' => $costInfo['cost'],
                            'cost_formatted' => TokenCounter::formatCost($costInfo['cost']),
                            'currency' => $costInfo['currency'],
                            'model' => $usedModel ?? $model
                        ], JSON_UNESCAPED_UNICODE));
                    }
                    StreamHelper::sendSSE($connection, 'done', '');
                    $connection->close();
                },
                function ($error) use ($connection, &$isConnectionAlive) {
                    if (!$isConnectionAlive) return;
                    StreamHelper::sendSSE($connection, 'error', $error);
                    $connection->close();
                },
                [
                    'custom_instructions' => $customInstructions,
                    'token_count' => $tokenCount,
                ]
            );
            
        } catch (\Exception $e) {
            StreamHelper::sendSSE($connection, 'error', $e->getMessage());
            $connection->close();
        }
        
        return null;
    }
    
    /**
     * 分析书籍人物
     */
    public static function analyzeCharacters(Context $ctx): ?array
    {
        $connection = $ctx->connection();
        $body = $ctx->jsonBody() ?? [];
        $bookFile = $body['book'] ?? '';
        $model = $body['model'] ?? 'gemini-2.5-flash';
        
        if (empty($bookFile)) {
            return ['error' => 'Missing book parameter'];
        }
        
        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Access-Control-Allow-Origin' => '*'
        ];
        $connection->send(new Response(200, $headers, ''));
        
        try {
            $writer = new EnhancedStoryWriter(GEMINI_API_KEY, $model);
            
            StreamHelper::sendSSE($connection, 'sources', json_encode([
                ['text' => '使用 Context Cache 分析人物', 'score' => 100]
            ], JSON_UNESCAPED_UNICODE));
            
            $writer->analyzeCharacters(
                $bookFile,
                function ($text, $isThought) use ($connection) {
                    if ($text && !$isThought) {
                        StreamHelper::sendSSE($connection, 'content', $text);
                    }
                },
                function ($fullContent, $usageMetadata = null, $usedModel = null) use ($connection, $model) {
                    if ($usageMetadata) {
                        $costInfo = TokenCounter::calculateCost($usageMetadata, $usedModel ?? $model);
                        StreamHelper::sendSSE($connection, 'usage', json_encode([
                            'tokens' => $costInfo['tokens'],
                            'cost' => $costInfo['cost'],
                            'cost_formatted' => TokenCounter::formatCost($costInfo['cost']),
                            'currency' => $costInfo['currency'],
                            'model' => $usedModel ?? $model
                        ], JSON_UNESCAPED_UNICODE));
                    }
                    StreamHelper::sendSSE($connection, 'done', '');
                    $connection->close();
                },
                function ($error) use ($connection) {
                    StreamHelper::sendSSE($connection, 'error', $error);
                    $connection->close();
                }
            );
            
        } catch (\Exception $e) {
            StreamHelper::sendSSE($connection, 'error', $e->getMessage());
            $connection->close();
        }
        
        return null;
    }
}
