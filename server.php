<?php
/**
 * Smart Book AI 服务入口文件
 * 
 * 启动服务：
 * php server.php start
 * php server.php start -d  (守护进程模式)
 * php server.php restart
 * php server.php stop
 */

// 加载 Composer autoload
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    echo "请先运行: composer require workerman/workerman\n";
    exit(1);
}

// 加载初始化文件
require_once __DIR__ . '/bootstrap.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use SmartBook\RAG\BookIndexer;

// 启动前检查并自动创建书籍索引
$indexer = new BookIndexer(__DIR__ . '/books', GEMINI_API_KEY);
$indexer->checkAndIndexAll();

use Workerman\Protocols\Http\Request;
use SmartBook\AI\AsyncCurlManager;
use SmartBook\Cache\CacheService;
use SmartBook\MCP\ToolManager;

// ===================================
// HTTP 服务器
// ===================================

$httpWorker = new Worker('http://0.0.0.0:8088');
$httpWorker->count = 1;
$httpWorker->name = 'AI-HTTP-Server';

$httpWorker->onWorkerStart = function ($worker) {
    try {
        CacheService::init();
    } catch (Exception $e) {
        echo "⚠️  Redis 连接失败: {$e->getMessage()}\n";
    }
    AsyncCurlManager::init();
    
    // 初始化 MCP 工具
    ToolManager::initDefaultTools();
};

$httpWorker->onMessage = function (TcpConnection $connection, Request $request) {
    handleHttpRequest($connection, $request);
};

// ===================================
// WebSocket 服务器
// ===================================

$wsWorker = new Worker('websocket://0.0.0.0:8081');
$wsWorker->count = 1;
$wsWorker->name = 'AI-WebSocket-Server';

$wsWorker->onConnect = fn($conn) => null;
$wsWorker->onMessage = function (TcpConnection $connection, $data) {
    handleWebSocketMessage($connection, $data);
};
$wsWorker->onClose = fn($conn) => null;

// ===================================
// 启动
// ===================================

echo "=========================================\n";
echo "   AI 书籍助手 Smart Book 服务\n";
echo "=========================================\n";
echo "🌐 打开浏览器访问: http://localhost:8088\n";
echo "=========================================\n";

Worker::runAll();
