<?php
/**
 * 测试 Calibre AI 实现
 * 
 * 用法: php test_ai.php [API_KEY]
 * 或设置环境变量: export GEMINI_API_KEY="your-key" && php test_ai.php
 */

require_once __DIR__ . '/../src/AI/GeminiClient.php';

use SmartBook\AI\GeminiClient;

// 加载配置
$prompts = require __DIR__ . '/../config/prompts.php';

// 从命令行参数或环境变量获取 API Key
$apiKey = $argv[1] ?? getenv('GEMINI_API_KEY') ?: '';

if (empty($apiKey)) {
    echo "用法: php test_ai.php <GEMINI_API_KEY>\n";
    echo "或设置环境变量: export GEMINI_API_KEY=\"your-key\" && php test_ai.php\n";
    exit(1);
}

echo "=== Calibre AI 测试 ===\n\n";

// 创建 Gemini 客户端
$gemini = new GeminiClient(
    apiKey: $apiKey,
    model: GeminiClient::MODEL_GEMINI_25_FLASH
);

// 测试书籍
$book = [
    'title' => '三体',
    'authors' => '刘慈欣',
    'series' => '地球往事三部曲',
    'tags' => ['科幻', '硬科幻', '外星文明'],
];

echo "📚 测试书籍: {$book['title']} by {$book['authors']}\n\n";

// 使用配置生成系统提示词
$libraryPrompts = $prompts['library'];

// 格式化书籍信息
$bookInfo = $libraryPrompts['book_intro'];
$bookInfo .= str_replace(
    ['{which}', '{title}', '{authors}'],
    ['', $book['title'], $book['authors']],
    $libraryPrompts['book_template']
);
if (!empty($book['series'])) {
    $bookInfo .= str_replace('{series}', $book['series'], $libraryPrompts['series_template']);
}
if (!empty($book['tags'])) {
    $tags = is_array($book['tags']) ? implode(', ', $book['tags']) : $book['tags'];
    $bookInfo .= str_replace('{tags}', $tags, $libraryPrompts['tags_template']);
}
$bookInfo .= $libraryPrompts['separator'];

// 组装完整的系统提示词
$systemPrompt = $bookInfo;
$systemPrompt .= $libraryPrompts['markdown_instruction'];
$systemPrompt .= $libraryPrompts['unknown_single'];
$systemPrompt .= ' ' . str_replace('{language}', $prompts['language']['default'], $prompts['language']['instruction']);

// 获取操作提示
$action = $libraryPrompts['actions']['summarize'];
$actionPrompt = str_replace(
    ['{books_word}', '{is_are}'],
    ['book', 'is'],
    $action['prompt']
);

echo "--- 系统提示词 ---\n{$systemPrompt}\n\n";
echo "--- 用户提示词 ---\n{$actionPrompt}\n\n";

// 调用 API
echo "🤖 正在调用 Gemini API...\n\n";
echo "--- AI 回复 ---\n";

try {
    // 流式输出
    $result = $gemini->chatStream(
        [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $actionPrompt],
        ],
        function($text, $chunk, $isThought) {
            if ($isThought) {
                // 可选: 显示思考过程
                // echo "[思考] {$text}";
            } else {
                echo $text;
            }
        },
        ['enableSearch' => false]  // 禁用搜索以加快响应
    );
    
    echo "\n\n";
    echo "✅ 调用成功!\n";
    
    if (!empty($result['reasoning'])) {
        echo "\n--- AI 思考过程 ---\n";
        echo substr($result['reasoning'], 0, 500);
        if (strlen($result['reasoning']) > 500) {
            echo "...(已截断)";
        }
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "\n\n❌ 错误: " . $e->getMessage() . "\n";
}
