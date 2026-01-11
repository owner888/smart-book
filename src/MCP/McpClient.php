<?php
/**
 * MCP Client - PHP 版本
 * 支持 Streamable HTTP 协议连接 MCP 服务器
 * 
 * @see https://modelcontextprotocol.io/specification/2025-03-26/basic/transports
 * Protocol Revision: 2025-03-26
 */

namespace SmartBook\MCP;

require_once __DIR__ . '/../../Logger.php';

class McpClient
{
    private string $serverUrl;
    private ?string $sessionId = null;
    private bool $isConnected = false;
    private array $serverCapabilities = [];
    private array $serverInfo = [];
    private array $tools = [];
    private array $resources = [];
    private int $requestId = 0;
    
    private string $clientName;
    private string $clientVersion;
    private int $timeout;
    private bool $debug;
    
    // MCP 协议版本
    private const PROTOCOL_VERSION = '2025-03-26';
    
    public function __construct(string $serverUrl, array $options = [])
    {
        $this->serverUrl = rtrim($serverUrl, '/');
        $this->clientName = $options['clientName'] ?? 'smart-book-php';
        $this->clientVersion = $options['clientVersion'] ?? '1.0.0';
        $this->timeout = $options['timeout'] ?? 30;
        $this->debug = $options['debug'] ?? false;
    }
    
    /**
     * 连接到 MCP 服务器（初始化阶段）
     */
    public function connect(): array
    {
        $response = $this->sendRequest('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'roots' => ['listChanged' => true],
                'sampling' => new \stdClass(),
            ],
            'clientInfo' => [
                'name' => $this->clientName,
                'version' => $this->clientVersion,
            ],
        ]);
        
        if (isset($response['error'])) {
            throw new \Exception('Initialize failed: ' . ($response['error']['message'] ?? 'Unknown error'));
        }
        
        $result = $response['result'] ?? [];
        $this->serverCapabilities = $result['capabilities'] ?? [];
        $this->serverInfo = $result['serverInfo'] ?? [];
        $this->isConnected = true;
        
        // 发送 initialized 通知
        $this->sendNotification('notifications/initialized');
        
        \Logger::info("Connected to MCP server: " . ($this->serverInfo['name'] ?? 'Unknown') . ", Protocol: " . ($result['protocolVersion'] ?? 'Unknown'));
        
        return $result;
    }
    
    /**
     * 断开连接
     * 根据规范，客户端可以发送 DELETE 请求终止会话
     */
    public function disconnect(): void
    {
        if ($this->sessionId) {
            try {
                $this->httpDelete();
            } catch (\Exception $e) {
                \Logger::warn("Disconnect warning: " . $e->getMessage());
            }
        }
        
        $this->sessionId = null;
        $this->isConnected = false;
        $this->tools = [];
        $this->resources = [];
        \Logger::info("Disconnected from MCP server");
    }
    
    /**
     * 获取工具列表
     */
    public function listTools(?string $cursor = null): array
    {
        $params = [];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }
        
        $response = $this->sendRequest('tools/list', $params);
        
        if (isset($response['error'])) {
            throw new \Exception('List tools failed: ' . ($response['error']['message'] ?? 'Unknown error'));
        }
        
        $result = $response['result'] ?? [];
        $this->tools = $result['tools'] ?? [];
        \Logger::info("Found " . count($this->tools) . " tools from MCP server");
        
        // 处理分页
        if (!empty($result['nextCursor'])) {
            $moreTools = $this->listTools($result['nextCursor']);
            $this->tools = array_merge($this->tools, $moreTools);
        }
        
        return $this->tools;
    }
    
    /**
     * 调用工具
     */
    public function callTool(string $name, array $arguments = []): array
    {
        $response = $this->sendRequest('tools/call', [
            'name' => $name,
            'arguments' => empty($arguments) ? new \stdClass() : $arguments,
        ]);
        
        if (isset($response['error'])) {
            throw new \Exception('Tool call failed: ' . ($response['error']['message'] ?? 'Unknown error'));
        }
        
        \Logger::info("Tool '{$name}' called successfully");
        
        return $response['result'] ?? [];
    }
    
    /**
     * 获取资源列表
     */
    public function listResources(?string $cursor = null): array
    {
        $params = [];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }
        
        $response = $this->sendRequest('resources/list', $params);
        
        if (isset($response['error'])) {
            throw new \Exception('List resources failed: ' . ($response['error']['message'] ?? 'Unknown error'));
        }
        
        $result = $response['result'] ?? [];
        $this->resources = $result['resources'] ?? [];
        
        // 处理分页
        if (!empty($result['nextCursor'])) {
            $moreResources = $this->listResources($result['nextCursor']);
            $this->resources = array_merge($this->resources, $moreResources);
        }
        
        return $this->resources;
    }
    
    /**
     * 读取资源
     */
    public function readResource(string $uri): array
    {
        $response = $this->sendRequest('resources/read', ['uri' => $uri]);
        
        if (isset($response['error'])) {
            throw new \Exception('Read resource failed: ' . ($response['error']['message'] ?? 'Unknown error'));
        }
        
        return $response['result']['contents'] ?? [];
    }
    
    /**
     * 获取提示词列表
     */
    public function listPrompts(?string $cursor = null): array
    {
        $params = [];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }
        
        $response = $this->sendRequest('prompts/list', $params);
        
        if (isset($response['error'])) {
            throw new \Exception('List prompts failed: ' . ($response['error']['message'] ?? 'Unknown error'));
        }
        
        return $response['result']['prompts'] ?? [];
    }
    
    /**
     * 获取提示词
     */
    public function getPrompt(string $name, array $arguments = []): array
    {
        $response = $this->sendRequest('prompts/get', [
            'name' => $name,
            'arguments' => empty($arguments) ? new \stdClass() : $arguments,
        ]);
        
        if (isset($response['error'])) {
            throw new \Exception('Get prompt failed: ' . ($response['error']['message'] ?? 'Unknown error'));
        }
        
        return $response['result'] ?? [];
    }
    
    /**
     * 发送 JSON-RPC 请求
     */
    private function sendRequest(string $method, array $params = []): array
    {
        $id = ++$this->requestId;
        
        $payload = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
        ];
        
        if (!empty($params)) {
            $payload['params'] = $params;
        }
        
        return $this->httpPost($payload);
    }
    
    /**
     * 发送通知（无需响应）
     */
    private function sendNotification(string $method, array $params = []): void
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
        ];
        
        if (!empty($params)) {
            $payload['params'] = $params;
        }
        
        $this->httpPost($payload, false);
    }
    
    /**
     * HTTP POST 请求
     * 根据规范：
     * - 必须包含 Accept header: application/json, text/event-stream
     * - 通知返回 202 Accepted
     * - 请求返回 application/json 或 text/event-stream
     */
    private function httpPost(array $payload, bool $expectResponse = true): array
    {
        $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE);
        
        if ($this->debug) {
            \Logger::debug("MCP Request: {$jsonBody}");
        }
        
        // 根据规范必须同时支持 JSON 和 SSE
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ];
        
        if ($this->sessionId) {
            $headers[] = "Mcp-Session-Id: {$this->sessionId}";
        }
        
        $ch = curl_init($this->serverUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        
        if ($error) {
            throw new \Exception("CURL error: {$error}");
        }
        
        // 解析响应头
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // 提取 session ID（服务器在初始化响应中返回）
        if (preg_match('/mcp-session-id:\s*([^\r\n]+)/i', $headerStr, $matches)) {
            $this->sessionId = trim($matches[1]);
            \Logger::info("MCP Session ID: {$this->sessionId}");
        }
        
        // 检查 Content-Type
        $contentType = '';
        if (preg_match('/content-type:\s*([^\r\n;]+)/i', $headerStr, $matches)) {
            $contentType = trim($matches[1]);
        }
        
        if ($this->debug) {
            \Logger::debug("MCP Response (HTTP {$httpCode}, {$contentType}): " . substr($body, 0, 500));
        }
        
        // 处理通知响应 (202 Accepted)
        if (!$expectResponse && $httpCode === 202) {
            return ['accepted' => true];
        }
        
        // 处理会话过期 (404 Not Found)
        if ($httpCode === 404 && $this->sessionId) {
            $this->sessionId = null;
            throw new \Exception('Session expired, please reconnect');
        }
        
        // 处理错误
        if ($httpCode >= 400) {
            $errorData = json_decode($body, true);
            $errorMsg = $errorData['error']['message'] ?? "HTTP error {$httpCode}";
            throw new \Exception($errorMsg);
        }
        
        // 成功响应
        if ($httpCode === 200) {
            // SSE 响应
            if (strpos($contentType, 'text/event-stream') !== false) {
                return $this->parseSSEResponse($body);
            }
            // JSON 响应
            return json_decode($body, true) ?? [];
        }
        
        throw new \Exception("Unexpected HTTP status: {$httpCode}");
    }
    
    /**
     * HTTP DELETE 请求（终止会话）
     */
    private function httpDelete(): void
    {
        if (!$this->sessionId) {
            return;
        }
        
        $headers = [
            "Mcp-Session-Id: {$this->sessionId}",
        ];
        
        $ch = curl_init($this->serverUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // 405 表示服务器不支持客户端终止会话，这是允许的
        if ($httpCode !== 200 && $httpCode !== 405) {
            \Logger::warn("MCP DELETE returned HTTP {$httpCode}");
        }
    }
    
    /**
     * 解析 SSE 响应
     * 根据规范，SSE 流中可能包含多个事件
     */
    private function parseSSEResponse(string $body): array
    {
        $lines = explode("\n", $body);
        $result = null;
        $currentData = '';
        
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            
            // 空行表示事件结束
            if ($line === '' && $currentData !== '') {
                $parsed = json_decode($currentData, true);
                if ($parsed) {
                    // 保存最后一个有效的请求响应
                    if (isset($parsed['result']) || isset($parsed['error'])) {
                        $result = $parsed;
                    }
                }
                $currentData = '';
                continue;
            }
            
            // 解析 data 行
            if (strpos($line, 'data:') === 0) {
                $data = substr($line, 5);
                // 处理多行数据
                if ($data !== '' && $data[0] === ' ') {
                    $data = substr($data, 1);
                }
                $currentData .= $data;
            }
        }
        
        // 处理最后一个事件
        if ($currentData !== '') {
            $parsed = json_decode($currentData, true);
            if ($parsed && (isset($parsed['result']) || isset($parsed['error']))) {
                $result = $parsed;
            }
        }
        
        return $result ?? [];
    }
    
    /**
     * 获取工具定义（Gemini 格式）
     */
    public function getToolsForGemini(): array
    {
        $declarations = [];
        foreach ($this->tools as $tool) {
            $declarations[] = [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters' => $tool['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }
        return $declarations;
    }
    
    /**
     * 获取工具定义（OpenAI 格式）
     */
    public function getToolsForOpenAI(): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ];
        }
        return $tools;
    }
    
    /**
     * 是否已连接
     */
    public function isConnected(): bool
    {
        return $this->isConnected;
    }
    
    /**
     * 获取会话 ID
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }
    
    /**
     * 获取服务器能力
     */
    public function getCapabilities(): array
    {
        return $this->serverCapabilities;
    }
    
    /**
     * 获取服务器信息
     */
    public function getServerInfo(): array
    {
        return $this->serverInfo;
    }
    
    /**
     * 获取已缓存的工具
     */
    public function getTools(): array
    {
        return $this->tools;
    }
}
