<?php
/**
 * RAG 书籍助手
 */

namespace SmartBook\RAG;

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
            echo "📂 从缓存加载索引...\n";
            $this->vectorStore = new VectorStore($cacheFile);
            $this->bookMetadata = EpubParser::extractMetadata($epubPath);
            echo "✅ 已加载 {$this->vectorStore->count()} 个文档块\n";
            return;
        }
        
        $this->bookMetadata = EpubParser::extractMetadata($epubPath);
        echo "📖 书籍: {$this->bookMetadata['title']}\n";
        
        echo "📄 正在提取文本...\n";
        $text = EpubParser::extractText($epubPath);
        echo "   提取了 " . mb_strlen($text) . " 个字符\n";
        
        echo "✂️  正在分块...\n";
        $chunks = $this->chunker->chunk($text);
        echo "   生成了 " . count($chunks) . " 个文档块\n";
        
        echo "🔢 正在生成向量嵌入...\n";
        $batchSize = 20;
        $totalBatches = ceil(count($chunks) / $batchSize);
        
        for ($i = 0; $i < count($chunks); $i += $batchSize) {
            $batch = array_slice($chunks, $i, $batchSize);
            $embeddings = $this->embedder->embedBatch(array_column($batch, 'text'));
            $this->vectorStore->addBatch($batch, $embeddings);
            echo "   批次 " . (floor($i / $batchSize) + 1) . "/{$totalBatches} 完成\n";
        }
        
        if ($cacheFile) {
            echo "💾 保存索引缓存...\n";
            $this->vectorStore->save($cacheFile);
        }
        
        echo "✅ 索引完成！共 {$this->vectorStore->count()} 个文档块\n\n";
    }
    
    public function ask(string $question, int $topK = 5, bool $stream = true): string
    {
        if ($this->vectorStore->isEmpty()) return '错误：请先加载书籍';
        
        echo "🔍 正在检索相关内容...\n";
        $queryEmbedding = $this->embedder->embedQuery($question);
        $results = $this->vectorStore->hybridSearch($question, $queryEmbedding, $topK, 0.6);
        
        // 使用配置文件中的提示词模板
        $systemPrompt = $this->buildSystemPrompt($results);
        
        echo "🤖 正在生成回答...\n\n--- AI 回复 ---\n";
        
        if ($stream) {
            $result = $this->llm->chatStream(
                [['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => $question]],
                function($text, $chunk, $isThought) { if (!$isThought) echo $text; },
                ['enableSearch' => false]
            );
            echo "\n";
            return $result['content'];
        } else {
            $response = $this->llm->chat([['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => $question]]);
            $content = '';
            foreach ($response['candidates'] ?? [] as $candidate) {
                foreach ($candidate['content']['parts'] ?? [] as $part) {
                    if (!($part['thought'] ?? false)) $content .= $part['text'] ?? '';
                }
            }
            echo $content . "\n";
            return $content;
        }
    }
    
    /**
     * 执行预定义操作
     */
    public function executeAction(string $actionName, int $topK = 5, string $language = 'Chinese'): string
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
    
    public function showRetrievedChunks(string $question, int $topK = 5): void
    {
        $queryEmbedding = $this->embedder->embedQuery($question);
        $results = $this->vectorStore->search($queryEmbedding, $topK);
        
        echo "=== 检索结果 (Top {$topK}) ===\n\n";
        foreach ($results as $i => $result) {
            echo "【片段 " . ($i + 1) . "】相关度: " . round($result['score'] * 100, 1) . "%\n";
            echo str_repeat('-', 40) . "\n{$result['chunk']['text']}\n\n";
        }
    }
}
