<?php
/**
 * 测试 RAG 书籍助手 - 更多问题
 */

require_once __DIR__ . '/calibre_rag.php';

// 从 ~/.zprofile 读取 API Key
$zprofile = file_get_contents('/Users/kaka/.zprofile');
preg_match('/GEMINI_API_KEY="([^"]+)"/', $zprofile, $matches);
$apiKey = $matches[1] ?? '';

// 创建助手
$assistant = new BookRAGAssistant($apiKey);

// 从缓存加载书籍
$epubPath = '/Users/kaka/Documents/西游记.epub';
$cacheFile = '/Users/kaka/Documents/西游记_index.json';

$assistant->loadBook($epubPath, $cacheFile);

// 测试更具体的问题
$question = $argv[1] ?? '孙悟空大闹天宫';

echo "\n" . str_repeat('=', 50) . "\n";
echo "❓ 问题: {$question}\n";
echo str_repeat('=', 50) . "\n\n";

$assistant->ask($question, topK: 8);
