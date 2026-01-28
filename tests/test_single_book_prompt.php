<?php
/**
 * 测试单本书的完整 System Prompt 生成
 * 
 * 用途：验证根据 config/prompts.php 中的 library 配置，为单本书生成正确的 system prompt
 */

require_once __DIR__ . '/../bootstrap.php';

use SmartBook\Parser\EpubParser;

echo "=== 单本书 System Prompt 测试 ===\n\n";

// 加载 prompts 配置
$config = require __DIR__ . '/../config/prompts.php';
$library = $config['library'];
$language = $config['language'];

// 扫描 books 目录获取 EPUB 文件
$booksDir = __DIR__ . '/../books';
$epubFiles = [];

if (is_dir($booksDir)) {
    $files = scandir($booksDir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'epub') {
            $epubFiles[] = $file;
        }
    }
}

if (empty($epubFiles)) {
    die("❌ 错误: books 目录中没有找到 EPUB 文件\n");
}

echo "📚 找到 " . count($epubFiles) . " 个 EPUB 文件:\n";
foreach ($epubFiles as $index => $file) {
    echo "  " . ($index + 1) . ". {$file}\n";
}
echo "\n";

// 选择第一本书进行测试
$selectedBook = $epubFiles[0];
$bookPath = $booksDir . '/' . $selectedBook;

echo "📖 使用第一本书进行测试: {$selectedBook}\n\n";

// 提取书籍元数据
try {
    $metadata = EpubParser::extractMetadata($bookPath);
    
    $title = $metadata['title'] ?? pathinfo($selectedBook, PATHINFO_FILENAME);
    $authors = $metadata['authors'] ?? '';
    $description = $metadata['description'] ?? '';
    $language_code = $metadata['language'] ?? '';
    $publisher = $metadata['publisher'] ?? '';
    
    echo "--- 书籍元数据 ---\n";
    echo "标题: {$title}\n";
    echo "作者: {$authors}\n";
    if (!empty($description)) {
        echo "简介: " . mb_substr($description, 0, 100) . (mb_strlen($description) > 100 ? '...' : '') . "\n";
    }
    if (!empty($language_code)) {
        echo "语言: {$language_code}\n";
    }
    if (!empty($publisher)) {
        echo "出版商: {$publisher}\n";
    }
    echo "\n";
    
} catch (Exception $e) {
    die("❌ 错误: 无法提取书籍元数据 - " . $e->getMessage() . "\n");
}

// 构建 System Prompt（参考 library 配置）
echo "--- 生成 System Prompt ---\n\n";

// 1. Book introduction
$systemPrompt = $library['book_intro'];

// 2. Book template
$bookTemplate = str_replace(
    ['{which}', '{title}', '{authors}'],
    ['', $title, $authors ?: '未知作者'],
    $library['book_template']
);
$systemPrompt .= $bookTemplate;

// 3. Optional: series (if available - 这里示例中没有，可以忽略)
// $systemPrompt .= str_replace('{series}', $seriesName, $library['series_template']);

// 4. Optional: tags (if available - 这里示例中没有，可以忽略)
// $systemPrompt .= str_replace('{tags}', $tags, $library['tags_template']);

// 5. Separator
$systemPrompt .= $library['separator'];

// 6. Markdown instruction
$systemPrompt .= $library['markdown_instruction'];

// 7. Unknown book handling
$systemPrompt .= $library['unknown_single'];

// 8. Language instruction
$languageInstruction = str_replace(
    '{language}',
    $language['default'],
    $language['instruction']
);
$systemPrompt .= ' ' . $languageInstruction;

// 显示完整的 System Prompt
echo "=================================================\n";
echo "完整 System Prompt:\n";
echo "=================================================\n";
echo $systemPrompt;
echo "\n=================================================\n\n";

// 显示字符数和估算 token 数
$charCount = mb_strlen($systemPrompt);
$estimatedTokens = intval($charCount / 2); // 粗略估算：中文约 1.5-2 字符/token

echo "📊 统计信息:\n";
echo "  字符数: {$charCount}\n";
echo "  估算 tokens: ~{$estimatedTokens}\n\n";

// 显示配置来源说明
echo "💡 配置来源:\n";
echo "  - book_intro: \"{$library['book_intro']}\"\n";
echo "  - book_template: \"{$library['book_template']}\"\n";
echo "  - separator: " . json_encode($library['separator']) . "\n";
echo "  - markdown_instruction: \"{$library['markdown_instruction']}\"\n";
echo "  - unknown_single: \"{$library['unknown_single']}\"\n";
echo "  - language.instruction: \"{$language['instruction']}\"\n";
echo "  - language.default: \"{$language['default']}\"\n\n";

// 示例对话
echo "--- 示例对话 ---\n\n";
echo "User: Can you summarize this book?\n\n";
echo "System Prompt 会告诉 AI:\n";
echo "  1. 这是一本名为《{$title}》的书\n";
echo "  2. 作者是 {$authors}\n";
echo "  3. 回答时使用 markdown 格式\n";
echo "  4. 如果 AI 不认识这本书，应该说 'the book is unknown'\n";
echo "  5. 如果可以，用中文回答\n\n";

echo "✅ 测试完成!\n";
