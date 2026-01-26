<?php
/**
 * 书籍管理处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Http\Context;
use SmartBook\RAG\DocumentChunker;
use SmartBook\RAG\EmbeddingClient;
use SmartBook\RAG\VectorStore;
use Workerman\Protocols\Http\Response;

class BookHandler
{
    /**
     * 获取所有可用书籍列表
     */
    public static function getBooks(): array
    {
        $booksDir = dirname(__DIR__, 3) . '/books';
        $books = [];
        $currentBook = null;
        
        $currentBookPath = ConfigHandler::getCurrentBookPath();
        if ($currentBookPath) {
            $currentBook = basename($currentBookPath);
        }
        
        if (is_dir($booksDir)) {
            $files = scandir($booksDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                
                $filePath = $booksDir . '/' . $file;
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                
                if (!in_array($ext, ['epub', 'txt'])) continue;
                
                $baseName = pathinfo($file, PATHINFO_FILENAME);
                $indexFile = $booksDir . '/' . $baseName . '_index.json';
                $hasIndex = file_exists($indexFile);
                
                $title = $baseName;
                $author = '';
                $fileSize = filesize($filePath);
                $indexSize = $hasIndex ? filesize($indexFile) : 0;
                $chunkCount = 0;
                
                if ($ext === 'epub') {
                    try {
                        $metadata = \SmartBook\Parser\EpubParser::extractMetadata($filePath);
                        $title = $metadata['title'] ?? $baseName;
                        $author = $metadata['authors'] ?? '';
                    } catch (\Exception $e) {}
                }
                
                if ($hasIndex) {
                    try {
                        $indexData = json_decode(file_get_contents($indexFile), true);
                        $chunkCount = count($indexData['chunks'] ?? []);
                    } catch (\Exception $e) {}
                }
                
                $books[] = [
                    'file' => $file,
                    'title' => $title,
                    'author' => $author,
                    'format' => strtoupper($ext),
                    'fileSize' => self::formatFileSize($fileSize),
                    'hasIndex' => $hasIndex,
                    'indexSize' => $hasIndex ? self::formatFileSize($indexSize) : null,
                    'chunkCount' => $chunkCount,
                    'isSelected' => ($file === $currentBook),
                ];
            }
        }
        
        usort($books, fn($a, $b) => strcmp($a['title'], $b['title']));
        
        return [
            'books' => $books,
            'currentBook' => $currentBook,
            'booksDir' => $booksDir,
        ];
    }
    
    /**
     * 选择当前书籍
     */
    public static function selectBook(Context $ctx): array
    {
        $body = $ctx->jsonBody() ?? [];
        $bookFile = $body['book'] ?? '';
        
        if (empty($bookFile)) {
            return ['error' => 'Missing book parameter'];
        }
        
        $booksDir = dirname(__DIR__, 3) . '/books';
        $bookPath = $booksDir . '/' . $bookFile;
        
        if (!file_exists($bookPath)) {
            return ['error' => 'Book not found: ' . $bookFile];
        }
        
        $baseName = pathinfo($bookFile, PATHINFO_FILENAME);
        $indexPath = $booksDir . '/' . $baseName . '_index.json';
        
        $GLOBALS['selected_book'] = [
            'path' => $bookPath,
            'cache' => $indexPath,
            'hasIndex' => file_exists($indexPath),
        ];
        
        return [
            'success' => true,
            'book' => $bookFile,
            'path' => $bookPath,
            'hasIndex' => file_exists($indexPath),
            'message' => file_exists($indexPath) 
                ? "已选择书籍: {$baseName}" 
                : "已选择书籍: {$baseName}（需要先创建索引）",
        ];
    }
    
    /**
     * 为书籍创建向量索引（SSE 流式返回进度）
     */
    public static function indexBook(Context $ctx): ?array
    {
        $connection = $ctx->connection();
        $body = $ctx->jsonBody() ?? [];
        $bookFile = $body['book'] ?? '';
        
        if (empty($bookFile)) {
            return ['error' => 'Missing book parameter'];
        }
        
        $booksDir = dirname(__DIR__, 3) . '/books';
        $bookPath = $booksDir . '/' . $bookFile;
        
        if (!file_exists($bookPath)) {
            return ['error' => 'Book not found: ' . $bookFile];
        }
        
        $baseName = pathinfo($bookFile, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($bookFile, PATHINFO_EXTENSION));
        $indexPath = $booksDir . '/' . $baseName . '_index.json';
        
        $headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Access-Control-Allow-Origin' => '*'];
        $connection->send(new Response(200, $headers, ''));
        
        try {
            StreamHelper::sendSSE($connection, 'progress', json_encode(['step' => 'start', 'message' => "开始处理: {$baseName}"]));
            
            StreamHelper::sendSSE($connection, 'progress', json_encode(['step' => 'extract', 'message' => '正在提取文本...']));
            
            if ($ext === 'epub') {
                $text = \SmartBook\Parser\EpubParser::extractText($bookPath);
            } else {
                $text = file_get_contents($bookPath);
            }
            
            $textLength = mb_strlen($text);
            StreamHelper::sendSSE($connection, 'progress', json_encode(['step' => 'extract_done', 'message' => "提取完成: {$textLength} 字符"]));
            
            StreamHelper::sendSSE($connection, 'progress', json_encode(['step' => 'chunk', 'message' => '正在分块...']));
            
            $chunker = new DocumentChunker(chunkSize: 800, chunkOverlap: 150);
            $chunks = $chunker->chunk($text);
            $chunkCount = count($chunks);
            
            StreamHelper::sendSSE($connection, 'progress', json_encode(['step' => 'chunk_done', 'message' => "分块完成: {$chunkCount} 个块"]));
            
            StreamHelper::sendSSE($connection, 'progress', json_encode(['step' => 'embed', 'message' => '正在生成向量嵌入...']));
            
            $embedder = new EmbeddingClient(GEMINI_API_KEY);
            $vectorStore = new VectorStore();
            
            $batchSize = 20;
            $totalBatches = ceil($chunkCount / $batchSize);
            
            for ($i = 0; $i < $chunkCount; $i += $batchSize) {
                $batch = array_slice($chunks, $i, $batchSize);
                $embeddings = $embedder->embedBatch(array_column($batch, 'text'));
                $vectorStore->addBatch($batch, $embeddings);
                
                $currentBatch = floor($i / $batchSize) + 1;
                $progress = round(($currentBatch / $totalBatches) * 100);
                StreamHelper::sendSSE($connection, 'progress', json_encode([
                    'step' => 'embed_batch', 
                    'batch' => $currentBatch, 
                    'total' => $totalBatches,
                    'progress' => $progress,
                    'message' => "向量化进度: {$currentBatch}/{$totalBatches} ({$progress}%)"
                ]));
            }
            
            StreamHelper::sendSSE($connection, 'progress', json_encode(['step' => 'save', 'message' => '正在保存索引...']));
            $vectorStore->save($indexPath);
            
            $indexSize = self::formatFileSize(filesize($indexPath));
            StreamHelper::sendSSE($connection, 'done', json_encode([
                'success' => true,
                'book' => $bookFile,
                'chunkCount' => $chunkCount,
                'indexSize' => $indexSize,
                'message' => "索引创建完成！共 {$chunkCount} 个块，索引大小 {$indexSize}"
            ]));
            
        } catch (\Exception $e) {
            StreamHelper::sendSSE($connection, 'error', $e->getMessage());
        }
        
        $connection->close();
        return null;
    }
    
    /**
     * 格式化文件大小
     */
    public static function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
