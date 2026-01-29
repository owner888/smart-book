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
use Workerman\Protocols\Http\Request;
use SmartBook\RAG\BookIndexer;
use SmartBook\AI\AsyncCurlManager;
use SmartBook\Cache\CacheService;
use SmartBook\MCP\ToolManager;
use SmartBook\MCP\StreamableHttpServer;
use SmartBook\Http\Handlers\StreamHelper;

// 注意: Workerman Task Worker 需要 5.x 版本
// 当前使用文件持久化来存储任务状态，支持服务器重启后恢复

// 启动前检查并自动创建书籍索引
$indexer = new BookIndexer(BOOKS_DIR, GEMINI_API_KEY);
$indexer->checkAndIndexAll();

// ===================================
// HTTP 服务器 (主服务)
// ===================================

$httpWorker = new Worker('http://' . WEB_SERVER_LISTEN . ':' . WEB_SERVER_PORT);
$httpWorker->count = 1;
$httpWorker->name = 'AI-HTTP-Server';

$httpWorker->onWorkerStart = function ($worker) {
    try {
        CacheService::init();
    } catch (Exception $e) {
        echo "⚠️  Redis 连接失败: {$e->getMessage()}\n";
    }
    AsyncCurlManager::init();
    ToolManager::initDefaultTools();
};

$httpWorker->onMessage = function (TcpConnection $connection, Request $request) {
    handleHttpRequest($connection, $request);
};

// ===================================
// WebSocket 服务器 (ASR 流式识别)
// ===================================

$wsWorker = new Worker('websocket://' . WEB_SERVER_LISTEN . ':8083');
$wsWorker->count = 1;
$wsWorker->name = 'ASR-WebSocket-Server';

$wsWorker->onConnect = function(TcpConnection $connection) {
    \SmartBook\Http\Handlers\ASRStreamHandler::onConnect($connection);
};

$wsWorker->onMessage = function(TcpConnection $connection, $data) {
    \SmartBook\Http\Handlers\ASRStreamHandler::onMessage($connection, $data);
};

$wsWorker->onClose = function(TcpConnection $connection) {
    \SmartBook\Http\Handlers\ASRStreamHandler::onClose($connection);
};

$wsWorker->onError = function(TcpConnection $connection, $code, $msg) {
    \SmartBook\Http\Handlers\ASRStreamHandler::onError($connection, $code, $msg);
};

// ===================================
// TTS WebSocket 服务器 (流式语音合成)
// ===================================

$ttsWorker = new Worker('websocket://' . WEB_SERVER_LISTEN . ':8084');
$ttsWorker->count = 1;
$ttsWorker->name = 'TTS-WebSocket-Server';

$ttsWorker->onConnect = function(TcpConnection $connection) {
    // 设置 WebSocket 为二进制模式（支持音频传输）
    $connection->websocketType = \Workerman\Protocols\Websocket::BINARY_TYPE_ARRAYBUFFER;
    
    \SmartBook\Http\Handlers\TTSStreamHandler::onConnect($connection);
};

$ttsWorker->onMessage = function(TcpConnection $connection, $data) {
    \SmartBook\Http\Handlers\TTSStreamHandler::onMessage($connection, $data);
};

$ttsWorker->onClose = function(TcpConnection $connection) {
    \SmartBook\Http\Handlers\TTSStreamHandler::onClose($connection);
};

$ttsWorker->onError = function(TcpConnection $connection, $code, $msg) {
    \SmartBook\Http\Handlers\TTSStreamHandler::onError($connection, $code, $msg);
};

// ===================================
// MCP Server (Streamable HTTP 协议 + SSE 支持)
// ===================================

// 使用 TCP 协议以支持 SSE 长连接
// HTTP 协议会在响应后自动关闭连接，不适合 SSE
$mcpWorker = new Worker('tcp://' . MCP_SERVER_LISTEN . ':' . MCP_SERVER_PORT);
$mcpWorker->count = 1;
$mcpWorker->name = 'MCP-Server';

// 初始化 MCP 服务器
$mcpServer = null;

$mcpWorker->onWorkerStart = function() use (&$mcpServer) {
    $mcpServer = new StreamableHttpServer(BOOKS_DIR, false);
};

// 手动处理 HTTP/SSE 请求
$mcpWorker->onMessage = function (TcpConnection $connection, string $data) use (&$mcpServer) {
    // 解析 HTTP 请求
    $request = StreamHelper::parseHttpRequest($data, $connection);
    if ($request && $mcpServer) {
        $mcpServer->handleRequest($connection, $request);
    }
};

// ===================================
// 启动
// ===================================

echo "=========================================\n";
echo "   AI 书籍助手 Smart Book 服务\n";
echo "=========================================\n";
echo "🌐 Web UI:     http://" . WEB_SERVER_HOST . ":" . WEB_SERVER_PORT . "\n";
echo "🎙️ ASR Stream:  ws://" . WEB_SERVER_HOST . ":8083\n";
echo "   └─ Protocol: WebSocket\n";
echo "   └─ Real-time speech recognition\n";
echo "🔊 TTS Stream:  ws://" . WEB_SERVER_HOST . ":8084\n";
echo "   └─ Protocol: WebSocket\n";
echo "   └─ Real-time text-to-speech\n";
echo "🔌 MCP:        http://" . MCP_SERVER_HOST . ":" . MCP_SERVER_PORT . "/mcp\n";
echo "   └─ Protocol: Streamable HTTP\n";
echo "   └─ Methods: POST (JSON-RPC), GET, DELETE\n";
echo "=========================================\n";

Worker::runAll();
