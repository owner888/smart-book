<?php
/**
 * Smart Book 初始化文件
 * 
 * 负责：
 * 1. 加载 .env 文件
 * 2. 加载配置
 * 3. 自动加载类文件
 */

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

// 验证 API Key
if (empty(GEMINI_API_KEY)) {
    die("❌ 错误: 无法获取 GEMINI_API_KEY\n" .
        "   请在 smart-book/.env 文件中配置:\n" .
        "   GEMINI_API_KEY=your_api_key_here\n\n" .
        "   或复制模板: cp .env.example .env\n");
}

// ===================================
// 自动加载类
// ===================================

spl_autoload_register(function ($class) {
    // 类名到文件的映射
    $classMap = [
        // AI 客户端
        'GeminiClient' => __DIR__ . '/src/AI/GeminiClient.php',
        'AsyncGeminiClient' => __DIR__ . '/src/AI/AsyncGeminiClient.php',
        'AsyncCurlManager' => __DIR__ . '/src/AI/AsyncCurlManager.php',
        'OpenAIClient' => __DIR__ . '/src/AI/OpenAIClient.php',
        'AIService' => __DIR__ . '/src/AI/AIService.php',
        
        // 缓存
        'CacheService' => __DIR__ . '/src/Cache/CacheService.php',
        'RedisVectorStore' => __DIR__ . '/src/Cache/RedisVectorStore.php',
        
        // RAG (临时：仍从旧文件加载)
        'EmbeddingClient' => __DIR__ . '/rag.php',
        'DocumentChunker' => __DIR__ . '/rag.php',
        'VectorStore' => __DIR__ . '/rag.php',
        'BookRAGAssistant' => __DIR__ . '/rag.php',
        'EpubParser' => __DIR__ . '/rag.php',
        
        // 提示词
        'CalibreAIPrompts' => __DIR__ . '/src/Prompts/CalibreAIPrompts.php',
        'CalibreAIService' => __DIR__ . '/ai_prompts.php',
    ];
    
    static $loaded = [];
    
    if (isset($classMap[$class]) && file_exists($classMap[$class])) {
        $file = $classMap[$class];
        // 避免重复加载同一文件
        if (!isset($loaded[$file])) {
            require_once $file;
            $loaded[$file] = true;
        }
    }
});
