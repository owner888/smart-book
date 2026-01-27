<?php
/**
 * 书籍管理处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Http\Context;
use SmartBook\AI\GeminiContextCache;
use SmartBook\Logger;
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
        $booksDir = BOOKS_DIR;
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
        $model = $body['model'] ?? 'gemini-2.0-flash';
        
        if (empty($bookFile)) {
            return ['error' => 'Missing book parameter'];
        }
        
        $bookPath = BOOKS_DIR . '/' . $bookFile;
        
        if (!file_exists($bookPath)) {
            return ['error' => 'Book not found: ' . $bookFile];
        }
        
        $baseName = pathinfo($bookFile, PATHINFO_FILENAME);
        $indexPath = BOOKS_DIR . '/' . $baseName . '_index.json';
        
        $GLOBALS['selected_book'] = [
            'path' => $bookPath,
            'cache' => $indexPath,
            'hasIndex' => file_exists($indexPath),
        ];
        
        // 检查并创建 Context Cache
        $cacheStatus = self::ensureContextCache($bookFile, $bookPath, $model);
        
        return [
            'success' => true,
            'book' => $bookFile,
            'path' => $bookPath,
            'hasIndex' => file_exists($indexPath),
            'contextCache' => $cacheStatus,
            'message' => file_exists($indexPath) 
                ? "已选择书籍: {$baseName}" 
                : "已选择书籍: {$baseName}（需要先创建索引）",
        ];
    }
    
    /**
     * 确保书籍的 Context Cache 存在
     */
    private static function ensureContextCache(string $bookFile, string $bookPath, string $model): array
    {
        try {
            // 先提取内容，用于计算 MD5
            $ext = strtolower(pathinfo($bookPath, PATHINFO_EXTENSION));
            if ($ext === 'epub') {
                $content = \SmartBook\Parser\EpubParser::extractText($bookPath);
            } else {
                $content = file_get_contents($bookPath);
            }
            
            if (empty($content)) {
                Logger::error("无法提取书籍内容: {$bookFile}");
                return ['exists' => false, 'created' => false, 'error' => '无法提取书籍内容'];
            }
            
            // 使用文件内容 MD5 作为唯一标识
            $contentMd5 = md5($content);
            
            $cacheClient = new GeminiContextCache(GEMINI_API_KEY, $model);
            $bookCache = $cacheClient->getBookCache($contentMd5);
            
            if ($bookCache) {
                Logger::info("✅ Context Cache 已存在: {$bookFile} (MD5: {$contentMd5})");
                return [
                    'exists' => true,
                    'created' => false,
                    'tokenCount' => $bookCache['usageMetadata']['totalTokenCount'] ?? 0,
                ];
            }
            
            // 缓存不存在，创建新缓存
            Logger::info("🔄 创建 Context Cache: {$bookFile} (MD5: {$contentMd5})");
            
            $createResult = $cacheClient->createForBook($bookFile, $content, 7200);
            
            if ($createResult['success']) {
                $newCache = $cacheClient->getBookCache($contentMd5);
                Logger::info("✅ Context Cache 创建成功: {$bookFile}");
                return [
                    'exists' => true,
                    'created' => true,
                    'tokenCount' => $newCache['usageMetadata']['totalTokenCount'] ?? 0,
                ];
            } else {
                Logger::error("Context Cache 创建失败: " . ($createResult['error'] ?? 'Unknown'));
                return ['exists' => false, 'created' => false, 'error' => $createResult['error'] ?? 'Unknown'];
            }
            
        } catch (\Exception $e) {
            Logger::error("Context Cache 检查失败: " . $e->getMessage());
            return ['exists' => false, 'created' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 上传书籍文件
     */
    public static function uploadBook(Context $ctx): array
    {
        try {
            Logger::info("📥 收到书籍上传请求");
            
            $request = $ctx->request();
            $files = $request->file();
            
            Logger::info("📋 上传文件信息: " . json_encode($files));
            
            if (empty($files) || !isset($files['file'])) {
                Logger::error("❌ 没有找到上传文件");
                return ['success' => false, 'error' => '没有上传文件'];
            }
            
            $file = $files['file'];
            $originalName = $file['name'];
            $tmpPath = $file['tmp_name'];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            
            Logger::info("📚 文件名: {$originalName}, 临时路径: {$tmpPath}");
            
            // 验证文件类型
            if (!in_array($ext, ['epub', 'txt'])) {
                Logger::error("❌ 不支持的文件格式: {$ext}");
                return ['success' => false, 'error' => '不支持的文件格式，仅支持 EPUB 和 TXT'];
            }
            
            // 保存到 books 目录
            if (!is_dir(BOOKS_DIR)) {
                Logger::info("📁 创建 books 目录: " . BOOKS_DIR);
                mkdir(BOOKS_DIR, 0755, true);
            }
            
            $destPath = BOOKS_DIR . '/' . $originalName;
            
            // 如果文件已存在，直接返回成功
            if (file_exists($destPath)) {
                Logger::info("📚 书籍已存在: {$originalName}");
                return [
                    'success' => true,
                    'message' => '书籍已存在',
                    'file' => $originalName,
                    'existed' => true
                ];
            }
            
            // 检查临时文件是否存在
            if (!file_exists($tmpPath)) {
                Logger::error("❌ 临时文件不存在: {$tmpPath}");
                return ['success' => false, 'error' => '临时文件不存在'];
            }
            
            // 检查临时文件是否可读
            if (!is_readable($tmpPath)) {
                Logger::error("❌ 临时文件不可读: {$tmpPath}");
                return ['success' => false, 'error' => '临时文件不可读'];
            }
            
            // 检查目标目录是否可写
            if (!is_writable(BOOKS_DIR)) {
                Logger::error("❌ 目标目录不可写: " . BOOKS_DIR);
                return ['success' => false, 'error' => '目标目录不可写'];
            }
            
            Logger::info("💾 保存文件: {$tmpPath} -> {$destPath}");
            Logger::info("📂 目录权限: " . substr(sprintf('%o', fileperms(BOOKS_DIR)), -4));
            Logger::info("📄 临时文件大小: " . filesize($tmpPath) . " bytes");
            
            // Workerman 使用 copy 而不是 move_uploaded_file
            if (!copy($tmpPath, $destPath)) {
                Logger::error("❌ 文件保存失败");
                return ['success' => false, 'error' => '文件保存失败'];
            }
            
            @unlink($tmpPath);
            Logger::info("✅ 文件保存成功");
            
            Logger::info("✅ 书籍上传成功: {$originalName}");
            
            return [
                'success' => true,
                'message' => '书籍上传成功',
                'file' => $originalName,
                'path' => $destPath,
                'size' => filesize($destPath)
            ];
            
        } catch (\Exception $e) {
            Logger::error("书籍上传失败: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
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
        
        $bookPath = BOOKS_DIR . '/' . $bookFile;
        
        if (!file_exists($bookPath)) {
            return ['error' => 'Book not found: ' . $bookFile];
        }
        
        $baseName = pathinfo($bookFile, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($bookFile, PATHINFO_EXTENSION));
        $indexPath = BOOKS_DIR . '/' . $baseName . '_index.json';
        
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
