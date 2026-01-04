<?php
/**
 * 调试 RAG 检索效果
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

// 问题
$question = $argv[1] ?? '孙悟空大闹天宫';

echo "\n=== 调试检索结果 ===\n";
echo "问题: {$question}\n\n";

// 显示检索到的内容
$assistant->showRetrievedChunks($question, 10);
