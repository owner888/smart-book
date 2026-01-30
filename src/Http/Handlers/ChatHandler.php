<?php
/**
 * 聊天处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Logger;
use SmartBook\AI\AIService;
use SmartBook\AI\TokenCounter;
use SmartBook\AI\GeminiContextCache;
use SmartBook\AI\GoogleTTSClient;
use SmartBook\Cache\CacheService;
use SmartBook\Http\Context;
use SmartBook\RAG\EmbeddingClient;
use SmartBook\RAG\VectorStore;
use Workerman\Protocols\Http\Response;

class ChatHandler
{   
    /**
     * 流式书籍问答助手（SSE）
     */
    public static function streamAskAsync(Context $ctx): ?array
    {
        $connection = $ctx->connection();
        $body = $ctx->jsonBody() ?? [];
        $question = $body['question'] ?? '';
        $chatId = $body['chat_id'] ?? '';
        $enableSearch = $body['search'] ?? true;
        $engine = $body['engine'] ?? 'google';
        $ragEnabled = $body['rag'] ?? true;
        $keywordWeight = floatval($body['keyword_weight'] ?? 0.5);
        $model = $body['model'] ?? 'gemini-2.5-flash';
        $assistantId = $body['assistant_id'] ?? 'ask';
        $bookId = $body['book_id'] ?? '';
        
        // 过滤空问题或过短的问题（至少2个字符）
        $trimmedQuestion = trim($question);
        if (mb_strlen($trimmedQuestion) < 2) {
            Logger::warn("⚠️ 问题太短或为空，拒绝处理: '{$trimmedQuestion}' (长度: " . mb_strlen($trimmedQuestion) . ")");
            return ['error' => 'Question too short (minimum 2 characters)'];
        }
        
        Logger::info("🤖 Assistant: {$assistantId} | 🎯 Model: {$model}" . ($bookId ? " | 📚 Book: {$bookId}" : ''));
        
        $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
        
        CacheService::getChatContext($chatId, function($context) use ($connection, $question, $chatId, $headers, $enableSearch, $engine, $ragEnabled, $keywordWeight, $model) {
            $connection->send(new Response(200, $headers, ''));
            
            $prompts = $GLOBALS['config']['prompts'];
            $libraryPrompts = $prompts['library'];
            $ragPrompts = $prompts['rag'];
            
            $bookTitle = '未知书籍';
            $bookAuthors = '未知作者';
            
            $currentBookPath = ConfigHandler::getCurrentBookPath();
            if ($currentBookPath) {
                $ext = strtolower(pathinfo($currentBookPath, PATHINFO_EXTENSION));
                if ($ext === 'epub') {
                    $metadata = \SmartBook\Parser\EpubParser::extractMetadata($currentBookPath);
                    if (!empty($metadata['title'])) $bookTitle = '《' . $metadata['title'] . '》';
                    if (!empty($metadata['authors'])) $bookAuthors = $metadata['authors'];
                } else {
                    $bookTitle = '《' . pathinfo($currentBookPath, PATHINFO_FILENAME) . '》';
                }
            }
            
            $doChat = function($ragContext, $ragSources) use (
                $connection, $question, $chatId, $enableSearch, $engine, $ragEnabled, $model,
                $context, $bookTitle, $bookAuthors, $prompts, $libraryPrompts, $ragPrompts
            ) {
                if ($ragEnabled && !empty($ragContext)) {
                    $bookInfo = str_replace('{title}', $bookTitle, $ragPrompts['book_intro'] ?? 'I am discussing the book: {title}');
                    if (!empty($bookAuthors)) {
                        $bookInfo .= str_replace('{authors}', $bookAuthors, $ragPrompts['author_template'] ?? ' by {authors}');
                    }
                    $systemPrompt = str_replace(['{book_info}', '{context}'], [$bookInfo, $ragContext], $ragPrompts['system'] ?? 'You are a book analysis assistant. {book_info}\n\nContext:\n{context}');
                    StreamHelper::sendSSE($connection, 'sources', json_encode($ragSources, JSON_UNESCAPED_UNICODE));
                } else {
                    $bookInfo = $libraryPrompts['book_intro'] . str_replace(['{which}', '{title}', '{authors}'], ['', $bookTitle, $bookAuthors], $libraryPrompts['book_template']) . $libraryPrompts['separator'];
                    $systemPrompt = $bookInfo . $libraryPrompts['markdown_instruction'] . ($libraryPrompts['unknown_single'] ?? '') . ' ' . str_replace('{language}', $prompts['language']['default'], $prompts['language']['instruction']);
                    $sourceTexts = $prompts['source_texts'] ?? ['google' => 'AI 预训练知识 + Google Search', 'mcp' => 'AI 预训练知识 + MCP 工具', 'off' => 'AI 预训练知识（搜索已关闭）'];
                    StreamHelper::sendSSE($connection, 'sources', json_encode([['text' => $sourceTexts[$engine] ?? $sourceTexts['off'], 'score' => 100]], JSON_UNESCAPED_UNICODE));
                }
                
                if ($context['summary']) {
                    $historyLabel = $prompts['summarize']['history_label'] ?? '【对话历史摘要】';
                    $systemPrompt .= "\n\n{$historyLabel}\n" . $context['summary']['text'];
                    StreamHelper::sendSSE($connection, 'summary_used', json_encode(['rounds_summarized' => $context['summary']['rounds_summarized'], 'recent_messages' => count($context['messages']) / 2], JSON_UNESCAPED_UNICODE));
                }
                
                $messages = [['role' => 'system', 'content' => $systemPrompt]];
                foreach ($context['messages'] as $msg) {
                    if (isset($msg['role']) && isset($msg['content'])) $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
                }
                $messages[] = ['role' => 'user', 'content' => $question];
                
                if ($chatId) CacheService::addToChatHistory($chatId, ['role' => 'user', 'content' => $question]);
                
                $asyncGemini = AIService::getAsyncGemini($model);
                $isConnectionAlive = true;
                $requestId = $asyncGemini->chatStreamAsync(
                    $messages,
                    function ($text, $isThought) use ($connection, &$isConnectionAlive, &$requestId, $asyncGemini) {
                        if (!$isConnectionAlive) return;
                        if ($text) {
                            if (!StreamHelper::sendSSE($connection, $isThought ? 'thinking' : 'content', $text)) {
                                $isConnectionAlive = false;
                                if ($requestId) $asyncGemini->cancel($requestId);
                            }
                        }
                    },
                    function ($fullAnswer, $usageMetadata = null, $usedModel = null) use ($connection, $chatId, $context, $model, &$isConnectionAlive) {
                        if (!$isConnectionAlive) return;
                        if ($chatId) {
                            CacheService::addToChatHistory($chatId, ['role' => 'assistant', 'content' => $fullAnswer]);
                            self::triggerSummarizationIfNeeded($chatId, $context);
                        }
                        if ($usageMetadata) {
                            $costInfo = TokenCounter::calculateCost($usageMetadata, $usedModel ?? $model);
                            StreamHelper::sendSSE($connection, 'usage', json_encode(['tokens' => $costInfo['tokens'], 'cost' => $costInfo['cost'], 'cost_formatted' => TokenCounter::formatCost($costInfo['cost']), 'currency' => $costInfo['currency'], 'model' => $usedModel ?? $model], JSON_UNESCAPED_UNICODE));
                        }
                        StreamHelper::sendSSE($connection, 'done', '');
                        $connection->close();
                    },
                    function ($error) use ($connection, &$isConnectionAlive) {
                        if (!$isConnectionAlive) return;
                        StreamHelper::sendSSE($connection, 'error', $error);
                        $connection->close();
                    },
                    ['enableSearch' => $enableSearch && $engine === 'google', 'enableTools' => $engine === 'mcp']
                );
            };
            
            $currentCache = ConfigHandler::getCurrentBookCache();
            if ($ragEnabled && $currentCache) {
                try {
                    $embedder = new EmbeddingClient(GEMINI_API_KEY);
                    $queryEmbedding = $embedder->embedQuery($question);
                    
                    $ragContext = '';
                    $ragSources = [];
                    $chunkTemplate = $ragPrompts['chunk_template'] ?? "【Passage {index}】\n{text}\n";
                    
                    $vectorStore = new VectorStore($currentCache);
                    $results = $vectorStore->hybridSearch($question, $queryEmbedding, 5, $keywordWeight);
                    
                    foreach ($results as $i => $result) {
                        $ragContext .= str_replace(['{index}', '{text}'], [$i + 1, $result['chunk']['text']], $chunkTemplate);
                        $ragContext .= "(Relevance: " . round($result['score'] * 100, 1) . "%)\n\n";
                        $ragSources[] = ['text' => mb_substr($result['chunk']['text'], 0, 200) . '...', 'score' => round($result['score'] * 100, 1)];
                    }
                    $doChat($ragContext, $ragSources);
                } catch (\Exception $e) {
                    $doChat('', []);
                }
            } else {
                $doChat('', []);
            }
        });
        
        return null;
    }
    
    /**
     * 流式通用聊天（SSE）
     */
    public static function streamChat(Context $ctx): ?array
    {
        $connection = $ctx->connection();
        $body = $ctx->jsonBody() ?? [];
        $message = $body['message'] ?? '';
        $chatId = $body['chat_id'] ?? '';
        $enableSearch = $body['search'] ?? true;
        $engine = $body['engine'] ?? 'google';
        $model = $body['model'] ?? 'gemini-2.5-flash';
        $assistantId = $body['assistant_id'] ?? 'chat';  // 新增：获取助手 ID
        
        // 过滤空消息或过短的消息（至少2个字符）
        $trimmedMessage = trim($message);
        if (mb_strlen($trimmedMessage) < 2) {
            Logger::warn("⚠️ 消息太短或为空，拒绝处理: '{$trimmedMessage}' (长度: " . mb_strlen($trimmedMessage) . ")");
            return ['error' => 'Message too short (minimum 2 characters)'];
        }
        
        Logger::info("🤖 Assistant: {$assistantId} | 🎯 Model: {$model}");
        
        $clientSummary = $body['summary'] ?? null;
        $clientHistory = $body['history'] ?? null;
        
        $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
        
        if ($clientSummary !== null || $clientHistory !== null) {
            $connection->send(new Response(200, $headers, ''));
            
            $prompts = $GLOBALS['config']['prompts'];
            $sourceTexts = $prompts['source_texts'] ?? ['google' => 'AI 预训练知识 + Google Search', 'mcp' => 'AI 预训练知识 + MCP 工具', 'off' => 'AI 预训练知识（搜索已关闭）'];
            StreamHelper::sendSSE($connection, 'sources', json_encode([['text' => $sourceTexts[$engine] ?? $sourceTexts['off'], 'score' => 100]], JSON_UNESCAPED_UNICODE));
            
            // 根据助手类型获取系统提示词
            $systemPrompt = self::getSystemPromptForAssistant($assistantId, $prompts);
            $messages = [['role' => 'system', 'content' => $systemPrompt]];
            
            if ($clientSummary) {
                $historyLabel = $prompts['summarize']['history_label'] ?? '【对话历史摘要】';
                $messages[0]['content'] .= "\n\n{$historyLabel}\n" . $clientSummary;
                StreamHelper::sendSSE($connection, 'summary_used', json_encode(['source' => 'ios_client', 'has_summary' => true], JSON_UNESCAPED_UNICODE));
            }
            
            if (is_array($clientHistory)) {
                foreach ($clientHistory as $msg) {
                    if (isset($msg['role']) && isset($msg['content'])) {
                        $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
                    }
                }
            }
            
            $messages[] = ['role' => 'user', 'content' => $message];
            
            // 输出完整的请求数据
            Logger::info("📤 提交给 Gemini 的 JSON Body (Model: {$model}):");
            Logger::info(print_r($messages, true));
            
            $asyncGemini = AIService::getAsyncGemini($model);
            $isConnectionAlive = true;
            $requestId = $asyncGemini->chatStreamAsync(
                $messages,
                function ($text, $isThought) use ($connection, &$isConnectionAlive, &$requestId, $asyncGemini) {
                    if (!$isConnectionAlive) return;
                    if ($text) {
                        if (!StreamHelper::sendSSE($connection, $isThought ? 'thinking' : 'content', $text)) {
                            $isConnectionAlive = false;
                            if ($requestId) $asyncGemini->cancel($requestId);
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
                ['enableSearch' => $enableSearch && $engine === 'google', 'enableTools' => $engine === 'mcp']
            );
            
            return null;
        }
        
        CacheService::getChatContext($chatId, function($context) use ($connection, $message, $chatId, $headers, $enableSearch, $engine, $model, $assistantId) {
            $connection->send(new Response(200, $headers, ''));
            
            $prompts = $GLOBALS['config']['prompts'];
            $sourceTexts = $prompts['source_texts'] ?? ['google' => 'AI 预训练知识 + Google Search', 'mcp' => 'AI 预训练知识 + MCP 工具', 'off' => 'AI 预训练知识（搜索已关闭）'];
            StreamHelper::sendSSE($connection, 'sources', json_encode([['text' => $sourceTexts[$engine] ?? $sourceTexts['off'], 'score' => 100]], JSON_UNESCAPED_UNICODE));
            
            // 根据助手类型获取系统提示词
            $systemPrompt = self::getSystemPromptForAssistant($assistantId, $prompts);
            $messages = [['role' => 'system', 'content' => $systemPrompt]];
            
            if ($context['summary']) {
                $historyLabel = $prompts['summarize']['history_label'] ?? '【对话历史摘要】';
                $messages[0]['content'] .= "\n\n{$historyLabel}\n" . $context['summary']['text'];
                StreamHelper::sendSSE($connection, 'summary_used', json_encode([
                    'rounds_summarized' => $context['summary']['rounds_summarized'],
                    'recent_messages' => count($context['messages']) / 2
                ], JSON_UNESCAPED_UNICODE));
            }
            
            foreach ($context['messages'] as $msg) {
                if (isset($msg['role']) && isset($msg['content'])) {
                    $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
                }
            }
            $messages[] = ['role' => 'user', 'content' => $message];
            
            if ($chatId) {
                CacheService::addToChatHistory($chatId, ['role' => 'user', 'content' => $message]);
            }
            
            $asyncGemini = AIService::getAsyncGemini($model);
            $isConnectionAlive = true;
            $requestId = $asyncGemini->chatStreamAsync(
                $messages,
                function ($text, $isThought) use ($connection, &$isConnectionAlive, &$requestId, $asyncGemini) {
                    if (!$isConnectionAlive) return;
                    if ($text) {
                        if (!StreamHelper::sendSSE($connection, $isThought ? 'thinking' : 'content', $text)) {
                            $isConnectionAlive = false;
                            if ($requestId) $asyncGemini->cancel($requestId);
                        }
                    }
                },
                function ($fullContent, $usageMetadata = null, $usedModel = null) use ($connection, $chatId, $context, $model, &$isConnectionAlive) {
                    if (!$isConnectionAlive) return;
                    if ($chatId) {
                        CacheService::addToChatHistory($chatId, ['role' => 'assistant', 'content' => $fullContent]);
                        self::triggerSummarizationIfNeeded($chatId, $context);
                    }
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
                ['enableSearch' => $enableSearch && $engine === 'google', 'enableTools' => $engine === 'mcp']
            );
        });
        
        return null;
    }
    
    /**
     * 流式续写小说（SSE）
     */
    public static function streamContinue(Context $ctx): ?array
    {
        $connection = $ctx->connection();
        $body = $ctx->jsonBody() ?? [];
        $prompt = $body['prompt'] ?? '';
        $enableSearch = $body['search'] ?? false;
        $engine = $body['engine'] ?? 'off';
        $ragEnabled = $body['rag'] ?? false;
        $keywordWeight = floatval($body['keyword_weight'] ?? 0.5);
        $model = $body['model'] ?? 'gemini-2.5-flash';
        
        // 过滤空提示或过短的提示（至少2个字符）
        $trimmedPrompt = trim($prompt);
        if (mb_strlen($trimmedPrompt) < 2) {
            Logger::warn("⚠️ 续写提示太短或为空，拒绝处理: '{$trimmedPrompt}' (长度: " . mb_strlen($trimmedPrompt) . ")");
            return ['error' => 'Prompt too short (minimum 2 characters)'];
        }
        
        $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
        $connection->send(new Response(200, $headers, ''));
        
        $prompts = $GLOBALS['config']['prompts'];
        $ragPrompts = $prompts['rag'];
        
        $bookTitle = '当前书籍';
        $currentBookPath = ConfigHandler::getCurrentBookPath();
        if ($currentBookPath) {
            $ext = strtolower(pathinfo($currentBookPath, PATHINFO_EXTENSION));
            if ($ext === 'epub') {
                $metadata = \SmartBook\Parser\EpubParser::extractMetadata($currentBookPath);
                if (!empty($metadata['title'])) $bookTitle = '《' . $metadata['title'] . '》';
            } else {
                $bookTitle = '《' . pathinfo($currentBookPath, PATHINFO_FILENAME) . '》';
            }
        }
        
        $baseSystemPrompt = str_replace('{title}', $bookTitle, $prompts['continue']['system'] ?? '');
        $userPrompt = $prompt ?: str_replace('{title}', $bookTitle, $prompts['continue']['default_prompt'] ?? '');
        
        $continuePrompts = $prompts['continue'];
        $doChat = function($ragContext, $ragSources) use (
            $connection, $baseSystemPrompt, $userPrompt, $enableSearch, $engine, $model, $prompts, $ragEnabled, $continuePrompts
        ) {
            $systemPrompt = $baseSystemPrompt;
            
            if ($ragEnabled && !empty($ragContext)) {
                $ragInstruction = $continuePrompts['rag_instruction'] ?? '';
                $systemPrompt .= str_replace('{context}', $ragContext, $ragInstruction);
                StreamHelper::sendSSE($connection, 'sources', json_encode($ragSources, JSON_UNESCAPED_UNICODE));
            } else {
                $sourceTexts = $prompts['source_texts'] ?? ['google' => 'AI 预训练知识 + Google Search', 'mcp' => 'AI 预训练知识 + MCP 工具', 'off' => 'AI 预训练知识（搜索已关闭）'];
                StreamHelper::sendSSE($connection, 'sources', json_encode([['text' => $sourceTexts[$engine] ?? $sourceTexts['off'], 'score' => 100]], JSON_UNESCAPED_UNICODE));
            }
            
            $asyncGemini = AIService::getAsyncGemini($model);
            $isConnectionAlive = true;
            $requestId = $asyncGemini->chatStreamAsync(
                [['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => $userPrompt]],
                function ($text, $isThought) use ($connection, &$isConnectionAlive, &$requestId, $asyncGemini) {
                    if (!$isConnectionAlive) return;
                    if (!$isThought && $text) {
                        if (!StreamHelper::sendSSE($connection, 'content', $text)) {
                            $isConnectionAlive = false;
                            if ($requestId) $asyncGemini->cancel($requestId);
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
                ['enableSearch' => $enableSearch && $engine === 'google', 'enableTools' => $engine === 'mcp']
            );
        };
        
        $currentCache = ConfigHandler::getCurrentBookCache();
        if ($ragEnabled && $currentCache) {
            try {
                $embedder = new EmbeddingClient(GEMINI_API_KEY);
                $queryEmbedding = $embedder->embedQuery($userPrompt);
                
                $ragContext = '';
                $ragSources = [];
                $chunkTemplate = $ragPrompts['chunk_template'] ?? "【Passage {index}】\n{text}\n";
                
                $vectorStore = new VectorStore($currentCache);
                $results = $vectorStore->hybridSearch($userPrompt, $queryEmbedding, 5, $keywordWeight);
                
                foreach ($results as $i => $result) {
                    $ragContext .= str_replace(['{index}', '{text}'], [$i + 1, $result['chunk']['text']], $chunkTemplate);
                    $ragContext .= "(Relevance: " . round($result['score'] * 100, 1) . "%)\n\n";
                    $ragSources[] = ['text' => mb_substr($result['chunk']['text'], 0, 200) . '...', 'score' => round($result['score'] * 100, 1)];
                }
                $doChat($ragContext, $ragSources);
            } catch (\Exception $e) {
                $doChat('', []);
            }
        } else {
            $doChat('', []);
        }
        
        return null;
    }
    
    /**
     * 根据助手 ID 获取系统提示词
     */
    private static function getSystemPromptForAssistant(string $assistantId, array $prompts): string
    {
        // 获取助手配置列表
        $assistants = ConfigHandler::getAssistants();
        $assistantsList = $assistants['list'] ?? [];
        
        // 查找匹配的助手
        foreach ($assistantsList as $assistant) {
            if ($assistant['id'] === $assistantId) {
                return $assistant['system_prompt'] ?? '';
            }
        }
        
        // 如果没找到，返回通用聊天的系统提示词
        return $prompts['chat']['system_prompt'] ?? '';
    }
    
    /**
     * 检查并触发上下文摘要
     */
    public static function triggerSummarizationIfNeeded(string $chatId, array $context): void
    {
        CacheService::needsSummarization($chatId, function($needsSummary) use ($chatId, $context) {
            if (!$needsSummary) return;
            
            CacheService::getChatHistory($chatId, function($history) use ($chatId, $context) {
                if (empty($history)) return;
                
                $prompts = $GLOBALS['config']['prompts'];
                $summarizeConfig = $prompts['summarize'] ?? [];
                $roleNames = $prompts['role_names'] ?? ['user' => '用户', 'assistant' => 'AI'];
                
                $conversationText = "";
                if ($context['summary']) {
                    $prevLabel = $summarizeConfig['previous_summary_label'] ?? '【之前的摘要】';
                    $newLabel = $summarizeConfig['new_conversation_label'] ?? '【新对话】';
                    $conversationText .= "{$prevLabel}\n" . $context['summary']['text'] . "\n\n{$newLabel}\n";
                }
                foreach ($history as $msg) {
                    $role = $roleNames[$msg['role']] ?? ($msg['role'] === 'user' ? '用户' : 'AI');
                    $conversationText .= "{$role}: {$msg['content']}\n\n";
                }
                
                $summarizePrompt = CacheService::getSummarizePrompt();
                
                $asyncGemini = AIService::getAsyncGemini();
                $asyncGemini->chatStreamAsync(
                    [
                        ['role' => 'user', 'content' => $conversationText . "\n\n" . $summarizePrompt]
                    ],
                    function ($text, $isThought) { },
                    function ($summaryText) use ($chatId) {
                        if (!empty($summaryText)) {
                            CacheService::saveSummaryAndCompress($chatId, $summaryText);
                            Logger::info("对话 {$chatId} 已自动摘要");
                        }
                    },
                    function ($error) use ($chatId) {
                        Logger::error("摘要生成失败 ({$chatId}): {$error}");
                    },
                    ['enableSearch' => false]
                );
            });
        });
    }
    
    /**
     * 基于 Context Cache 的书籍问答（无需 RAG 和 embedding）
     */
    public static function streamAskWithCache(Context $ctx): ?array
    {
        $connection = $ctx->connection();
        $body = $ctx->jsonBody() ?? [];
        $question = $body['question'] ?? '';
        $bookId = $body['book_id'] ?? '';
        $model = $body['model'] ?? 'gemini-2.0-flash';
        $assistantId = $body['assistant_id'] ?? 'ask';
        $chatId = $body['chat_id'] ?? '';  // 新增：支持 chat_id
        $clientHistory = $body['history'] ?? null;  // 新增：支持客户端传入历史
        
        // 过滤空问题或过短的问题（至少2个字符）
        $trimmedQuestion = trim($question);
        if (mb_strlen($trimmedQuestion) < 2) {
            Logger::warn("⚠️ 问题太短或为空，拒绝处理: '{$trimmedQuestion}' (长度: " . mb_strlen($trimmedQuestion) . ")");
            return ['error' => 'Question too short (minimum 2 characters)'];
        }
        
        Logger::info("🤖 Assistant: {$assistantId} | 🎯 Model: {$model} | 📚 Book: {$bookId} (Context Cache)");
        
        // 获取当前选中的书籍路径
        $bookPath = ConfigHandler::getCurrentBookPath();
        if (!$bookPath) {
            return ['error' => '请先选择一本书籍'];
        }
        
        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Access-Control-Allow-Origin' => '*'
        ];
        $connection->send(new Response(200, $headers, ''));
        
        try {
            // 使用缓存的 MD5（在选择书籍时已计算好，避免每次重新读取书籍）
            $contentMd5 = $GLOBALS['selected_book']['content_md5'] ?? null;
            
            if (!$contentMd5) {
                StreamHelper::sendSSE($connection, 'error', "MD5 未缓存，请重新选择书籍");
                $connection->close();
                return null;
            }
            
            $cacheClient = new GeminiContextCache(GEMINI_API_KEY, $model);
            $bookCache = $cacheClient->getBookCache($contentMd5);
            
            // 如果 Context Cache 不存在，提取书籍内容直接问答（适用于小书籍）
            if (!$bookCache) {
                Logger::info("Context Cache 不存在，使用直接问答模式（书籍可能过小）");
                
                // 提取书籍内容
                $ext = strtolower(pathinfo($bookPath, PATHINFO_EXTENSION));
                if ($ext === 'epub') {
                    $bookContent = \SmartBook\Parser\EpubParser::extractText($bookPath);
                } else {
                    $bookContent = file_get_contents($bookPath);
                }
                
                if (empty($bookContent)) {
                    StreamHelper::sendSSE($connection, 'error', "无法读取书籍内容");
                    $connection->close();
                    return null;
                }
                
                // 获取书名
                $bookTitle = pathinfo($bookPath, PATHINFO_FILENAME);
                if ($ext === 'epub') {
                    $metadata = \SmartBook\Parser\EpubParser::extractMetadata($bookPath);
                    if (!empty($metadata['title'])) {
                        $bookTitle = $metadata['title'];
                    }
                }
                
                StreamHelper::sendSSE($connection, 'sources', json_encode([
                    ['text' => "书籍全文（内容过短，无法使用 Context Cache）", 'score' => 100]
                ], JSON_UNESCAPED_UNICODE));
                
                // 构建系统提示词
                $systemPrompt = "你是一个专业的书籍分析助手。以下是书籍《{$bookTitle}》的完整内容：\n\n{$bookContent}\n\n请基于以上书籍内容，准确回答用户的问题。";
                
                $asyncGemini = AIService::getAsyncGemini($model);
                $isConnectionAlive = true;
                
                $asyncGemini->chatStreamAsync(
                    [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $question]
                    ],
                    function ($text, $isThought) use ($connection, &$isConnectionAlive) {
                        if (!$isConnectionAlive) return;
                        if ($text) {
                            if (!StreamHelper::sendSSE($connection, $isThought ? 'thinking' : 'content', $text)) {
                                $isConnectionAlive = false;
                            }
                        }
                    },
                    function ($fullAnswer, $usageMetadata = null, $usedModel = null) use ($connection, $model, &$isConnectionAlive) {
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
                    }
                );
                
                return null;
            }
            
            $cacheModel = str_replace('models/', '', $bookCache['model'] ?? '');
            if ($cacheModel !== $model) {
                $errorMsg = "⚠️ 模型不匹配！\n\n" .
                    "• 当前选择: {$model}\n" .
                    "• 缓存要求: {$cacheModel}\n\n" .
                    "请切换到 {$cacheModel} 模型后重试。";
                StreamHelper::sendSSE($connection, 'error', $errorMsg);
                $connection->close();
                return null;
            }
            
            $tokenCount = $bookCache['usageMetadata']['totalTokenCount'] ?? 0;
            StreamHelper::sendSSE($connection, 'sources', json_encode([
                ['text' => "Context Cache（{$tokenCount} tokens，无需 embedding）", 'score' => 100]
            ], JSON_UNESCAPED_UNICODE));
            
            // 构建消息列表（包含对话历史）
            $messages = [];
            
            // 如果客户端传入了历史，直接使用
            if (is_array($clientHistory)) {
                foreach ($clientHistory as $msg) {
                    if (isset($msg['role']) && isset($msg['content'])) {
                        $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
                    }
                }
            }
            
            // 添加当前问题
            $messages[] = ['role' => 'user', 'content' => $question];
            
            // 📊 输出完整的请求数据
            Logger::info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            Logger::info("📤 发送给 Gemini 的完整请求数据");
            Logger::info("Model: {$cacheModel} | Cache: {$bookCache['name']} | Tokens: {$tokenCount}");
            Logger::info(print_r($messages, true));
            Logger::info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            
            // 使用 Context Cache 直接问答
            $asyncGemini = AIService::getAsyncGemini($cacheModel);
            $isConnectionAlive = true;
            
            $asyncGemini->chatStreamAsync(
                $messages,
                function ($text, $isThought) use ($connection, &$isConnectionAlive) {
                    if (!$isConnectionAlive) return;
                    if ($text) {
                        if (!StreamHelper::sendSSE($connection, $isThought ? 'thinking' : 'content', $text)) {
                            $isConnectionAlive = false;
                        }
                    }
                },
                function ($fullAnswer, $usageMetadata = null, $usedModel = null) use ($connection, $cacheModel, &$isConnectionAlive) {
                    if (!$isConnectionAlive) return;
                    if ($usageMetadata) {
                        $costInfo = TokenCounter::calculateCost($usageMetadata, $usedModel ?? $cacheModel);
                        StreamHelper::sendSSE($connection, 'usage', json_encode([
                            'tokens' => $costInfo['tokens'],
                            'cost' => $costInfo['cost'],
                            'cost_formatted' => TokenCounter::formatCost($costInfo['cost']),
                            'currency' => $costInfo['currency'],
                            'model' => $usedModel ?? $cacheModel
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
                    'cachedContent' => $bookCache['name'],
                    'model' => $cacheModel
                ]
            );
            
        } catch (\Exception $e) {
            StreamHelper::sendSSE($connection, 'error', $e->getMessage());
            $connection->close();
        }
        
        return null;
    }
    
    /**
     * 基于 Context Cache 的续写小说（无需 RAG）
     */
    public static function streamContinueWithCache(Context $ctx): ?array
    {
        $connection = $ctx->connection();
        $body = $ctx->jsonBody() ?? [];
        $prompt = $body['prompt'] ?? '';
        $model = $body['model'] ?? 'gemini-2.0-flash';
        $assistantId = $body['assistant_id'] ?? 'continue';
        
        // 过滤空提示或过短的提示（至少2个字符）
        $trimmedPrompt = trim($prompt);
        if (mb_strlen($trimmedPrompt) < 2) {
            Logger::warn("⚠️ 续写提示太短或为空，拒绝处理: '{$trimmedPrompt}' (长度: " . mb_strlen($trimmedPrompt) . ")");
            return ['error' => 'Prompt too short (minimum 2 characters)'];
        }
        
        Logger::info("🤖 Assistant: {$assistantId} | 🎯 Model: {$model} (Context Cache 续写)");
        
        // 获取当前选中的书籍路径
        $bookPath = ConfigHandler::getCurrentBookPath();
        if (!$bookPath) {
            return ['error' => '请先选择一本书籍'];
        }
        
        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Access-Control-Allow-Origin' => '*'
        ];
        $connection->send(new Response(200, $headers, ''));
        
        try {
            // 使用缓存的 MD5（在选择书籍时已计算好，避免每次重新读取书籍）
            $contentMd5 = $GLOBALS['selected_book']['content_md5'] ?? null;
            
            if (!$contentMd5) {
                StreamHelper::sendSSE($connection, 'error', "MD5 未缓存，请重新选择书籍");
                $connection->close();
                return null;
            }
            
            $cacheClient = new GeminiContextCache(GEMINI_API_KEY, $model);
            $bookCache = $cacheClient->getBookCache($contentMd5);
            
            // 如果 Context Cache 不存在，提取书籍内容直接续写（适用于小书籍）
            if (!$bookCache) {
                Logger::info("Context Cache 不存在，使用直接续写模式（书籍可能过小）");
                
                // 提取书籍内容
                $ext = strtolower(pathinfo($bookPath, PATHINFO_EXTENSION));
                if ($ext === 'epub') {
                    $bookContent = \SmartBook\Parser\EpubParser::extractText($bookPath);
                } else {
                    $bookContent = file_get_contents($bookPath);
                }
                
                if (empty($bookContent)) {
                    StreamHelper::sendSSE($connection, 'error', "无法读取书籍内容");
                    $connection->close();
                    return null;
                }
                
                // 获取书名
                $bookTitle = pathinfo($bookPath, PATHINFO_FILENAME);
                if ($ext === 'epub') {
                    $metadata = \SmartBook\Parser\EpubParser::extractMetadata($bookPath);
                    if (!empty($metadata['title'])) {
                        $bookTitle = $metadata['title'];
                    }
                }
                
                StreamHelper::sendSSE($connection, 'sources', json_encode([
                    ['text' => "书籍全文（内容过短，无法使用 Context Cache）", 'score' => 100]
                ], JSON_UNESCAPED_UNICODE));
                
                // 构建系统提示词 - 续写风格
                $prompts = $GLOBALS['config']['prompts'];
                $continuePrompts = $prompts['continue'] ?? [];
                $baseSystemPrompt = str_replace('{title}', $bookTitle, $continuePrompts['system'] ?? '');
                $systemPrompt = $baseSystemPrompt . "\n\n以下是书籍《{$bookTitle}》的完整内容：\n\n{$bookContent}\n\n请基于以上书籍的风格和内容进行续写。";
                
                $asyncGemini = AIService::getAsyncGemini($model);
                $isConnectionAlive = true;
                
                $asyncGemini->chatStreamAsync(
                    [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    function ($text, $isThought) use ($connection, &$isConnectionAlive) {
                        if (!$isConnectionAlive) return;
                        if ($text && !$isThought) {
                            if (!StreamHelper::sendSSE($connection, 'content', $text)) {
                                $isConnectionAlive = false;
                            }
                        }
                    },
                    function ($fullAnswer, $usageMetadata = null, $usedModel = null) use ($connection, $model, &$isConnectionAlive) {
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
                    }
                );
                
                return null;
            }
            
            $cacheModel = str_replace('models/', '', $bookCache['model'] ?? '');
            if ($cacheModel !== $model) {
                $errorMsg = "⚠️ 模型不匹配！\n\n" .
                    "• 当前选择: {$model}\n" .
                    "• 缓存要求: {$cacheModel}\n\n" .
                    "请切换到 {$cacheModel} 模型后重试。";
                StreamHelper::sendSSE($connection, 'error', $errorMsg);
                $connection->close();
                return null;
            }
            
            $tokenCount = $bookCache['usageMetadata']['totalTokenCount'] ?? 0;
            StreamHelper::sendSSE($connection, 'sources', json_encode([
                ['text' => "Context Cache（{$tokenCount} tokens，无需 embedding）", 'score' => 100]
            ], JSON_UNESCAPED_UNICODE));
            
            // 获取续写提示词配置
            $prompts = $GLOBALS['config']['prompts'];
            $continuePrompts = $prompts['continue'] ?? [];
            
            // 构建用户消息
            $userMessage = $prompt;
            
            // 使用 Context Cache 进行续写
            $asyncGemini = AIService::getAsyncGemini($cacheModel);
            $isConnectionAlive = true;
            
            $asyncGemini->chatStreamAsync(
                [['role' => 'user', 'content' => $userMessage]],
                function ($text, $isThought) use ($connection, &$isConnectionAlive) {
                    if (!$isConnectionAlive) return;
                    if ($text && !$isThought) {
                        if (!StreamHelper::sendSSE($connection, 'content', $text)) {
                            $isConnectionAlive = false;
                        }
                    }
                },
                function ($fullAnswer, $usageMetadata = null, $usedModel = null) use ($connection, $cacheModel, &$isConnectionAlive) {
                    if (!$isConnectionAlive) return;
                    if ($usageMetadata) {
                        $costInfo = TokenCounter::calculateCost($usageMetadata, $usedModel ?? $cacheModel);
                        StreamHelper::sendSSE($connection, 'usage', json_encode([
                            'tokens' => $costInfo['tokens'],
                            'cost' => $costInfo['cost'],
                            'cost_formatted' => TokenCounter::formatCost($costInfo['cost']),
                            'currency' => $costInfo['currency'],
                            'model' => $usedModel ?? $cacheModel
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
                    'cachedContent' => $bookCache['name'],
                    'model' => $cacheModel
                ]
            );
            
        } catch (\Exception $e) {
            StreamHelper::sendSSE($connection, 'error', $e->getMessage());
            $connection->close();
        }
        
        return null;
    }
}
