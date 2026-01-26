<?php
/**
 * RAG 书籍助手
 */

namespace SmartBook\RAG;

require_once dirname(__DIR__) . '/Logger.php';

use SmartBook\AI\GeminiClient;
use SmartBook\Parser\EpubParser;

class BookRAGAssistant
{
    private GeminiClient $llm;
    private EmbeddingClient $embedder;
    private VectorStore $vectorStore;
    private DocumentChunker $chunker;
    private array $bookMetadata = [];
    private array $prompts = [];
    
    public function __construct(string $apiKey)
    {
        $this->llm = new GeminiClient($apiKey, GeminiClient::MODEL_GEMINI_25_FLASH);
        $this->embedder = new EmbeddingClient($apiKey);
        $this->vectorStore = new VectorStore();
        $this->chunker = new DocumentChunker(chunkSize: 800, chunkOverlap: 150);
        $this->loadPrompts();
    }
    
    private function loadPrompts(): void
    {
        $promptsFile = dirname(__DIR__, 2) . '/config/prompts.php';
        if (file_exists($promptsFile)) {
            $allPrompts = require $promptsFile;
            $this->prompts = $allPrompts['rag'] ?? [];
        }
    }
    
    /**
     * 构建书籍信息字符串
     */
    private function buildBookInfo(): string
    {
        $info = str_replace('{title}', $this->bookMetadata['title'] ?? 'Unknown', $this->prompts['book_intro'] ?? 'Discussing book: {title}');
        
        if (!empty($this->bookMetadata['author'])) {
            $info .= str_replace('{authors}', $this->bookMetadata['author'], $this->prompts['author_template'] ?? ' by {authors}');
        }
        
        if (!empty($this->bookMetadata['series'])) {
            $info .= str_replace('{series}', $this->bookMetadata['series'], $this->prompts['series_template'] ?? '');
        }
        
        return $info;
    }
    
    /**
     * 构建检索上下文
     */
    private function buildContext(array $results): string
    {
        $chunkTemplate = $this->prompts['chunk_template'] ?? "【Passage {index}】\n{text}\n";
        $separator = $this->prompts['chunk_separator'] ?? "\n";
        
        $chunks = [];
        foreach ($results as $i => $result) {
            $chunk = str_replace(
                ['{index}', '{text}'],
                [$i + 1, $result['chunk']['text']],
                $chunkTemplate
            );
            // 添加相关度分数
            $chunk .= "(Relevance: " . round($result['score'] * 100, 1) . "%)\n";
            $chunks[] = $chunk;
        }
        
        return implode($separator, $chunks);
    }
    
    /**
     * 构建系统提示词
     */
    private function buildSystemPrompt(array $results): string
    {
        $bookInfo = $this->buildBookInfo();
        
        if (empty($results)) {
            // 无检索结果时使用 fallback
            $template = $this->prompts['no_context_system'] ?? $this->prompts['system'] ?? '';
            return str_replace('{book_info}', $bookInfo, $template);
        }
        
        $context = $this->buildContext($results);
        $template = $this->prompts['system'] ?? 'You are a book analysis assistant. {book_info}\n\nContext:\n{context}';
        
        return str_replace(
            ['{book_info}', '{context}'],
            [$bookInfo, $context],
            $template
        );
    }
    
    public function loadBook(string $epubPath, ?string $cacheFile = null): void
    {
        if ($cacheFile && file_exists($cacheFile)) {
            \Logger::info("从缓存加载索引...");
            $this->vectorStore = new VectorStore($cacheFile);
            $this->bookMetadata = EpubParser::extractMetadata($epubPath);
            \Logger::info("已加载 {$this->vectorStore->count()} 个文档块");
            return;
        }
        
        $this->bookMetadata = EpubParser::extractMetadata($epubPath);
        \Logger::info("书籍: {$this->bookMetadata['title']}");
        
        \Logger::info("正在提取文本...");
        $text = EpubParser::extractText($epubPath);
        \Logger::info("  提取了 " . mb_strlen($text) . " 个字符");
        
        \Logger::info("正在分块...");
        $chunks = $this->chunker->chunk($text);
        \Logger::info("  生成了 " . count($chunks) . " 个文档块");
        
        \Logger::info("正在生成向量嵌入...");
        $batchSize = 20;
        $totalBatches = ceil(count($chunks) / $batchSize);
        
        for ($i = 0; $i < count($chunks); $i += $batchSize) {
            $batch = array_slice($chunks, $i, $batchSize);
            $embeddings = $this->embedder->embedBatch(array_column($batch, 'text'));
            $this->vectorStore->addBatch($batch, $embeddings);
            \Logger::debug("  批次 " . (floor($i / $batchSize) + 1) . "/{$totalBatches} 完成");
        }
        
        if ($cacheFile) {
            \Logger::info("保存索引缓存...");
            $this->vectorStore->save($cacheFile);
        }
        
        \Logger::info("索引完成！共 {$this->vectorStore->count()} 个文档块");
    }
    
    public function ask(string $question, int $topK = 10, bool $stream = true): string
    {
        if ($this->vectorStore->isEmpty()) return '错误：请先加载书籍';
        
        \Logger::info("正在检索相关内容...");
        $queryEmbedding = $this->embedder->embedQuery($question);
        $results = $this->vectorStore->hybridSearch($question, $queryEmbedding, $topK, 0.6);
        
        // 使用配置文件中的提示词模板
        $systemPrompt = $this->buildSystemPrompt($results);
        
        \Logger::info("正在生成回答...");
        
        if ($stream) {
            $result = $this->llm->chatStream(
                [['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => $question]],
                function($text, $chunk, $isThought) { if (!$isThought) \Logger::debug($text); },
                ['enableSearch' => false]
            );
            return $result['content'];
        } else {
            $response = $this->llm->chat([['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => $question]]);
            $content = '';
            foreach ($response['candidates'] ?? [] as $candidate) {
                foreach ($candidate['content']['parts'] ?? [] as $part) {
                    if (!($part['thought'] ?? false)) $content .= $part['text'] ?? '';
                }
            }
            \Logger::info($content);
            return $content;
        }
    }
    
    /**
     * 执行预定义操作
     */
    public function executeAction(string $actionName, int $topK = 10, string $language = 'Chinese'): string
    {
        $actions = $this->prompts['actions'] ?? [];
        
        if (!isset($actions[$actionName])) {
            return "未知操作: {$actionName}";
        }
        
        $action = $actions[$actionName];
        $prompt = str_replace('{language}', $language, $action['prompt']);
        
        return $this->ask($prompt, $topK);
    }
    
    /**
     * 获取可用操作列表
     */
    public function getAvailableActions(): array
    {
        $actions = $this->prompts['actions'] ?? [];
        $result = [];
        
        foreach ($actions as $key => $action) {
            $result[$key] = $action['human_name'] ?? $key;
        }
        
        return $result;
    }
    
    public function showRetrievedChunks(string $question, int $topK = 10): void
    {
        $queryEmbedding = $this->embedder->embedQuery($question);
        $results = $this->vectorStore->search($queryEmbedding, $topK);
        
        \Logger::info("=== 检索结果 (Top {$topK}) ===");
        foreach ($results as $i => $result) {
            $text = "【片段 " . ($i + 1) . "】相关度: " . round($result['score'] * 100, 1) . "%\n";
            $text .= str_repeat('-', 40) . "\n{$result['chunk']['text']}\n";
            \Logger::info($text);
        }
    }
}
