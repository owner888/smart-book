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
    
    public function __construct(string $apiKey)
    {
        $this->llm = new GeminiClient($apiKey, GeminiClient::MODEL_GEMINI_25_FLASH);
        $this->embedder = new EmbeddingClient($apiKey);
        $this->vectorStore = new VectorStore();
        $this->chunker = new DocumentChunker(chunkSize: 800, chunkOverlap: 150);
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
        
        $context = "以下是从《{$this->bookMetadata['title']}》中检索到的相关内容：\n\n";
        foreach ($results as $i => $result) {
            $context .= "【片段 " . ($i + 1) . "】(相关度: " . round($result['score'] * 100, 1) . "%)\n{$result['chunk']['text']}\n\n";
        }
        
        $systemPrompt = "你是一个专业的书籍分析助手。用户正在阅读《{$this->bookMetadata['title']}》。根据检索到的内容回答问题。使用中文和 Markdown 格式。\n\n{$context}";
        
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
