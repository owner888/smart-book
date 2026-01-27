<?php
namespace SmartBook\RAG;
/**
 * 书籍索引器 - 负责检查和创建书籍向量索引
 */
use SmartBook\Logger;
use SmartBook\Parser\EpubParser;
class BookIndexer
{
    private string $booksDir;
    private string $apiKey;
    
    public function __construct(string $booksDir, string $apiKey)
    {
        $this->booksDir = $booksDir;
        $this->apiKey = $apiKey;
    }
    
    /**
     * 检查并为所有未索引的书籍创建索引
     */
    public function checkAndIndexAll(): void
    {
        if (!is_dir($this->booksDir)) {
            Logger::warn("books 目录不存在，跳过索引检查");
            return;
        }
        
        $needIndex = $this->findBooksNeedingIndex();
        
        if (empty($needIndex)) {
            Logger::info("所有书籍已有索引");
            return;
        }
        
        Logger::info("发现 " . count($needIndex) . " 本书籍需要创建索引");
        foreach ($needIndex as $book) {
            Logger::info("  - {$book['file']}");
        }
        
        foreach ($needIndex as $book) {
            $this->createIndex($book['file'], $book['path'], $book['ext']);
        }
        
        Logger::info("所有书籍索引创建完成");
    }
    
    /**
     * 查找需要创建索引的书籍
     */
    public function findBooksNeedingIndex(): array
    {
        $files = scandir($this->booksDir);
        $needIndex = [];
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['epub', 'txt'])) continue;
            
            $baseName = pathinfo($file, PATHINFO_FILENAME);
            $indexFile = $this->booksDir . '/' . $baseName . '_index.json';
            
            if (!file_exists($indexFile)) {
                $needIndex[] = [
                    'file' => $file,
                    'path' => $this->booksDir . '/' . $file,
                    'ext' => $ext
                ];
            }
        }
        
        return $needIndex;
    }
    
    /**
     * 为单本书籍创建索引
     */
    public function createIndex(string $file, string $path, string $ext): bool
    {
        $baseName = pathinfo($file, PATHINFO_FILENAME);
        $indexPath = $this->booksDir . '/' . $baseName . '_index.json';
        
        Logger::info("正在处理: {$baseName}");
        
        try {
            // 提取文本
            $text = $this->extractText($path, $ext);
            $textLength = mb_strlen($text);
            Logger::info("  提取文本: {$textLength} 字符");
            
            // 分块
            $chunker = new DocumentChunker(chunkSize: 800, chunkOverlap: 150);
            $chunks = $chunker->chunk($text);
            $chunkCount = count($chunks);
            Logger::info("  分块处理: {$chunkCount} 个块");
            
            // 生成向量嵌入
            Logger::info("  生成向量嵌入...");
            $this->generateEmbeddings($chunks, $indexPath);
            
            // 输出结果
            $indexSize = number_format(filesize($indexPath) / 1024, 2);
            Logger::info("  索引大小: {$indexSize} KB");
            Logger::info("  完成: {$baseName}");
            
            return true;
            
        } catch (\Exception $e) {
            Logger::error("  失败: {$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * 提取文本内容
     */
    private function extractText(string $path, string $ext): string
    {
        if ($ext === 'epub') {
            return EpubParser::extractText($path);
        }
        return file_get_contents($path);
    }
    
    /**
     * 生成向量嵌入并保存
     */
    private function generateEmbeddings(array $chunks, string $indexPath): void
    {
        $embedder = new EmbeddingClient($this->apiKey);
        $vectorStore = new VectorStore();
        
        $batchSize = 20;
        $chunkCount = count($chunks);
        $totalBatches = ceil($chunkCount / $batchSize);
        
        for ($i = 0; $i < $chunkCount; $i += $batchSize) {
            $batch = array_slice($chunks, $i, $batchSize);
            $embeddings = $embedder->embedBatch(array_column($batch, 'text'));
            $vectorStore->addBatch($batch, $embeddings);
            
            $currentBatch = floor($i / $batchSize) + 1;
            $progress = round(($currentBatch / $totalBatches) * 100);
            Logger::debug("  进度: {$currentBatch}/{$totalBatches} ({$progress}%)");
        }
        
        $vectorStore->save($indexPath);
    }
}
