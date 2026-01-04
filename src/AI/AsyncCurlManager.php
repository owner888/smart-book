<?php
/**
 * curl_multi 异步管理器
 */

namespace SmartBook\AI;

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
        echo "✅ AsyncCurlManager 已初始化\n";
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
        
        curl_multi_exec(self::$multiHandle, $running);
        if ($running > 0) curl_multi_select(self::$multiHandle, 0);
        
        while ($info = curl_multi_info_read(self::$multiHandle)) {
            $ch = $info['handle'];
            foreach (self::$handles as $requestId => $handle) {
                if ($handle['ch'] === $ch) {
                    $handle['onComplete']($info['result'] === CURLE_OK, curl_error($ch));
                    curl_multi_remove_handle(self::$multiHandle, $ch);
                    if (PHP_VERSION_ID < 80000 && is_resource($ch)) curl_close($ch);
                    unset(self::$handles[$requestId]);
                    break;
                }
            }
        }
    }
    
    public static function cancel(string $requestId): void
    {
        if (isset(self::$handles[$requestId])) {
            $ch = self::$handles[$requestId]['ch'];
            curl_multi_remove_handle(self::$multiHandle, $ch);
            if (PHP_VERSION_ID < 80000 && is_resource($ch)) curl_close($ch);
            unset(self::$handles[$requestId]);
        }
    }
    
    public static function getActiveCount(): int { return count(self::$handles); }
    
    public static function close(): void
    {
        if (self::$timerId !== null) { \Workerman\Timer::del(self::$timerId); self::$timerId = null; }
        foreach (self::$handles as $handle) {
            curl_multi_remove_handle(self::$multiHandle, $handle['ch']);
            if (PHP_VERSION_ID < 80000 && is_resource($handle['ch'])) curl_close($handle['ch']);
        }
        self::$handles = [];
        if (self::$multiHandle !== null) { curl_multi_close(self::$multiHandle); self::$multiHandle = null; }
    }
}
