<?php
/**
 * 聊天处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Logger;
use SmartBook\AI\AIService;
use SmartBook\AI\TokenCounter;
use SmartBook\AI\GeminiContextCache;
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
        
        Logger::info("🤖 Assistant: {$assistantId} | 🎯 Model: {$model}" . ($bookId ? " | 📚 Book: {$bookId}" : ''));
        
        if (empty($question)) return ['error' => 'Missing question'];
        
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
        
        Logger::info("🤖 Assistant: {$assistantId} | 🎯 Model: {$model}");
        
        $clientSummary = $body['summary'] ?? null;
        $clientHistory = $body['history'] ?? null;
        
        if (empty($message)) return ['error' => 'Missing message'];
        
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
            
            Logger::info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            Logger::info("📋 提交给 Gemini 的完整 Prompt");
            Logger::info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            Logger::info("🤖 模型: {$model}");
            Logger::info("📊 消息数量: " . count($messages));
            Logger::info("");
            
            foreach ($messages as $index => $msg) {
                $role = match($msg['role']) {
                    'system' => '⚙️ System',
                    'user' => '👤 User',
                    'assistant' => '🤖 Assistant',
                    default => '❓ Unknown'
                };
                
                $content = $msg['content'];
                $length = mb_strlen($content);
                
                Logger::info("[消息 " . ($index + 1) . "] {$role} ({$length} 字符)");
                Logger::info("---");
                Logger::info($content);
                Logger::info("---");
                Logger::info("");
            }
            
            $totalLength = array_reduce($messages, fn($sum, $msg) => $sum + mb_strlen($msg['content']), 0);
            $estimatedTokens = intval($totalLength / 3);
            
            Logger::info("📊 统计信息:");
            Logger::info("  • 总消息数: " . count($messages));
            Logger::info("  • 总字符数: {$totalLength}");
            Logger::info("  • 估算 Tokens: ~{$estimatedTokens}");
            Logger::info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            
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
        
        Logger::info("🤖 Assistant: {$assistantId} | 🎯 Model: {$model} | 📚 Book: {$bookId} (Context Cache)");
        
        if (empty($question)) {
            return ['error' => 'Missing question'];
        }
        
        if (empty($bookId)) {
            return ['error' => 'Missing book_id'];
        }
        
        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Access-Control-Allow-Origin' => '*'
        ];
        $connection->send(new Response(200, $headers, ''));
        
        try {
            $cacheClient = new GeminiContextCache(GEMINI_API_KEY, $model);
            $bookCache = $cacheClient->getBookCache($bookId);
            
            if (!$bookCache) {
                // 创建 Context Cache
                StreamHelper::sendSSE($connection, 'sources', json_encode([
                    ['text' => "正在为《{$bookId}》创建 Context Cache...", 'score' => 0]
                ], JSON_UNESCAPED_UNICODE));
                
                $booksDir = dirname(__DIR__, 3) . '/books';
                $bookPath = $booksDir . '/' . $bookId;
                
                if (!file_exists($bookPath)) {
                    StreamHelper::sendSSE($connection, 'error', "书籍文件不存在: {$bookId}");
                    $connection->close();
                    return null;
                }
                
                $ext = strtolower(pathinfo($bookId, PATHINFO_EXTENSION));
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
                
                $createResult = $cacheClient->createForBook($bookId, $content, 7200);
                
                if (!$createResult['success']) {
                    StreamHelper::sendSSE($connection, 'error', "创建缓存失败: " . ($createResult['error'] ?? '未知错误'));
                    $connection->close();
                    return null;
                }
                
                $bookCache = $cacheClient->getBookCache($bookId);
                
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
            
            // 使用 Context Cache 直接问答
            $asyncGemini = AIService::getAsyncGemini($cacheModel);
            $isConnectionAlive = true;
            
            $asyncGemini->chatStreamAsyncWithCache(
                $bookCache['name'],
                [['role' => 'user', 'content' => $question]],
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
                }
            );
            
        } catch (\Exception $e) {
            StreamHelper::sendSSE($connection, 'error', $e->getMessage());
            $connection->close();
        }
        
        return null;
    }
}
