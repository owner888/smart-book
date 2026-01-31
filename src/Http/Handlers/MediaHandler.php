<?php
/**
 * 媒体上传处理器
 */

namespace SmartBook\Http\Handlers;

use SmartBook\Logger;
use SmartBook\Http\Context;

class MediaHandler
{
    /**
     * 上传图片
     */
    public static function uploadImage(Context $ctx): array
    {
        $body = $ctx->jsonBody() ?? [];
        $imageData = $body['image'] ?? '';  // base64编码的图片
        $fileName = $body['file_name'] ?? 'image_' . time() . '.jpg';
        
        if (empty($imageData)) {
            return ['error' => 'No image data provided'];
        }
        
        // 解码base64
        $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);
        $decodedData = base64_decode($imageData);
        
        if ($decodedData === false) {
            return ['error' => 'Invalid base64 image data'];
        }
        
        // 保存到临时目录
        $uploadDir = dirname(__DIR__, 3) . '/uploads/images';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $filePath = $uploadDir . '/' . $fileName;
        file_put_contents($filePath, $decodedData);
        
        $fileSize = filesize($filePath);
        Logger::info("📸 图片已保存: {$fileName} ({$fileSize} bytes)");
        
        return [
            'success' => true,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'url' => '/uploads/images/' . $fileName
        ];
    }
    
    /**
     * 上传文档
     */
    public static function uploadDocument(Context $ctx): array
    {
        $body = $ctx->jsonBody() ?? [];
        $content = $body['content'] ?? '';
        $fileName = $body['file_name'] ?? 'document_' . time() . '.txt';
        
        if (empty($content)) {
            return ['error' => 'No document content provided'];
        }
        
        // 保存到临时目录
        $uploadDir = dirname(__DIR__, 3) . '/uploads/documents';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $filePath = $uploadDir . '/' . $fileName;
        file_put_contents($filePath, $content);
        
        $fileSize = filesize($filePath);
        $charCount = mb_strlen($content);
        
        Logger::info("📄 文档已保存: {$fileName} ({$charCount} 字符, {$fileSize} bytes)");
        
        return [
            'success' => true,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'char_count' => $charCount,
            'url' => '/uploads/documents/' . $fileName
        ];
    }
    
    /**
     * 批量上传媒体
     */
    public static function uploadMedia(Context $ctx): array
    {
        $body = $ctx->jsonBody() ?? [];
        $mediaItems = $body['media'] ?? [];
        
        if (empty($mediaItems)) {
            return ['error' => 'No media items provided'];
        }
        
        $results = [];
        
        foreach ($mediaItems as $item) {
            $type = $item['type'] ?? '';
            
            if ($type === 'image') {
                $imageData = $item['data'] ?? '';
                $fileName = $item['file_name'] ?? 'image_' . time() . '_' . uniqid() . '.jpg';
                
                // 解码base64
                $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);
                $decodedData = base64_decode($imageData);
                
                if ($decodedData !== false) {
                    $uploadDir = dirname(__DIR__, 3) . '/uploads/images';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $filePath = $uploadDir . '/' . $fileName;
                    file_put_contents($filePath, $decodedData);
                    
                    $results[] = [
                        'type' => 'image',
                        'file_name' => $fileName,
                        'file_size' => filesize($filePath),
                        'url' => '/uploads/images/' . $fileName
                    ];
                    
                    Logger::info("📸 图片已保存: {$fileName}");
                }
            } elseif ($type === 'document') {
                $content = $item['content'] ?? '';
                $fileName = $item['file_name'] ?? 'document_' . time() . '_' . uniqid() . '.txt';
                
                if (!empty($content)) {
                    $uploadDir = dirname(__DIR__, 3) . '/uploads/documents';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $filePath = $uploadDir . '/' . $fileName;
                    file_put_contents($filePath, $content);
                    
                    $results[] = [
                        'type' => 'document',
                        'file_name' => $fileName,
                        'file_size' => filesize($filePath),
                        'char_count' => mb_strlen($content),
                        'url' => '/uploads/documents/' . $fileName
                    ];
                    
                    Logger::info("📄 文档已保存: {$fileName}");
                }
            }
        }
        
        return [
            'success' => true,
            'uploaded' => count($results),
            'items' => $results
        ];
    }
    
    /**
     * 获取上传的媒体文件
     */
    public static function getMedia(Context $ctx): ?string
    {
        $type = $ctx->param('type') ?? 'images';
        $filename = $ctx->param('filename') ?? '';
        
        if (empty($filename)) {
            http_response_code(400);
            return json_encode(['error' => 'Filename required']);
        }
        
        $uploadDir = dirname(__DIR__, 3) . "/uploads/{$type}";
        $filePath = $uploadDir . '/' . basename($filename);
        
        if (!file_exists($filePath)) {
            http_response_code(404);
            return json_encode(['error' => 'File not found']);
        }
        
        // 设置正确的Content-Type
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $contentTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'html' => 'text/html',
        ];
        
        header('Content-Type: ' . ($contentTypes[$ext] ?? 'application/octet-stream'));
        return file_get_contents($filePath);
    }
}
