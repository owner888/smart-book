<?php
/**
 * 测试 Google TTS - 生成 MP3 文件
 */

require_once __DIR__ . '/bootstrap.php';

use SmartBook\AI\GoogleTTSClient;

$text = "你好，今天天气真不错。这是一个测试音频。";

try {
    $tts = new GoogleTTSClient();
    
    echo "正在合成音频...\n";
    $result = $tts->synthesize($text);
    
    // 保存到 static 目录
    $audioData = base64_decode($result['audio']);
    $filename = 'test_tts_' . time() . '.mp3';
    $filepath = __DIR__ . '/static/' . $filename;
    
    file_put_contents($filepath, $audioData);
    
    $url = "http://" . WEB_SERVER_HOST . ":" . WEB_SERVER_PORT . "/{$filename}";
    
    echo "✅ 音频合成成功！\n";
    echo "文件大小: " . strlen($audioData) . " 字节\n";
    echo "文件路径: {$filepath}\n";
    echo "访问 URL: {$url}\n";
    echo "\n";
    echo "在 iOS 中测试播放这个 URL！\n";
    
} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
}
