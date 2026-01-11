<?php
/**
 * curl_multi 异步管理器
 */

namespace SmartBook\AI;

require_once dirname(__DIR__) . '/Logger.php';

class AsyncCurlManager
{
    private static $multiHandle = null;
    private static array $handles = [];
    private static ?int $timerId = null;
    
    public static function init(): void
    {
        if (self::$multiHandle !== null) return;
        
        self::$multiHandle = curl_multi_init();
        self::$timerId = \Workerman\Timer::add(0.01, fn() => self::poll());
        \Logger::info("AsyncCurlManager 已初始化");
    }
    
    public static function request(string $url, array $options, callable $onData, callable $onComplete): string
    {
        $ch = curl_init();
        $requestId = uniqid('curl_', true);
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use ($onData) { $onData($data); return strlen($data); },
        ] + $options);
        
        curl_multi_add_handle(self::$multiHandle, $ch);
        self::$handles[$requestId] = ['ch' => $ch, 'onComplete' => $onComplete];
        curl_multi_exec(self::$multiHandle, $running);
        
        return $requestId;
    }
    
    private static function poll(): void
    {
        if (self::$multiHandle === null || empty(self::$handles)) return;
        
        // 非阻塞执行 - 只处理当前可用的数据，不阻塞事件循环
        curl_multi_exec(self::$multiHandle, $running);
        
        // 收集所有已完成的请求（先收集，后处理，避免在处理过程中修改数组）
        $completedHandles = [];
        while ($info = curl_multi_info_read(self::$multiHandle)) {
            $ch = $info['handle'];
            foreach (self::$handles as $requestId => $handle) {
                if ($handle['ch'] === $ch) {
                    $completedHandles[] = [
                        'requestId' => $requestId,
                        'ch' => $ch,
                        'handle' => $handle,
                        'success' => $info['result'] === CURLE_OK,
                        'error' => curl_error($ch),
                    ];
                    break;
                }
            }
        }
        
        // 处理已完成的请求（先移除，再回调）
        foreach ($completedHandles as $completed) {
            // 先从 handles 数组和 multi handle 中移除
            curl_multi_remove_handle(self::$multiHandle, $completed['ch']);
            unset(self::$handles[$completed['requestId']]);
            
            // 调用完成回调（回调中可能会添加新请求，但不会影响当前处理）
            $completed['handle']['onComplete']($completed['success'], $completed['error']);
        }
        
        // 如果还有活跃请求，使用非阻塞的 select
        if ($running > 0) {
            curl_multi_select(self::$multiHandle, 0);
        }
    }
    
    public static function cancel(string $requestId): void
    {
        if (isset(self::$handles[$requestId])) {
            $ch = self::$handles[$requestId]['ch'];
            curl_multi_remove_handle(self::$multiHandle, $ch);
            unset(self::$handles[$requestId]);
        }
    }
    
    public static function getActiveCount(): int { return count(self::$handles); }
    
    public static function close(): void
    {
        if (self::$timerId !== null) { \Workerman\Timer::del(self::$timerId); self::$timerId = null; }
        foreach (self::$handles as $handle) {
            curl_multi_remove_handle(self::$multiHandle, $handle['ch']);
        }
        self::$handles = [];
        if (self::$multiHandle !== null) { curl_multi_close(self::$multiHandle); self::$multiHandle = null; }
    }
}
