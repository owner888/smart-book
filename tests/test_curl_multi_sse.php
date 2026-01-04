<?php
/**
 * curl_multi 流式 SSE 性能测试
 * 
 * 测试内容：
 * 1. 单请求流式响应
 * 2. 并发请求压力测试
 * 3. 首字节延迟 (TTFB)
 * 4. 吞吐量测试
 * 
 * 使用：php tests/test_curl_multi_sse.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

echo "=========================================\n";
echo "   curl_multi SSE 性能测试\n";
echo "=========================================\n\n";

// ===================================
// 测试 1: 单请求 SSE 流式响应
// ===================================

echo "📊 测试 1: 单请求 SSE 流式响应\n";
echo str_repeat('-', 40) . "\n";

$singleResults = testSingleSSERequest();
printResult('单请求延迟', $singleResults);

// ===================================
// 测试 2: 并发请求压力测试
// ===================================

echo "\n📊 测试 2: 并发请求压力测试\n";
echo str_repeat('-', 40) . "\n";

$concurrencyLevels = [1, 5, 10, 20];
foreach ($concurrencyLevels as $concurrent) {
    $results = testConcurrentSSE($concurrent);
    printResult("{$concurrent} 并发请求", $results);
}

// ===================================
// 测试 3: 本地 curl_multi 模拟
// ===================================

echo "\n📊 测试 3: curl_multi 本地模拟测试\n";
echo str_repeat('-', 40) . "\n";

$localResults = testLocalCurlMulti();
printResult('本地 curl_multi', $localResults);

// ===================================
// 测试函数
// ===================================

function testSingleSSERequest(): array
{
    $url = 'http://localhost:8088/api/stream/ask';
    $data = json_encode(['question' => '测试问题', 'top_k' => 3]);
    
    $startTime = microtime(true);
    $firstChunkTime = null;
    $chunkCount = 0;
    $totalBytes = 0;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$firstChunkTime, &$chunkCount, &$totalBytes, $startTime) {
            if ($firstChunkTime === null) {
                $firstChunkTime = microtime(true) - $startTime;
            }
            $chunkCount++;
            $totalBytes += strlen($chunk);
            return strlen($chunk);
        },
    ]);
    
    curl_exec($ch);
    $totalTime = microtime(true) - $startTime;
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'http_code' => $httpCode,
        'total_time' => round($totalTime * 1000, 2),
        'ttfb' => $firstChunkTime ? round($firstChunkTime * 1000, 2) : 'N/A',
        'chunks' => $chunkCount,
        'bytes' => $totalBytes,
    ];
}

function testConcurrentSSE(int $concurrent): array
{
    $url = 'http://localhost:8088/api/stream/ask';
    
    $mh = curl_multi_init();
    $handles = [];
    $startTimes = [];
    $firstChunkTimes = [];
    $results = [];
    
    // 创建并发请求
    for ($i = 0; $i < $concurrent; $i++) {
        $ch = curl_init();
        $requestId = "req_{$i}";
        
        $data = json_encode(['question' => "测试问题 #{$i}", 'top_k' => 3]);
        
        $results[$requestId] = [
            'chunks' => 0,
            'bytes' => 0,
            'ttfb' => null,
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_WRITEFUNCTION => function($ch, $chunk) use ($requestId, &$results, &$firstChunkTimes, &$startTimes) {
                if (!isset($firstChunkTimes[$requestId])) {
                    $firstChunkTimes[$requestId] = microtime(true) - $startTimes[$requestId];
                }
                $results[$requestId]['chunks']++;
                $results[$requestId]['bytes'] += strlen($chunk);
                return strlen($chunk);
            },
        ]);
        
        $startTimes[$requestId] = microtime(true);
        $handles[$requestId] = $ch;
        curl_multi_add_handle($mh, $ch);
    }
    
    $overallStart = microtime(true);
    
    // 执行所有请求
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);
    
    $totalTime = microtime(true) - $overallStart;
    
    // 收集结果
    $successCount = 0;
    $totalChunks = 0;
    $totalBytes = 0;
    $ttfbs = [];
    
    foreach ($handles as $requestId => $ch) {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode === 200) $successCount++;
        
        $totalChunks += $results[$requestId]['chunks'];
        $totalBytes += $results[$requestId]['bytes'];
        
        if (isset($firstChunkTimes[$requestId])) {
            $ttfbs[] = $firstChunkTimes[$requestId] * 1000;
        }
        
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    
    curl_multi_close($mh);
    
    return [
        'concurrent' => $concurrent,
        'success' => $successCount,
        'total_time' => round($totalTime * 1000, 2),
        'avg_ttfb' => $ttfbs ? round(array_sum($ttfbs) / count($ttfbs), 2) : 'N/A',
        'min_ttfb' => $ttfbs ? round(min($ttfbs), 2) : 'N/A',
        'max_ttfb' => $ttfbs ? round(max($ttfbs), 2) : 'N/A',
        'total_chunks' => $totalChunks,
        'total_bytes' => $totalBytes,
        'requests_per_sec' => round($concurrent / $totalTime, 2),
    ];
}

function testLocalCurlMulti(): array
{
    // 模拟本地 curl_multi 行为（不发送真实请求）
    $handles = [];
    $maxConcurrent = 100;
    
    $startTime = microtime(true);
    
    // 测试 curl_multi_init 和 handle 管理开销
    $mh = curl_multi_init();
    
    for ($i = 0; $i < $maxConcurrent; $i++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'http://httpbin.org/delay/0',  // 快速响应的测试 URL
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $handles[] = $ch;
        curl_multi_add_handle($mh, $ch);
    }
    
    $initTime = microtime(true) - $startTime;
    
    // 清理
    foreach ($handles as $ch) {
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    
    $cleanupTime = microtime(true) - $startTime - $initTime;
    
    return [
        'handles_created' => $maxConcurrent,
        'init_time_ms' => round($initTime * 1000, 2),
        'cleanup_time_ms' => round($cleanupTime * 1000, 2),
        'total_time_ms' => round(($initTime + $cleanupTime) * 1000, 2),
        'handles_per_sec' => round($maxConcurrent / ($initTime + $cleanupTime)),
    ];
}

function printResult(string $label, array $data): void
{
    echo "\n🔹 {$label}:\n";
    foreach ($data as $key => $value) {
        $key = str_replace('_', ' ', $key);
        $unit = match(true) {
            str_contains($key, 'time') || str_contains($key, 'ttfb') => 'ms',
            str_contains($key, 'bytes') => ' bytes',
            str_contains($key, 'per sec') => '/s',
            default => ''
        };
        echo "   • {$key}: {$value}{$unit}\n";
    }
}

// ===================================
// 最终报告
// ===================================

echo "\n" . str_repeat('=', 40) . "\n";
echo "📋 测试完成\n";
echo str_repeat('=', 40) . "\n";

echo "\n💡 性能建议:\n";
echo "   • TTFB (首字节时间) < 500ms 为良好\n";
echo "   • 并发支持 > 20 请求为良好\n";
echo "   • curl_multi handle 创建应 < 10ms/100个\n";
