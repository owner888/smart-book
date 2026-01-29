<?php
/**
 * Deepgram ASR 测试文件
 * 
 * 测试 Deepgram 语音识别功能
 * 使用方法：php tests/test_deepgram_asr.php
 */

require_once __DIR__ . '/../bootstrap.php';

use SmartBook\AI\DeepgramASRClient;
use SmartBook\Logger;

// ===================================
// 测试配置
// ===================================

// 从环境变量读取 API Key
$apiKey = $_ENV['DEEPGRAM_API_KEY'] ?? null;

if (empty($apiKey)) {
    echo "❌ 错误：未配置 DEEPGRAM_API_KEY\n";
    echo "请在 .env 文件中设置：DEEPGRAM_API_KEY=your_api_key_here\n";
    exit(1);
}

echo "=".str_repeat("=", 60)."=\n";
echo "  Deepgram ASR 测试\n";
echo "=".str_repeat("=", 60)."=\n\n";

// ===================================
// 测试 1: 检查客户端初始化
// ===================================
echo "📋 测试 1: 初始化 Deepgram 客户端...\n";
try {
    $client = new DeepgramASRClient($apiKey);
    echo "✅ 客户端初始化成功\n\n";
} catch (Exception $e) {
    echo "❌ 客户端初始化失败: " . $e->getMessage() . "\n";
    exit(1);
}

// ===================================
// 测试 2: 获取支持的语言
// ===================================
echo "📋 测试 2: 获取支持的语言列表...\n";
try {
    $languages = $client->getLanguages();
    echo "✅ 支持的语言数量: " . count($languages) . "\n";
    echo "   示例语言:\n";
    $count = 0;
    foreach ($languages as $code => $name) {
        echo "   - $code: $name\n";
        if (++$count >= 5) break;
    }
    echo "   默认语言: " . DeepgramASRClient::getDefaultLanguage() . "\n\n";
} catch (Exception $e) {
    echo "❌ 获取语言列表失败: " . $e->getMessage() . "\n\n";
}

// ===================================
// 测试 3: 获取支持的模型
// ===================================
echo "📋 测试 3: 获取支持的模型列表...\n";
try {
    $models = $client->getModels();
    echo "✅ 支持的模型:\n";
    foreach ($models as $code => $name) {
        echo "   - $code: $name\n";
    }
    echo "   默认模型: " . DeepgramASRClient::getDefaultModel() . "\n\n";
} catch (Exception $e) {
    echo "❌ 获取模型列表失败: " . $e->getMessage() . "\n\n";
}

// ===================================
// 测试 4: 语言检测
// ===================================
echo "📋 测试 4: 测试语言检测功能...\n";
$testTexts = [
    '你好，世界！' => 'zh-CN',
    'Hello, world!' => 'en-US',
    'こんにちは' => 'ja',
    '안녕하세요' => 'ko',
    'สวัสดี' => 'th',
];

foreach ($testTexts as $text => $expected) {
    $detected = DeepgramASRClient::detectLanguage($text);
    $status = $detected === $expected ? '✅' : '⚠️';
    echo "   $status '$text' => $detected (期望: $expected)\n";
}
echo "\n";

// ===================================
// 测试 5: 模拟音频识别（需要真实音频文件）
// ===================================
echo "📋 测试 5: 音频识别测试...\n";

// 检查是否有测试音频文件
$testAudioFiles = [
    __DIR__ . '/audio/test.wav',
    __DIR__ . '/audio/test.mp3',
    __DIR__ . '/audio/test.webm',
];

$testAudioFile = null;
foreach ($testAudioFiles as $file) {
    if (file_exists($file)) {
        $testAudioFile = $file;
        break;
    }
}

if ($testAudioFile && file_exists($testAudioFile)) {
    echo "   找到测试音频文件: $testAudioFile\n";
    
    try {
        // 读取音频文件
        $audioData = file_get_contents($testAudioFile);
        $audioBase64 = base64_encode($audioData);
        
        // 确定编码格式
        $extension = pathinfo($testAudioFile, PATHINFO_EXTENSION);
        $encodingMap = [
            'wav' => 'LINEAR16',
            'mp3' => 'MP3',
            'webm' => 'WEBM_OPUS',
            'flac' => 'FLAC',
            'ogg' => 'OGG_OPUS',
        ];
        $encoding = $encodingMap[strtolower($extension)] ?? 'LINEAR16';
        
        echo "   音频编码: $encoding\n";
        echo "   音频大小: " . number_format(strlen($audioData)) . " bytes\n";
        echo "   正在识别...\n";
        
        // 执行识别
        $result = $client->recognize(
            $audioBase64,
            $encoding,
            48000,  // 采样率
            'zh-CN', // 语言
            'nova-2' // 模型
        );
        
        echo "\n   ✅ 识别结果:\n";
        echo "   - 文本: {$result['transcript']}\n";
        echo "   - 置信度: {$result['confidence']}%\n";
        echo "   - 语言: {$result['language']}\n";
        echo "   - 时长: {$result['duration']} 秒\n";
        echo "   - 费用: {$result['costFormatted']}\n";
        echo "   - 提供商: {$result['provider']}\n";
        
        if (!empty($result['words'])) {
            echo "   - 单词数: " . count($result['words']) . "\n";
        }
        
        if (!empty($result['request_id'])) {
            echo "   - 请求ID: {$result['request_id']}\n";
        }
        
    } catch (Exception $e) {
        echo "   ❌ 识别失败: " . $e->getMessage() . "\n";
    }
} else {
    echo "   ℹ️  未找到测试音频文件，跳过实际识别测试\n";
    echo "   提示：可以在以下位置放置测试音频文件：\n";
    foreach ($testAudioFiles as $file) {
        echo "   - $file\n";
    }
    echo "\n   或者创建目录并放置音频文件：\n";
    echo "   mkdir -p " . dirname($testAudioFiles[0]) . "\n";
}

echo "\n";

// ===================================
// 测试 6: API 端点测试（可选）
// ===================================
echo "📋 测试 6: 测试 HTTP API 端点...\n";

$serverHost = $_ENV['WEB_SERVER_HOST'] ?? 'localhost';
$serverPort = $_ENV['WEB_SERVER_PORT'] ?? 8081;
$baseUrl = "http://{$serverHost}:{$serverPort}";

// 检查服务器是否运行
$ch = curl_init("{$baseUrl}/api/health");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 2);
$response = @curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode === 200) {
    echo "   ✅ 服务器正在运行\n";
    
    // 测试 ASR 配置端点
    echo "   测试 GET /api/asr/config...\n";
    $ch = curl_init("{$baseUrl}/api/asr/config");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        echo "   ✅ ASR 配置:\n";
        echo "      - 提供商: " . ($data['data']['provider'] ?? 'N/A') . "\n";
        echo "      - 默认语言: " . ($data['data']['default_language'] ?? 'N/A') . "\n";
        if (isset($data['data']['default_model'])) {
            echo "      - 默认模型: {$data['data']['default_model']}\n";
        }
        echo "      - 支持语言数: " . count($data['data']['languages'] ?? []) . "\n";
    } else {
        echo "   ⚠️  无法获取 ASR 配置 (HTTP {$httpCode})\n";
    }
    
    // 测试语言列表端点
    echo "   测试 GET /api/asr/languages...\n";
    $ch = curl_init("{$baseUrl}/api/asr/languages");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        echo "   ✅ 获取语言列表成功\n";
        echo "      - 提供商: " . ($data['data']['provider'] ?? 'N/A') . "\n";
        echo "      - 语言数量: " . count($data['data']['languages'] ?? []) . "\n";
    } else {
        echo "   ⚠️  无法获取语言列表 (HTTP {$httpCode})\n";
    }
    
} else {
    echo "   ℹ️  服务器未运行，跳过 API 测试\n";
    echo "   提示：运行 'php server.php start' 启动服务器\n";
}

echo "\n";

// ===================================
// 测试总结
// ===================================
echo "=".str_repeat("=", 60)."=\n";
echo "  测试完成\n";
echo "=".str_repeat("=", 60)."=\n\n";

echo "📝 使用说明:\n";
echo "1. 在 .env 文件中配置 DEEPGRAM_API_KEY\n";
echo "2. 设置 ASR_PROVIDER=deepgram\n";
echo "3. （可选）设置 ASR_MODEL=nova-2\n";
echo "4. 重启服务器: php server.php restart\n";
echo "5. 使用 POST /api/asr/recognize 进行语音识别\n\n";

echo "🔗 相关链接:\n";
echo "- Deepgram 官网: https://deepgram.com\n";
echo "- Deepgram 文档: https://developers.deepgram.com\n";
echo "- 获取 API Key: https://console.deepgram.com\n\n";

echo "💡 提示:\n";
echo "- Nova-2 是最新最准确的模型\n";
echo "- 支持 30+ 种语言\n";
echo "- 实时识别和预录音频识别\n";
echo "- 费用: $0.0043/分钟 (Nova-2)\n\n";
