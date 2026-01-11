<?php

require_once __DIR__ . '/../LoggerEngineInterface.php';

/**
 * File Logger Engine
 * 
 * 将日志写入文件，支持按日期/大小自动切割
 */
class FileEngine implements LoggerEngineInterface
{
    private string $logPath;
    private string $logFile;
    private int $maxFileSize = 10 * 1024 * 1024;  // 10MB
    private int $maxFiles = 5;  // 5个历史保留最多文件
    private string $dateFormat = 'Y-m-d H:i:s';
    private string $levelFormat = '[%s]';  // 日志级别格式
    private bool $enabled = false;
    private bool $useLocking = true;  // 文件锁
    private array $levels = [
        'INFO' => 'INFO',
        'WARN' => 'WARN',
        'WARNING' => 'WARN',
        'DEBUG' => 'DEBUG',
        'ERROR' => 'ERROR',
    ];

    /**
     * 构造函数
     * 
     * @param string $logPath 日志目录路径
     * @param string $logFile 日志文件名（不含扩展名）
     */
    public function __construct(string $logPath = '', string $logFile = 'app')
    {
        $this->logPath = $logPath ?: getenv('LOG_PATH') ?: dirname(__DIR__, 2) . '/logs';
        $this->logFile = $logFile ?: getenv('LOG_FILE') ?: 'app';
        
        $this->ensureLogDir();
        $this->enabled = is_dir($this->logPath) && is_writable($this->logPath);
    }

    /**
     * 发送日志到文件
     */
    public function send(string $level, string $message, array $context = []): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $formatted = $this->formatMessage($level, $message, $context);
        
        return $this->write($formatted);
    }

    /**
     * 写入日志文件
     */
    public function write(string $content): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $filePath = $this->getLogFilePath();
        
        // 检查是否需要切割文件
        $this->checkRotation($filePath);
        
        // 写入日志
        $flags = $this->useLocking ? LOCK_EX : 0;
        $result = file_put_contents($filePath, $content . PHP_EOL, $flags);
        
        return $result !== false;
    }

    /**
     * 批量写入日志
     */
    public function writeBatch(array $lines): bool
    {
        if (!$this->isAvailable() || empty($lines)) {
            return false;
        }

        $content = implode(PHP_EOL, $lines) . PHP_EOL;
        return $this->write($content);
    }

    /**
     * 获取引擎名称
     */
    public function getName(): string
    {
        return 'File';
    }

    /**
     * 检查引擎是否可用
     */
    public function isAvailable(): bool
    {
        return $this->enabled;
    }

    /**
     * 启用/禁用引擎
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * 设置日志级别格式
     */
    public function setLevelFormat(string $format): void
    {
        $this->levelFormat = $format;
    }

    /**
     * 设置日期格式
     */
    public function setDateFormat(string $format): void
    {
        $this->dateFormat = $format;
    }

    /**
     * 设置文件大小限制
     */
    public function setMaxFileSize(int $bytes): void
    {
        $this->maxFileSize = $bytes;
    }

    /**
     * 设置保留文件数
     */
    public function setMaxFiles(int $count): void
    {
        $this->maxFiles = $count;
    }

    /**
     * 启用/禁用文件锁
     */
    public function setUseLocking(bool $use): void
    {
        $this->useLocking = $use;
    }

    /**
     * 获取当前配置
     */
    public function getConfig(): array
    {
        return [
            'log_path' => $this->logPath,
            'log_file' => $this->logFile,
            'max_file_size' => $this->maxFileSize,
            'max_files' => $this->maxFiles,
            'date_format' => $this->dateFormat,
            'enabled' => $this->enabled,
        ];
    }

    /**
     * 获取日志文件路径
     */
    public function getLogFilePath(): string
    {
        return $this->logPath . '/' . $this->logFile . '.log';
    }

    /**
     * 获取日志目录
     */
    public function getLogPath(): string
    {
        return $this->logPath;
    }

    /**
     * 读取日志文件
     */
    public function read(int $lines = 100): array
    {
        $filePath = $this->getLogFilePath();
        
        if (!file_exists($filePath)) {
            return [];
        }

        $result = [];
        $handle = fopen($filePath, 'r');
        
        if ($handle) {
            // 从末尾开始读取
            $count = 0;
            while ($count < $lines && fseek($handle, -1, SEEK_END) !== -1) {
                $pos = ftell($handle);
                if ($pos <= 0) {
                    break;
                }
                
                $char = fgetc($handle);
                if ($char === PHP_EOL) {
                    $count++;
                }
            }
            
            rewind($handle);
            while (($line = fgets($handle)) !== false) {
                $result[] = trim($line);
            }
            fclose($handle);
        }
        
        return array_slice($result, -$lines);
    }

    /**
     * 搜索日志
     */
    public function search(string $keyword, int $limit = 100): array
    {
        $filePath = $this->getLogFilePath();
        
        if (!file_exists($filePath)) {
            return [];
        }

        $result = [];
        $handle = fopen($filePath, 'r');
        
        if ($handle) {
            while (($line = fgets($handle)) !== false && count($result) < $limit) {
                if (stripos($line, $keyword) !== false) {
                    $result[] = trim($line);
                }
            }
            fclose($handle);
        }
        
        return $result;
    }

    /**
     * 获取日志文件大小
     */
    public function getFileSize(): int
    {
        $filePath = $this->getLogFilePath();
        return file_exists($filePath) ? filesize($filePath) : 0;
    }

    /**
     * 手动切割日志
     */
    public function rotate(): bool
    {
        $filePath = $this->getLogFilePath();
        
        if (!file_exists($filePath)) {
            return false;
        }

        return $this->doRotation($filePath);
    }

    /**
     * 清空日志文件
     */
    public function clear(): bool
    {
        $filePath = $this->getLogFilePath();
        
        if (!file_exists($filePath)) {
            return true;
        }

        return file_put_contents($filePath, '') !== false;
    }

    /**
     * 删除旧的历史日志文件
     */
    public function cleanup(): int
    {
        $pattern = $this->logPath . '/' . $this->logFile . '_*.log.*';
        $files = glob($pattern);
        
        if (empty($files)) {
            return 0;
        }

        // 按修改时间排序
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $deleted = 0;
        foreach (array_slice($files, $this->maxFiles) as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }
        
        return $deleted;
    }

    /**
     * 格式化日志消息
     */
    private function formatMessage(string $level, string $message, array $context = []): string
    {
        $timestamp = date($this->dateFormat);
        $levelStr = sprintf($this->levelFormat, strtoupper($level));
        
        $formatted = "[{$timestamp}] {$levelStr} - {$message}";
        
        // 添加上下文信息
        if (!empty($context)) {
            $contextStr = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $formatted .= PHP_EOL . $contextStr;
        }
        
        return $formatted;
    }

    /**
     * 确保日志目录存在
     */
    private function ensureLogDir(): void
    {
        if (!is_dir($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }
    }

    /**
     * 检查是否需要切割文件
     */
    private function checkRotation(string $filePath): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        if (filesize($filePath) >= $this->maxFileSize) {
            $this->doRotation($filePath);
        }
    }

    /**
     * 执行文件切割
     */
    private function doRotation(string $filePath): bool
    {
        $baseName = basename($filePath, '.log');
        $timestamp = date('Ymd_His');
        $newFile = $this->logPath . '/' . $baseName . '_' . $timestamp . '.log';
        
        // 重命名当前文件
        if (!rename($filePath, $newFile)) {
            return false;
        }
        
        // 清理旧文件
        $this->cleanup();
        
        return true;
    }
}
