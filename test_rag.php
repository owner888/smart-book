<?php
/**
 * 测试 RAG 书籍助手
 */

require_once __DIR__ . '/calibre_rag.php';

// 从 ~/.zprofile 读取 API Key
$zprofile = file_get_contents('/Users/kaka/.zprofile');
preg_match('/GEMINI_API_KEY="([^"]+)"/', $zprofile, $matches);
$apiKey = $matches[1] ?? '';

if (empty($apiKey)) {
    die("错误: 无法从 ~/.zprofile 读取 GEMINI_API_KEY\n");
}

echo "=== RAG 书籍助手测试 ===\n\n";

// 创建助手
$assistant = new BookRAGAssistant($apiKey);

// 加载书籍（使用缓存加速后续加载）
$epubPath = '/Users/kaka/Documents/西游记.epub';
$cacheFile = '/Users/kaka/Documents/西游记_index.json';

$assistant->loadBook($epubPath, $cacheFile);

// 提问
echo "\n" . str_repeat('=', 50) . "\n";
echo "❓ 问题: 帮我介绍一下书中的主要人物\n";
echo str_repeat('=', 50) . "\n\n";

$assistant->ask('帮我介绍一下书中的主要人物');

echo "\n✅ RAG 测试完成！\n";
