<?php
/**
 * Smart Book 初始化文件
 * 
 * 负责：
 * 1. 加载 Composer 自动加载
 * 2. 加载 .env 文件
 * 3. 加载配置并定义常量
 */

// ===================================
// Composer 自动加载
// ===================================

require __DIR__ . '/vendor/autoload.php';

// ===================================
// 加载 .env 文件
// ===================================

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim(trim($value), '"\'');
            
            if (!getenv($key)) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }
}

loadEnv(__DIR__ . '/.env');

// ===================================
// 加载配置
// ===================================

$GLOBALS['config'] = [
    'app' => require __DIR__ . '/config/app.php',
    'db' => require __DIR__ . '/config/database.php',
    'prompts' => require __DIR__ . '/config/prompts.php',
];

// 定义常量
define('REDIS_HOST', $GLOBALS['config']['db']['redis']['host']);
define('REDIS_PORT', $GLOBALS['config']['db']['redis']['port']);
define('CACHE_TTL', $GLOBALS['config']['db']['cache']['ttl']);
define('CACHE_PREFIX', $GLOBALS['config']['db']['redis']['prefix']);
define('GEMINI_API_KEY', $GLOBALS['config']['app']['ai']['gemini']['api_key']);
define('DEFAULT_BOOK_CACHE', $GLOBALS['config']['app']['books']['default']['cache']);
define('DEFAULT_BOOK_PATH', $GLOBALS['config']['app']['books']['default']['path']);

// 服务器配置常量（分离监听地址和访问地址）
define('WEB_SERVER_LISTEN', getenv('WEB_SERVER_LISTEN') ?: '0.0.0.0');  // 监听地址
define('WEB_SERVER_HOST', getenv('WEB_SERVER_HOST') ?: 'localhost');    // 访问地址
define('WEB_SERVER_PORT', getenv('WEB_SERVER_PORT') ?: '8088');

define('MCP_SERVER_LISTEN', getenv('MCP_SERVER_LISTEN') ?: '0.0.0.0');
define('MCP_SERVER_HOST', getenv('MCP_SERVER_HOST') ?: 'localhost');
define('MCP_SERVER_PORT', getenv('MCP_SERVER_PORT') ?: '8089');

define('WS_SERVER_LISTEN', getenv('WS_SERVER_LISTEN') ?: '0.0.0.0');
define('WS_SERVER_HOST', getenv('WS_SERVER_HOST') ?: 'localhost');
define('WS_SERVER_PORT', getenv('WS_SERVER_PORT') ?: '8081');

// 验证 API Key
if (empty(GEMINI_API_KEY)) {
    die("❌ 错误: 无法获取 GEMINI_API_KEY\n" .
        "   请在 smart-book/.env 文件中配置:\n" .
        "   GEMINI_API_KEY=your_api_key_here\n\n" .
        "   或复制模板: cp .env.example .env\n");
}
