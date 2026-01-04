<?php
/**
 * 读取 EPUB 并发送给 AI
 */

require_once __DIR__ . '/calibre_ai_prompts.php';

// 从 ~/.zprofile 读取 API Key
$zprofile = file_get_contents('/Users/kaka/.zprofile');
preg_match('/GEMINI_API_KEY="([^"]+)"/', $zprofile, $matches);
$apiKey = $matches[1] ?? '';

if (empty($apiKey)) {
    die("错误: 无法从 ~/.zprofile 读取 GEMINI_API_KEY\n");
}

/**
 * 从 EPUB 文件中提取文本内容
 */
function extractEpubText(string $epubPath, int $maxLength = 50000): string
{
    $zip = new ZipArchive();
    if ($zip->open($epubPath) !== true) {
        throw new Exception("无法打开 EPUB 文件: {$epubPath}");
    }
    
    $text = '';
    
    // 遍历 zip 中的所有文件
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);
        
        // 只处理 HTML/XHTML 文件
        if (preg_match('/\.(html?|xhtml)$/i', $filename)) {
            $content = $zip->getFromIndex($i);
            if ($content) {
                // 移除 HTML 标签，保留文本
                $content = strip_tags($content);
                // 清理多余空白
                $content = preg_replace('/\s+/', ' ', $content);
                $content = trim($content);
                
                if (!empty($content)) {
                    $text .= $content . "\n\n";
                }
            }
        }
    }
    
    $zip->close();
    
    // 截断到最大长度
    if (mb_strlen($text) > $maxLength) {
        $text = mb_substr($text, 0, $maxLength) . "\n\n[... 内容已截断，共 " . mb_strlen($text) . " 字符 ...]";
    }
    
    return $text;
}

// EPUB 文件路径
$epubPath = '/Users/kaka/Documents/西游记.epub';

echo "=== EPUB 阅读器 AI 测试 ===\n\n";
echo "📖 正在读取: {$epubPath}\n\n";

try {
    // 提取 EPUB 文本
    $bookContent = extractEpubText($epubPath, 30000);  // 限制30000字符
    
    echo "📊 提取到 " . mb_strlen($bookContent) . " 字符\n\n";
    
    // 显示前500字符预览
    echo "--- 内容预览 ---\n";
    echo mb_substr($bookContent, 0, 500) . "...\n\n";
    
    // 创建 Gemini 客户端
    $gemini = new GeminiClient(
        apiKey: $apiKey,
        model: GeminiClient::MODEL_GEMINI_25_FLASH
    );
    
    // 构建提示词
    $systemPrompt = <<<EOT
我正在阅读一本名为《西游记》的书。以下是这本书的部分内容。请根据书中内容回答我的问题。
使用中文回答，使用 markdown 格式。

--- 书籍内容 ---
{$bookContent}
--- 内容结束 ---
EOT;

    $userQuestion = "帮我介绍一下书中的主要人物，包括他们的特点和在故事中的角色。";
    
    echo "❓ 问题: {$userQuestion}\n\n";
    echo "🤖 正在调用 Gemini API...\n\n";
    echo "--- AI 回复 ---\n";
    
    // 流式调用
    $result = $gemini->chatStream(
        [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userQuestion],
        ],
        function($text, $chunk, $isThought) {
            if (!$isThought) {
                echo $text;
            }
        },
        ['enableSearch' => false]
    );
    
    echo "\n\n✅ 完成!\n";
    
} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
}
