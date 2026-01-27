<?php

namespace SmartBook;

/**
 * Logger - 彩色日志工具类
 * 
 * 支持 info、warn、debug、error 四个日志级别
 * 不同级别使用不同颜色输出
 * 支持扩展引擎（如 Telegram）
 */
class Logger
{
    // 颜色代码
    const COLOR_INFO = '32';    // 绿色
    const COLOR_WARN = '33';    // 黄色
    const COLOR_DEBUG = '36';   // 青色
    const COLOR_ERROR = '31';   // 红色

    // 日志级别名称
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARN = 'WARN';
    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_ERROR = 'ERROR';

    // 是否启用日志
    private static bool $enabled = true;

    // 是否显示时间戳
    private static bool $showTimestamp = true;

    // 是否是 CLI 模式
    private static ?bool $isCli = null;

    // 注册的引擎
    private static array $engines = [];

    /**
     * 初始化配置
     */
    public static function init(bool $enabled = true, bool $showTimestamp = true): void
    {
        self::$enabled = $enabled;
        self::$showTimestamp = $showTimestamp;
        self::$isCli = php_sapi_name() === 'cli';
    }

    /**
     * 启用/禁用日志
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /**
     * 设置是否显示时间戳
     */
    public static function setShowTimestamp(bool $show): void
    {
        self::$showTimestamp = $show;
    }

    /**
     * 注册日志引擎
     * 
     * @param LoggerEngineInterface $engine 引擎实例
     * @param string|null $alias 别名
     */
    public static function registerEngine(LoggerEngineInterface $engine, ?string $alias = null): void
    {
        $name = $alias ?? $engine->getName();
        self::$engines[$name] = $engine;
    }

    /**
     * 移除日志引擎
     */
    public static function removeEngine(string $name): void
    {
        unset(self::$engines[$name]);
    }

    /**
     * 获取所有已注册的引擎
     */
    public static function getEngines(): array
    {
        return self::$engines;
    }

    /**
     * 通过引擎发送日志
     */
    private static function sendToEngines(string $level, string $message, array $context = []): void
    {
        foreach (self::$engines as $engine) {
            if ($engine instanceof LoggerEngineInterface && $engine->isAvailable()) {
                try {
                    $engine->send($level, $message, $context);
                } catch (\Exception $e) {
                    // 静默忽略引擎错误
                    error_log("Logger Engine Error: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * 获取带颜色的格式化字符串
     */
    private static function format(string $level, string $message, string $color): string
    {
        $timestamp = self::$showTimestamp ? '[' . date('Y-m-d H:i:s') . '] ' : '';
        $prefix = self::getLevelPrefix($level);
        
        if (self::$isCli) {
            $coloredPrefix = "\033[{$color}m{$prefix}\033[0m";
            return $timestamp . $coloredPrefix . ' ' . $message;
        }
        
        // 非 CLI 模式返回纯文本
        return $timestamp . $prefix . ' ' . $message;
    }

    /**
     * 获取级别前缀
     */
    private static function getLevelPrefix(string $level): string
    {
        return str_pad($level, 5, ' ', STR_PAD_LEFT);
    }

    /**
     * 输出日志
     */
    private static function log(string $level, string $message, string $color, array $context = []): void
    {
        if (!self::$enabled) {
            return;
        }

        $formatted = self::format($level, $message, $color);
        
        // CLI 模式输出到控制台
        if (self::$isCli) {
            echo $formatted . PHP_EOL;
        } else {
            // Web 模式记录到错误日志
            error_log(strip_tags($formatted));
        }

        // 发送到注册的引擎
        self::sendToEngines($level, $message, $context);
    }

    /**
     * INFO 级别 - 绿色
     */
    public static function info(string $message, array $context = []): void
    {
        self::log(self::LEVEL_INFO, $message, self::COLOR_INFO, $context);
    }

    /**
     * WARN 级别 - 黄色
     */
    public static function warn(string $message, array $context = []): void
    {
        self::log(self::LEVEL_WARN, $message, self::COLOR_WARN, $context);
    }

    /**
     * DEBUG 级别 - 青色
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log(self::LEVEL_DEBUG, $message, self::COLOR_DEBUG, $context);
    }

    /**
     * ERROR 级别 - 红色
     */
    public static function error(string $message, array $context = []): void
    {
        self::log(self::LEVEL_ERROR, $message, self::COLOR_ERROR, $context);
    }

    /**
     * 记录数组/对象（调试用）
     */
    public static function dump(string $label, $data): void
    {
        if (!self::$enabled) {
            return;
        }

        $output = print_r($data, true);
        self::debug("{$label}: {$output}");
    }

    /**
     * 性能计时开始
     */
    public static function time(string $label): void
    {
        if (!self::$enabled) {
            return;
        }

        $_SERVER['LOGGER_TIME'][$label] = microtime(true);
        self::debug("Timer '{$label}' started");
    }

    /**
     * 性能计时结束并输出
     */
    public static function timeEnd(string $label): float
    {
        if (!self::$enabled || !isset($_SERVER['LOGGER_TIME'][$label])) {
            return 0;
        }

        $start = $_SERVER['LOGGER_TIME'][$label];
        $end = microtime(true);
        $duration = round(($end - $start) * 1000, 2);
        
        unset($_SERVER['LOGGER_TIME'][$label]);
        self::info("Timer '{$label}': {$duration}ms");
        
        return $duration;
    }

    /**
     * 分割线
     */
    public static function separator(string $char = '-', int $length = 50): void
    {
        if (!self::$enabled) {
            return;
        }

        $line = str_repeat($char, $length);
        self::info($line);
    }

    /**
     * 标题样式
     */
    public static function title(string $title): void
    {
        if (!self::$enabled) {
            return;
        }

        $border = str_repeat('=', 50);
        self::info('');
        self::info($border);
        self::info('  ' . $title);
        self::info($border);
        self::info('');
    }
}

// 自动初始化
Logger::init();
