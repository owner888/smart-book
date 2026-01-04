<?php
/**
 * Calibre RAG (Retrieval-Augmented Generation) 实现
 * 
 * 智能地从书籍中检索相关内容，而不是发送整本书
 */

require_once __DIR__ . '/calibre_ai_prompts.php';  // 同目录

// ===================================
// 向量嵌入客户端
// ===================================

class EmbeddingClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    
    const MODEL_GEMINI = 'text-embedding-004';
    
    public function __construct(string $apiKey, string $model = self::MODEL_GEMINI)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    }
    
    /**
     * 为查询文本生成嵌入向量（用于检索）
     */
    public function embedQuery(string $text): array
    {
        return $this->embedSingle($text, 'RETRIEVAL_QUERY');
    }
    
    /**
     * 为文档文本生成嵌入向量（用于索引）
     */
    public function embed(string $text): array
    {
        return $this->embedSingle($text, 'RETRIEVAL_DOCUMENT');
    }
    
    /**
     * 单个文本嵌入
     */
    private function embedSingle(string $text, string $taskType): array
    {
        $url = "{$this->baseUrl}/models/{$this->model}:embedContent?key={$this->apiKey}";
        
        $data = [
            'model' => "models/{$this->model}",
            'content' => ['parts' => [['text' => $text]]],
            'taskType' => $taskType,
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        unset($ch);
        
        if ($error) {
            echo "❌ Embedding curl 错误: {$error}\n";
            return [];
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['error'])) {
            echo "❌ Embedding API 错误: " . ($result['error']['message'] ?? 'Unknown') . "\n";
            return [];
        }
        
        return $result['embedding']['values'] ?? [];
    }
    
    /**
     * 批量生成文档嵌入向量
     */
    public function embedBatch(array $texts): array
    {
        $url = "{$this->baseUrl}/models/{$this->model}:batchEmbedContents?key={$this->apiKey}";
        
        $requests = array_map(fn($text) => [
            'model' => "models/{$this->model}",
            'content' => ['parts' => [['text' => $text]]],
            'taskType' => 'RETRIEVAL_DOCUMENT',
        ], $texts);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['requests' => $requests]),
            CURLOPT_TIMEOUT => 60,
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        unset($ch);  // PHP 8.0+ 自动销毁 CurlHandle
        
        if ($error) {
            echo "❌ Embedding batch curl 错误: {$error}\n";
            return array_fill(0, count($texts), []);
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['error'])) {
            echo "❌ Embedding batch API 错误: " . ($result['error']['message'] ?? 'Unknown') . "\n";
            return array_fill(0, count($texts), []);
        }
        
        return array_map(
            fn($e) => $e['values'] ?? [],
            $result['embeddings'] ?? []
        );
    }
}

// ===================================
// 文档分块器
// ===================================

class DocumentChunker
{
    private int $chunkSize;
    private int $chunkOverlap;
    
    public function __construct(int $chunkSize = 500, int $chunkOverlap = 100)
    {
        $this->chunkSize = $chunkSize;
        $this->chunkOverlap = $chunkOverlap;
    }
    
    /**
     * 将文本分割成块
     */
    public function chunk(string $text): array
    {
        $chunks = [];
        $text = $this->cleanText($text);
        
        // 按段落分割
        $paragraphs = preg_split('/\n{2,}/', $text);
        
        $currentChunk = '';
        $chunkIndex = 0;
        
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (empty($para)) continue;
            
            // 如果段落本身超过 chunk 大小，按句子分割
            if (mb_strlen($para) > $this->chunkSize) {
                // 先保存当前 chunk
                if (!empty($currentChunk)) {
                    $chunks[] = $this->createChunk($currentChunk, $chunkIndex++);
                    $currentChunk = '';
                }
                
                // 分割长段落
                $sentences = $this->splitIntoSentences($para);
                $sentenceChunk = '';
                
                foreach ($sentences as $sentence) {
                    if (mb_strlen($sentenceChunk . $sentence) > $this->chunkSize && !empty($sentenceChunk)) {
                        $chunks[] = $this->createChunk($sentenceChunk, $chunkIndex++);
                        // 保留重叠部分
                        $sentenceChunk = mb_substr($sentenceChunk, -$this->chunkOverlap) . $sentence;
                    } else {
                        $sentenceChunk .= $sentence;
                    }
                }
                
                if (!empty($sentenceChunk)) {
                    $currentChunk = $sentenceChunk;
                }
            } else {
                // 累积段落
                if (mb_strlen($currentChunk . "\n\n" . $para) > $this->chunkSize && !empty($currentChunk)) {
                    $chunks[] = $this->createChunk($currentChunk, $chunkIndex++);
                    // 保留重叠部分
                    $overlap = mb_substr($currentChunk, -$this->chunkOverlap);
                    $currentChunk = $overlap . "\n\n" . $para;
                } else {
                    $currentChunk .= (empty($currentChunk) ? '' : "\n\n") . $para;
                }
            }
        }
        
        // 保存最后一个 chunk
        if (!empty($currentChunk)) {
            $chunks[] = $this->createChunk($currentChunk, $chunkIndex);
        }
        
        return $chunks;
    }
    
    private function createChunk(string $text, int $index): array
    {
        return [
            'id' => $index,
            'text' => trim($text),
            'length' => mb_strlen($text),
        ];
    }
    
    private function cleanText(string $text): string
    {
        // 清理多余空白
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
    
    private function splitIntoSentences(string $text): array
    {
        // 支持中文和英文句子分割
        return preg_split('/(?<=[。！？.!?])\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    }
}

// ===================================
// 向量存储
// ===================================

class VectorStore
{
    private array $chunks = [];
    private array $embeddings = [];
    private ?string $cacheFile = null;
    
    public function __construct(?string $cacheFile = null)
    {
        $this->cacheFile = $cacheFile;
        if ($cacheFile && file_exists($cacheFile)) {
            $this->load();
        }
    }
    
    /**
     * 添加文档块和其嵌入向量
     */
    public function add(array $chunk, array $embedding): void
    {
        $this->chunks[] = $chunk;
        $this->embeddings[] = $embedding;
    }
    
    /**
     * 批量添加
     */
    public function addBatch(array $chunks, array $embeddings): void
    {
        foreach ($chunks as $i => $chunk) {
            $this->add($chunk, $embeddings[$i] ?? []);
        }
    }
    
    /**
     * 检索最相关的文档块（纯向量检索）
     */
    public function search(array $queryEmbedding, int $topK = 5): array
    {
        if (empty($this->embeddings)) {
            return [];
        }
        
        $scores = [];
        foreach ($this->embeddings as $i => $embedding) {
            $scores[$i] = $this->cosineSimilarity($queryEmbedding, $embedding);
        }
        
        arsort($scores);
        
        $results = [];
        $count = 0;
        foreach ($scores as $i => $score) {
            if ($count >= $topK) break;
            $results[] = [
                'chunk' => $this->chunks[$i],
                'score' => $score,
                'method' => 'vector',
            ];
            $count++;
        }
        
        return $results;
    }
    
    /**
     * 混合检索：关键词 + 向量
     */
    public function hybridSearch(string $query, array $queryEmbedding, int $topK = 5, float $keywordWeight = 0.5): array
    {
        if (empty($this->chunks)) {
            return [];
        }
        
        $scores = [];
        
        // 1. 关键词搜索（BM25 简化版）
        $keywords = $this->extractKeywords($query);
        foreach ($this->chunks as $i => $chunk) {
            $keywordScore = $this->calculateKeywordScore($chunk['text'], $keywords);
            $scores[$i] = ['keyword' => $keywordScore, 'vector' => 0.0];
        }
        
        // 2. 向量搜索
        if (!empty($queryEmbedding) && !empty($this->embeddings)) {
            foreach ($this->embeddings as $i => $embedding) {
                $scores[$i]['vector'] = $this->cosineSimilarity($queryEmbedding, $embedding);
            }
        }
        
        // 3. 归一化并合并分数
        $maxKeyword = max(array_column($scores, 'keyword')) ?: 1;
        $maxVector = max(array_column($scores, 'vector')) ?: 1;
        
        $finalScores = [];
        foreach ($scores as $i => $s) {
            $normKeyword = $s['keyword'] / $maxKeyword;
            $normVector = $s['vector'] / $maxVector;
            $finalScores[$i] = $keywordWeight * $normKeyword + (1 - $keywordWeight) * $normVector;
        }
        
        arsort($finalScores);
        
        $results = [];
        $count = 0;
        foreach ($finalScores as $i => $score) {
            if ($count >= $topK) break;
            $results[] = [
                'chunk' => $this->chunks[$i],
                'score' => $score,
                'keyword_score' => $scores[$i]['keyword'],
                'vector_score' => $scores[$i]['vector'],
                'method' => 'hybrid',
            ];
            $count++;
        }
        
        return $results;
    }
    
    /**
     * 提取中文关键词
     */
    private function extractKeywords(string $query): array
    {
        // 简单分词：按标点和空格分割，保留2字以上的词
        $words = preg_split('/[\s\p{P}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
        $keywords = [];
        foreach ($words as $word) {
            if (mb_strlen($word) >= 2) {
                $keywords[] = $word;
            }
            // 对于长词，也加入2-gram
            if (mb_strlen($word) > 2) {
                for ($i = 0; $i < mb_strlen($word) - 1; $i++) {
                    $keywords[] = mb_substr($word, $i, 2);
                }
            }
        }
        return array_unique($keywords);
    }
    
    /**
     * 计算关键词匹配分数
     */
    private function calculateKeywordScore(string $text, array $keywords): float
    {
        if (empty($keywords)) {
            return 0.0;
        }
        
        $score = 0.0;
        $textLower = mb_strtolower($text);
        
        foreach ($keywords as $keyword) {
            $keywordLower = mb_strtolower($keyword);
            // 计算关键词出现次数
            $count = mb_substr_count($textLower, $keywordLower);
            if ($count > 0) {
                // TF-IDF 简化：log(1 + count) * 关键词长度权重
                $score += log(1 + $count) * mb_strlen($keyword);
            }
        }
        
        return $score;
    }
    
    /**
     * 计算余弦相似度
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }
        
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        
        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        
        $normA = sqrt($normA);
        $normB = sqrt($normB);
        
        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }
        
        return $dotProduct / ($normA * $normB);
    }
    
    /**
     * 保存到文件
     */
    public function save(?string $file = null): void
    {
        $file = $file ?? $this->cacheFile;
        if (!$file) return;
        
        file_put_contents($file, json_encode([
            'chunks' => $this->chunks,
            'embeddings' => $this->embeddings,
        ], JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 从文件加载
     */
    public function load(?string $file = null): void
    {
        $file = $file ?? $this->cacheFile;
        if (!$file || !file_exists($file)) return;
        
        $data = json_decode(file_get_contents($file), true);
        $this->chunks = $data['chunks'] ?? [];
        $this->embeddings = $data['embeddings'] ?? [];
    }
    
    public function count(): int
    {
        return count($this->chunks);
    }
    
    public function isEmpty(): bool
    {
        return empty($this->chunks);
    }
}

// ===================================
// EPUB 解析器
// ===================================

class EpubParser
{
    /**
     * 从 EPUB 文件提取文本内容
     */
    public static function extractText(string $epubPath): string
    {
        $zip = new ZipArchive();
        if ($zip->open($epubPath) !== true) {
            echo "❌ 无法打开 EPUB 文件: {$epubPath}\n";
            return '';
        }
        
        $text = '';
        $htmlFiles = [];
        
        // 获取所有 HTML/XHTML 文件
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (preg_match('/\.(html?|xhtml)$/i', $filename)) {
                $htmlFiles[] = $filename;
            }
        }
        
        // 按文件名排序以保持章节顺序
        sort($htmlFiles);
        
        foreach ($htmlFiles as $filename) {
            $content = $zip->getFromName($filename);
            if ($content) {
                // 提取章节标题
                if (preg_match('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', $content, $matches)) {
                    $chapterTitle = strip_tags($matches[1]);
                    $text .= "\n\n### {$chapterTitle}\n\n";
                }
                
                // 移除 HTML 标签，保留文本
                $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
                $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);
                $content = strip_tags($content);
                $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $content = preg_replace('/\s+/', ' ', $content);
                $content = trim($content);
                
                if (!empty($content) && mb_strlen($content) > 50) {
                    $text .= $content . "\n\n";
                }
            }
        }
        
        $zip->close();
        return $text;
    }
    
    /**
     * 提取 EPUB 元数据
     */
    public static function extractMetadata(string $epubPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($epubPath) !== true) {
            return [];
        }
        
        $metadata = [
            'title' => basename($epubPath, '.epub'),
            'authors' => '',
            'description' => '',
        ];
        
        // 尝试读取 OPF 文件
        $opfContent = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (preg_match('/\.opf$/i', $filename)) {
                $opfContent = $zip->getFromName($filename);
                break;
            }
        }
        
        if ($opfContent) {
            // 解析标题
            if (preg_match('/<dc:title[^>]*>(.*?)<\/dc:title>/is', $opfContent, $matches)) {
                $metadata['title'] = trim(strip_tags($matches[1]));
            }
            
            // 解析作者
            if (preg_match('/<dc:creator[^>]*>(.*?)<\/dc:creator>/is', $opfContent, $matches)) {
                $metadata['authors'] = trim(strip_tags($matches[1]));
            }
            
            // 解析描述
            if (preg_match('/<dc:description[^>]*>(.*?)<\/dc:description>/is', $opfContent, $matches)) {
                $metadata['description'] = trim(strip_tags($matches[1]));
            }
        }
        
        $zip->close();
        return $metadata;
    }
}

// ===================================
// RAG 书籍助手
// ===================================

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
    
    /**
     * 加载并索引书籍
     */
    public function loadBook(string $epubPath, ?string $cacheFile = null): void
    {
        // 检查缓存
        if ($cacheFile && file_exists($cacheFile)) {
            echo "📂 从缓存加载索引...\n";
            $this->vectorStore = new VectorStore($cacheFile);
            $this->bookMetadata = EpubParser::extractMetadata($epubPath);
            echo "✅ 已加载 {$this->vectorStore->count()} 个文档块\n";
            return;
        }
        
        // 提取元数据
        $this->bookMetadata = EpubParser::extractMetadata($epubPath);
        echo "📖 书籍: {$this->bookMetadata['title']}\n";
        if ($this->bookMetadata['authors']) {
            echo "✍️  作者: {$this->bookMetadata['authors']}\n";
        }
        
        // 提取文本
        echo "📄 正在提取文本...\n";
        $text = EpubParser::extractText($epubPath);
        echo "   提取了 " . mb_strlen($text) . " 个字符\n";
        
        // 分块
        echo "✂️  正在分块...\n";
        $chunks = $this->chunker->chunk($text);
        echo "   生成了 " . count($chunks) . " 个文档块\n";
        
        // 生成嵌入向量（分批处理）
        echo "🔢 正在生成向量嵌入...\n";
        $batchSize = 20;
        $totalBatches = ceil(count($chunks) / $batchSize);
        
        for ($i = 0; $i < count($chunks); $i += $batchSize) {
            $batch = array_slice($chunks, $i, $batchSize);
            $texts = array_column($batch, 'text');
            
            $embeddings = $this->embedder->embedBatch($texts);
            $this->vectorStore->addBatch($batch, $embeddings);
            
            $currentBatch = floor($i / $batchSize) + 1;
            echo "   批次 {$currentBatch}/{$totalBatches} 完成\n";
        }
        
        // 保存缓存
        if ($cacheFile) {
            echo "💾 保存索引缓存...\n";
            $this->vectorStore->save($cacheFile);
        }
        
        echo "✅ 索引完成！共 {$this->vectorStore->count()} 个文档块\n\n";
    }
    
    /**
     * 提问并获取答案（使用混合检索）
     */
    public function ask(string $question, int $topK = 5, bool $stream = true): string
    {
        if ($this->vectorStore->isEmpty()) {
            echo "❌ 请先加载书籍！\n";
            return '错误：请先加载书籍';
        }
        
        // 1. 为问题生成嵌入向量
        echo "🔍 正在检索相关内容（混合检索）...\n";
        $queryEmbedding = $this->embedder->embedQuery($question);
        
        // 2. 混合检索：关键词 (60%) + 向量 (40%)
        $results = $this->vectorStore->hybridSearch($question, $queryEmbedding, $topK, 0.6);
        
        // 3. 构建上下文
        $context = "以下是从《{$this->bookMetadata['title']}》中检索到的相关内容：\n\n";
        foreach ($results as $i => $result) {
            $score = round($result['score'] * 100, 1);
            $context .= "【片段 " . ($i + 1) . "】(相关度: {$score}%)\n";
            $context .= $result['chunk']['text'] . "\n\n";
        }
        
        // 4. 构建提示词
        $systemPrompt = <<<EOT
你是一个专业的书籍分析助手。用户正在阅读《{$this->bookMetadata['title']}》。

根据以下从书中检索到的内容片段，回答用户的问题。
- 如果检索到的内容不足以回答问题，请诚实说明
- 使用中文回答
- 使用 Markdown 格式

{$context}
EOT;

        // 5. 调用 LLM
        echo "🤖 正在生成回答...\n\n";
        echo "--- AI 回复 ---\n";
        
        if ($stream) {
            $result = $this->llm->chatStream(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $question],
                ],
                function($text, $chunk, $isThought) {
                    if (!$isThought) {
                        echo $text;
                    }
                },
                ['enableSearch' => false]
            );
            echo "\n";
            return $result['content'];
        } else {
            $response = $this->llm->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $question],
            ]);
            
            $content = '';
            foreach ($response['candidates'] ?? [] as $candidate) {
                foreach ($candidate['content']['parts'] ?? [] as $part) {
                    if (!($part['thought'] ?? false)) {
                        $content .= $part['text'] ?? '';
                    }
                }
            }
            echo $content . "\n";
            return $content;
        }
    }
    
    /**
     * 显示检索到的内容（用于调试）
     */
    public function showRetrievedChunks(string $question, int $topK = 5): void
    {
        $queryEmbedding = $this->embedder->embedQuery($question);
        $results = $this->vectorStore->search($queryEmbedding, $topK);
        
        echo "=== 检索结果 (Top {$topK}) ===\n\n";
        foreach ($results as $i => $result) {
            $score = round($result['score'] * 100, 1);
            echo "【片段 " . ($i + 1) . "】相关度: {$score}%\n";
            echo str_repeat('-', 40) . "\n";
            echo $result['chunk']['text'] . "\n\n";
        }
    }
}

// ===================================
// 使用示例
// ===================================

/*
// 初始化
$apiKey = 'your-gemini-api-key';
$assistant = new BookRAGAssistant($apiKey);

// 加载书籍（首次会建立索引，之后从缓存加载）
$assistant->loadBook(
    '/path/to/book.epub',
    '/path/to/cache/book_index.json'  // 可选缓存文件
);

// 提问
$assistant->ask('介绍一下书中的主要人物');
$assistant->ask('这本书的主题是什么？');
$assistant->ask('第三章讲了什么内容？');

// 调试：只显示检索结果
$assistant->showRetrievedChunks('主要人物', 3);
*/
