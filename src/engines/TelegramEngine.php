<?php

require_once __DIR__ . '/../LoggerEngineInterface.php';
require_once __DIR__ . '/../Requests.php';

/**
 * Telegram Logger Engine
 * 
 * 通过 Telegram Bot 发送日志消息
 */
class TelegramEngine implements LoggerEngineInterface
{
    private string $botToken;
    private string $chatId;
    private string $apiUrl;
    private bool $enabled = false;
    private Requests $http;

    /**
     * 构造函数
     * 
     * @param string $botToken Telegram Bot Token
     * @param string $chatId Telegram Chat ID (可以是用户ID或频道/群组ID)
     */
    public function __construct(string $botToken = '', string $chatId = '')
    {
        $this->botToken = $botToken ?: getenv('TELEGRAM_BOT_TOKEN') ?: '';
        $this->chatId = $chatId ?: getenv('TELEGRAM_CHAT_ID') ?: '';
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
        $this->http = new Requests(['timeout' => 10, 'connect_timeout' => 5]);
        
        $this->enabled = !empty($this->botToken) && !empty($this->chatId);
    }

    /**
     * 发送日志到 Telegram
     */
    public function send(string $level, string $message, array $context = []): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $coloredLevel = $this->getLevelEmoji($level) . ' ' . strtoupper($level);
        
        $text = "<b>{$coloredLevel}</b>\n" .
                "<code>" . date('Y-m-d H:i:s') . "</code>\n\n" .
                $this->escapeHtml($message);

        // 如果有上下文，添加额外信息
        if (!empty($context)) {
            $contextText = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $text .= "\n\n<pre>{$contextText}</pre>";
        }

        $response = $this->http->post("{$this->apiUrl}/sendMessage", [
            'json' => [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]
        ]);

        return $response->ok() && ($response->json()['ok'] ?? false);
    }

    /**
     * 发送错误日志（带错误级别颜色标记）
     */
    public function sendError(string $message, ?string $file = null, ?int $line = null): bool
    {
        $fullMessage = $message;
        if ($file !== null) {
            $fullMessage .= "\n📁 {$file}" . ($line !== null ? ":{$line}" : '');
        }
        
        $text = "<b>🔴 ERROR</b>\n" .
                "<code>" . date('Y-m-d H:i:s') . "</code>\n\n" .
                $this->escapeHtml($fullMessage);

        $response = $this->http->post("{$this->apiUrl}/sendMessage", [
            'json' => [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]
        ]);

        return $response->ok() && ($response->json()['ok'] ?? false);
    }

    /**
     * 获取引擎名称
     */
    public function getName(): string
    {
        return 'Telegram';
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
     * 获取当前配置
     */
    public function getConfig(): array
    {
        return [
            'bot_token_set' => !empty($this->botToken),
            'chat_id_set' => !empty($this->chatId),
            'enabled' => $this->enabled,
        ];
    }

    /**
     * 测试连接
     */
    public function test(): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'message' => 'Token or Chat ID not configured',
            ];
        }

        $response = $this->http->get("{$this->apiUrl}/getMe");
        
        $data = $response->json();
        if ($response->ok() && ($data['ok'] ?? false)) {
            return [
                'success' => true,
                'bot_name' => $data['result']['username'] ?? 'Unknown',
                'message' => 'Telegram bot connected successfully',
            ];
        }
        
        return [
            'success' => false,
            'message' => $data['description'] ?? 'Unknown error',
        ];
    }

    /**
     * 获取级别对应的 emoji
     */
    private function getLevelEmoji(string $level): string
    {
        return match (strtoupper($level)) {
            'INFO' => '🟢',
            'WARN', 'WARNING' => '🟡',
            'DEBUG' => '🔵',
            'ERROR' => '🔴',
            default => '⚪',
        };
    }

    /**
     * HTML 转义
     */
    private function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
