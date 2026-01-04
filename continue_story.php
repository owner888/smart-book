<?php
/**
 * 续写《西游记》章节
 */

require_once __DIR__ . '/calibre_rag.php';

// 从 ~/.zprofile 读取 API Key
$zprofile = file_get_contents('/Users/kaka/.zprofile');
preg_match('/GEMINI_API_KEY="([^"]+)"/', $zprofile, $matches);
$apiKey = $matches[1] ?? '';

if (empty($apiKey)) {
    die("错误: 无法获取 API Key\n");
}

echo "=== 《西游记》续写章节 ===\n\n";

// 创建 Gemini 客户端
$gemini = new GeminiClient($apiKey, GeminiClient::MODEL_GEMINI_25_FLASH);

// 构建创意写作提示
$systemPrompt = <<<'EOT'
你是一位精通古典文学的作家，擅长模仿《西游记》的章回体小说风格写作。

请严格模仿《西游记》的写作风格特点：
1. 章回体格式：标题用对仗的两句话（如"第一百零一回 唐三藏误入未来城 孙行者智破机关阵"）
2. 开头常用诗词引入
3. 结尾常用"毕竟不知XXX，且听下回分解"
4. 文言白话混合的语言风格
5. 人物对话生动传神
6. 善用诗词穿插

以下是《西游记》的典型结尾风格：
"毕竟不知胜负如何，且听下回分解。"
"毕竟不知几时才得正果求经，且听下回分解。"
EOT;

$userPrompt = <<<'EOT'
请为《西游记》续写一个新章节。

设定：唐僧师徒四人在取经途中，突然穿越时空来到了一座现代化的城市。他们面对高楼大厦、汽车、手机、电视等现代科技感到惊奇和困惑。

要求：
1. 写一个完整的章回，约1500字
2. 包括：章回标题、开篇诗词、正文、结尾
3. 保持《西游记》原著的文风和人物性格
4. 孙悟空机智、唐僧慈悲、八戒贪吃好色、沙僧老实
5. 情节要有趣味性和戏剧冲突
EOT;

echo "正在生成中...\n\n";
echo str_repeat('=', 60) . "\n\n";

try {
    $gemini->chatStream(
        [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        function($text, $chunk, $isThought) {
            if (!$isThought) {
                echo $text;
            }
        },
        ['enableSearch' => false]
    );
    echo "\n\n" . str_repeat('=', 60) . "\n";
    echo "✅ 续写完成！\n";
} catch (Exception $e) {
    echo "\n❌ 错误: " . $e->getMessage() . "\n";
}
