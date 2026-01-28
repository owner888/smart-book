<?php
/**
 * 测试多本书的完整 System Prompt 生成
 * 
 * 用途：验证根据 config/prompts.php 中的 library 配置，为多本书生成正确的 system prompt
 */

require_once __DIR__ . '/../bootstrap.php';

use SmartBook\Parser\EpubParser;

echo "=== 多本书 System Prompt 测试 ===\n\n";

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

// 决定使用多少本书（最多3本）
$maxBooks = min(count($epubFiles), 3);
$selectedBooks = array_slice($epubFiles, 0, $maxBooks);

echo "📖 使用 {$maxBooks} 本书进行测试\n\n";

// 提取所有书籍的元数据
$booksMetadata = [];
foreach ($selectedBooks as $index => $bookFile) {
    $bookPath = $booksDir . '/' . $bookFile;
    
    try {
        $metadata = EpubParser::extractMetadata($bookPath);
        
        $title = $metadata['title'] ?? pathinfo($bookFile, PATHINFO_FILENAME);
        $authors = $metadata['authors'] ?? '';
        $description = $metadata['description'] ?? '';
        $language_code = $metadata['language'] ?? '';
        $publisher = $metadata['publisher'] ?? '';
        
        $booksMetadata[] = [
            'file' => $bookFile,
            'title' => $title,
            'authors' => $authors,
            'description' => $description,
            'language' => $language_code,
            'publisher' => $publisher,
        ];
        
        echo "--- 书籍 " . ($index + 1) . " 元数据 ---\n";
        echo "文件: {$bookFile}\n";
        echo "标题: {$title}\n";
        echo "作者: {$authors}\n";
        if (!empty($description)) {
            echo "简介: " . mb_substr($description, 0, 80) . (mb_strlen($description) > 80 ? '...' : '') . "\n";
        }
        if (!empty($language_code)) {
            echo "语言: {$language_code}\n";
        }
        if (!empty($publisher)) {
            echo "出版商: {$publisher}\n";
        }
        echo "\n";
        
    } catch (Exception $e) {
        echo "❌ 错误: 无法提取书籍元数据 ({$bookFile}) - " . $e->getMessage() . "\n\n";
        continue;
    }
}

if (empty($booksMetadata)) {
    die("❌ 错误: 没有成功提取任何书籍的元数据\n");
}

// 构建 System Prompt（参考 library 配置）
echo "--- 生成 System Prompt ---\n\n";

// 1. Books introduction (复数形式)
$systemPrompt = $library['books_intro'];

// 2. Book templates（为每本书生成）
foreach ($booksMetadata as $index => $bookMeta) {
    // 确定书籍序号词
    $which = '';
    if ($index === 0) {
        $which = 'first ';
    } else {
        $which = 'next ';
    }
    
    // Book template
    $bookTemplate = str_replace(
        ['{which}', '{title}', '{authors}'],
        [$which, $bookMeta['title'], $bookMeta['authors'] ?: '未知作者'],
        $library['book_template']
    );
    $systemPrompt .= $bookTemplate;
    
    // Optional: series (if available - 这里示例中没有，可以忽略)
    // $systemPrompt .= str_replace('{series}', $seriesName, $library['series_template']);
    
    // Optional: tags (if available - 这里示例中没有，可以忽略)
    // $systemPrompt .= str_replace('{tags}', $tags, $library['tags_template']);
    
    // Separator (每本书后面加一个分隔符)
    $systemPrompt .= $library['separator'];
}

// 3. Markdown instruction
$systemPrompt .= $library['markdown_instruction'];

// 4. Unknown books handling (复数形式)
$systemPrompt .= $library['unknown_multiple'];

// 5. Language instruction
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
echo "  书籍数量: {$maxBooks}\n";
echo "  字符数: {$charCount}\n";
echo "  估算 tokens: ~{$estimatedTokens}\n\n";

// 显示配置来源说明
echo "💡 配置来源:\n";
echo "  - books_intro: \"{$library['books_intro']}\"\n";
echo "  - book_template: \"{$library['book_template']}\"\n";
echo "    * 第一本书: which = 'first '\n";
echo "    * 后续书籍: which = 'next '\n";
echo "  - separator: " . json_encode($library['separator']) . "\n";
echo "  - markdown_instruction: \"{$library['markdown_instruction']}\"\n";
echo "  - unknown_multiple: \"{$library['unknown_multiple']}\"\n";
echo "  - language.instruction: \"{$language['instruction']}\"\n";
echo "  - language.default: \"{$language['default']}\"\n\n";

// 示例对话
echo "--- 示例对话 ---\n\n";
echo "User: Can you compare these books and tell me which one is more suitable for beginners?\n\n";
echo "System Prompt 会告诉 AI:\n";
echo "  1. 用户想讨论多本书\n";
foreach ($booksMetadata as $index => $bookMeta) {
    echo "  " . ($index + 2) . ". 第" . ($index + 1) . "本书：《{$bookMeta['title']}》by {$bookMeta['authors']}\n";
}
echo "  " . (count($booksMetadata) + 2) . ". 回答时使用 markdown 格式\n";
echo "  " . (count($booksMetadata) + 3) . ". 如果 AI 不认识任何一本书，应该说 'the books are unknown'\n";
echo "  " . (count($booksMetadata) + 4) . ". 如果可以，用中文回答\n\n";

// 显示与 library 配置的参考示例的对比
echo "--- 与配置中示例的对比 ---\n\n";
echo "配置文件中的参考示例 (library.examples.multiple_books):\n";
echo $library['examples']['multiple_books'] . "\n\n";

echo "✅ 测试完成!\n";
