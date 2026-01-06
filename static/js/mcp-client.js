/**
 * MCP Client - 简单的 MCP (Model Context Protocol) 客户端实现
 * 
 * 支持 Streamable HTTP Transport (2025-03-26 规范)
 * - POST /mcp: JSON-RPC 请求
 * - GET /mcp (Accept: text/event-stream): SSE 连接
 * - DELETE /mcp: 终止会话
 * 
 * 使用示例:
 * ```js
 * const client = new McpClient('http://localhost:8787/mcp');
 * await client.connect();
 * 
 * // 列出工具
 * const tools = await client.listTools();
 * 
 * // 调用工具
 * const result = await client.callTool('search_book', { query: '孙悟空' });
 * 
 * // 读取资源
 * const resource = await client.readResource('book://library/list');
 * 
 * // 断开连接
 * client.disconnect();
 * ```
 */

class McpClient {
    /**
     * @param {string} serverUrl - MCP 服务器 URL
     * @param {Object} options - 配置选项
     * @param {string} options.clientName - 客户端名称
     * @param {string} options.clientVersion - 客户端版本
     * @param {number} options.timeout - 请求超时时间（毫秒）
     * @param {boolean} options.debug - 是否开启调试日志
     */
    constructor(serverUrl, options = {}) {
        this.serverUrl = serverUrl.replace(/\/$/, ''); // 移除末尾斜杠
        this.sessionId = null;
        this.requestId = 0;
        this.eventSource = null;
        this.connected = false;
        
        // 配置选项
        this.clientName = options.clientName || 'mcp-js-client';
        this.clientVersion = options.clientVersion || '1.0.0';
        this.timeout = options.timeout || 60000;
        this.debug = options.debug || false;
        
        // 服务器信息（初始化后填充）
        this.serverInfo = null;
        this.capabilities = null;
        this.protocolVersion = null;
        
        // 事件回调
        this.onNotification = null;
        this.onProgress = null;
        this.onError = null;
        this.onDisconnect = null;
    }
    
    /**
     * 连接到 MCP 服务器
     * @returns {Promise<Object>} 初始化响应
     */
    async connect() {
        this.log('Connecting to MCP server:', this.serverUrl);
        
        // 1. 先建立 SSE 连接（可选，用于接收服务器推送）
        try {
            await this.establishSSE();
        } catch (e) {
            this.log('SSE not supported or failed:', e.message);
            // SSE 失败不影响基本功能
        }
        
        // 2. 发送 initialize 请求
        const initResult = await this.sendRequest('initialize', {
            protocolVersion: '2025-03-26',
            clientInfo: {
                name: this.clientName,
                version: this.clientVersion,
            },
            capabilities: {
                roots: { listChanged: false },
                sampling: {},
            },
        });
        
        this.serverInfo = initResult.serverInfo;
        this.capabilities = initResult.capabilities;
        this.protocolVersion = initResult.protocolVersion;
        
        this.log('Server info:', this.serverInfo);
        this.log('Capabilities:', this.capabilities);
        
        // 3. 发送 initialized 通知
        await this.sendNotification('notifications/initialized', {});
        
        this.connected = true;
        this.log('Connected successfully!');
        
        return initResult;
    }
    
    /**
     * 建立 SSE 连接（用于接收服务器推送的通知）
     */
    async establishSSE() {
        return new Promise((resolve, reject) => {
            const url = this.sessionId 
                ? `${this.serverUrl}?session_id=${this.sessionId}`
                : this.serverUrl;
            
            this.log('Establishing SSE connection:', url);
            
            this.eventSource = new EventSource(url);
            
            this.eventSource.onopen = () => {
                this.log('SSE connection opened');
                resolve();
            };
            
            this.eventSource.onerror = (e) => {
                this.log('SSE error:', e);
                if (this.eventSource.readyState === EventSource.CLOSED) {
                    if (this.onDisconnect) this.onDisconnect();
                }
                reject(new Error('SSE connection failed'));
            };
            
            // 监听 message 事件（JSON-RPC 消息）
            this.eventSource.addEventListener('message', (e) => {
                try {
                    const message = JSON.parse(e.data);
                    this.handleSSEMessage(message);
                } catch (err) {
                    this.log('Failed to parse SSE message:', e.data);
                }
            });
            
            // 设置超时
            setTimeout(() => {
                if (this.eventSource.readyState === EventSource.CONNECTING) {
                    this.eventSource.close();
                    reject(new Error('SSE connection timeout'));
                }
            }, 5000);
        });
    }
    
    /**
     * 处理 SSE 消息
     */
    handleSSEMessage(message) {
        this.log('SSE message:', message);
        
        const method = message.method;
        const params = message.params || {};
        
        switch (method) {
            case 'notifications/message':
                // 服务器日志/通知
                if (this.onNotification) {
                    this.onNotification({
                        level: params.level,
                        message: params.message || params.data,
                        logger: params.logger,
                    });
                }
                break;
                
            case 'notifications/progress':
                // 进度更新
                if (this.onProgress) {
                    this.onProgress({
                        token: params.progressToken,
                        progress: params.progress,
                        total: params.total,
                        message: params.message,
                    });
                }
                break;
                
            case 'notifications/resources/list_changed':
                // 资源列表变化
                this.log('Resources list changed');
                break;
                
            case 'notifications/tools/list_changed':
                // 工具列表变化
                this.log('Tools list changed');
                break;
                
            default:
                this.log('Unknown notification:', method);
        }
    }
    
    /**
     * 发送 JSON-RPC 请求
     * @param {string} method - 方法名
     * @param {Object} params - 参数
     * @returns {Promise<Object>} 响应结果
     */
    async sendRequest(method, params = {}) {
        const id = ++this.requestId;
        const body = {
            jsonrpc: '2.0',
            id,
            method,
            params,
        };
        
        this.log(`Request [${id}]:`, method, params);
        
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        };
        
        if (this.sessionId) {
            headers['Mcp-Session-Id'] = this.sessionId;
        }
        
        const response = await fetch(this.serverUrl, {
            method: 'POST',
            headers,
            body: JSON.stringify(body),
        });
        
        // 保存 session ID
        const newSessionId = response.headers.get('Mcp-Session-Id');
        if (newSessionId) {
            this.sessionId = newSessionId;
            this.log('Session ID:', this.sessionId);
        }
        
        if (!response.ok) {
            throw new McpError(-32000, `HTTP error: ${response.status}`, {
                status: response.status,
                statusText: response.statusText,
            });
        }
        
        const result = await response.json();
        
        this.log(`Response [${id}]:`, result);
        
        if (result.error) {
            throw new McpError(result.error.code, result.error.message, result.error.data);
        }
        
        return result.result;
    }
    
    /**
     * 发送通知（不期望响应）
     * @param {string} method - 方法名
     * @param {Object} params - 参数
     */
    async sendNotification(method, params = {}) {
        const body = {
            jsonrpc: '2.0',
            method,
            params,
        };
        
        this.log('Notification:', method, params);
        
        const headers = {
            'Content-Type': 'application/json',
        };
        
        if (this.sessionId) {
            headers['Mcp-Session-Id'] = this.sessionId;
        }
        
        await fetch(this.serverUrl, {
            method: 'POST',
            headers,
            body: JSON.stringify(body),
        });
    }
    
    /**
     * 批量发送请求
     * @param {Array} requests - 请求数组 [{method, params}, ...]
     * @returns {Promise<Array>} 响应数组
     */
    async sendBatch(requests) {
        const batch = requests.map((req, index) => ({
            jsonrpc: '2.0',
            id: ++this.requestId,
            method: req.method,
            params: req.params || {},
        }));
        
        this.log('Batch request:', batch);
        
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        };
        
        if (this.sessionId) {
            headers['Mcp-Session-Id'] = this.sessionId;
        }
        
        const response = await fetch(this.serverUrl, {
            method: 'POST',
            headers,
            body: JSON.stringify(batch),
        });
        
        if (!response.ok) {
            throw new McpError(-32000, `HTTP error: ${response.status}`);
        }
        
        const results = await response.json();
        this.log('Batch response:', results);
        
        return results.map(r => r.error ? { error: r.error } : { result: r.result });
    }
    
    // ==================== 工具相关 ====================
    
    /**
     * 列出所有可用工具
     * @returns {Promise<Array>} 工具列表
     */
    async listTools() {
        const result = await this.sendRequest('tools/list');
        return result.tools || [];
    }
    
    /**
     * 调用工具
     * @param {string} name - 工具名称
     * @param {Object} args - 工具参数
     * @returns {Promise<Object>} 工具返回结果
     */
    async callTool(name, args = {}) {
        const result = await this.sendRequest('tools/call', {
            name,
            arguments: args,
        });
        return result;
    }
    
    // ==================== 资源相关 ====================
    
    /**
     * 列出所有可用资源
     * @returns {Promise<Array>} 资源列表
     */
    async listResources() {
        const result = await this.sendRequest('resources/list');
        return result.resources || [];
    }
    
    /**
     * 读取资源
     * @param {string} uri - 资源 URI
     * @returns {Promise<Object>} 资源内容
     */
    async readResource(uri) {
        const result = await this.sendRequest('resources/read', { uri });
        return result;
    }
    
    /**
     * 列出资源模板
     * @returns {Promise<Array>} 资源模板列表
     */
    async listResourceTemplates() {
        const result = await this.sendRequest('resources/templates/list');
        return result.resourceTemplates || [];
    }
    
    // ==================== 提示词相关 ====================
    
    /**
     * 列出所有提示词
     * @returns {Promise<Array>} 提示词列表
     */
    async listPrompts() {
        const result = await this.sendRequest('prompts/list');
        return result.prompts || [];
    }
    
    /**
     * 获取提示词
     * @param {string} name - 提示词名称
     * @param {Object} args - 提示词参数
     * @returns {Promise<Object>} 提示词内容
     */
    async getPrompt(name, args = {}) {
        const result = await this.sendRequest('prompts/get', {
            name,
            arguments: args,
        });
        return result;
    }
    
    // ==================== 补全相关 ====================
    
    /**
     * 获取参数补全建议
     * @param {Object} ref - 引用 (ref/prompt 或 ref/resource)
     * @param {Object} argument - 参数信息
     * @returns {Promise<Object>} 补全建议
     */
    async complete(ref, argument) {
        const result = await this.sendRequest('completion/complete', {
            ref,
            argument,
        });
        return result.completion || { values: [] };
    }
    
    // ==================== 任务相关 ====================
    
    /**
     * 列出所有任务
     * @returns {Promise<Array>} 任务列表
     */
    async listTasks() {
        const result = await this.sendRequest('tasks/list');
        return result.tasks || [];
    }
    
    /**
     * 获取任务状态
     * @param {string} taskId - 任务 ID
     * @returns {Promise<Object>} 任务信息
     */
    async getTask(taskId) {
        const result = await this.sendRequest('tasks/get', { id: taskId });
        return result.task;
    }
    
    /**
     * 取消任务
     * @param {string} taskId - 任务 ID
     * @returns {Promise<Object>} 取消结果
     */
    async cancelTask(taskId) {
        const result = await this.sendRequest('tasks/cancel', { id: taskId });
        return result.task;
    }
    
    /**
     * 获取任务结果
     * @param {string} taskId - 任务 ID
     * @returns {Promise<Object>} 任务结果
     */
    async getTaskResult(taskId) {
        const result = await this.sendRequest('tasks/result', { id: taskId });
        return result.result;
    }
    
    /**
     * 轮询等待任务完成
     * @param {string} taskId - 任务 ID
     * @param {Object} options - 选项
     * @param {number} options.interval - 轮询间隔（毫秒）
     * @param {number} options.timeout - 超时时间（毫秒）
     * @returns {Promise<Object>} 任务结果
     */
    async waitForTask(taskId, options = {}) {
        const interval = options.interval || 1000;
        const timeout = options.timeout || 60000;
        const startTime = Date.now();
        
        while (Date.now() - startTime < timeout) {
            const task = await this.getTask(taskId);
            
            if (task.status === 'completed') {
                return await this.getTaskResult(taskId);
            }
            
            if (task.status === 'failed' || task.status === 'cancelled') {
                throw new McpError(-32000, `Task ${task.status}: ${taskId}`);
            }
            
            await this.sleep(interval);
        }
        
        throw new McpError(-32000, `Task timeout: ${taskId}`);
    }
    
    // ==================== 日志相关 ====================
    
    /**
     * 设置日志级别
     * @param {string} level - 日志级别 (debug, info, warning, error)
     */
    async setLogLevel(level) {
        await this.sendRequest('logging/setLevel', { level });
    }
    
    // ==================== 工具方法 ====================
    
    /**
     * Ping 服务器
     * @returns {Promise<Object>} 空对象表示成功
     */
    async ping() {
        return await this.sendRequest('ping');
    }
    
    /**
     * 断开连接
     */
    async disconnect() {
        this.log('Disconnecting...');
        
        // 关闭 SSE
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
        
        // 发送 DELETE 请求终止会话
        if (this.sessionId) {
            try {
                await fetch(this.serverUrl, {
                    method: 'DELETE',
                    headers: {
                        'Mcp-Session-Id': this.sessionId,
                    },
                });
            } catch (e) {
                this.log('Error during disconnect:', e);
            }
        }
        
        this.connected = false;
        this.sessionId = null;
        this.log('Disconnected');
    }
    
    /**
     * 检查是否已连接
     * @returns {boolean}
     */
    isConnected() {
        return this.connected && this.sessionId !== null;
    }
    
    /**
     * 获取服务器状态
     * @returns {Promise<Object>}
     */
    async getServerStatus() {
        return await this.callTool('server_status');
    }
    
    /**
     * 调试日志
     */
    log(...args) {
        if (this.debug) {
            console.log('[McpClient]', ...args);
        }
    }
    
    /**
     * 休眠
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

/**
 * MCP 错误类
 */
class McpError extends Error {
    constructor(code, message, data = null) {
        super(message);
        this.name = 'McpError';
        this.code = code;
        this.data = data;
    }
    
    toJSON() {
        return {
            name: this.name,
            code: this.code,
            message: this.message,
            data: this.data,
        };
    }
}

// ==================== 辅助函数 ====================

/**
 * 解析 MCP 工具返回的内容
 * @param {Object} result - callTool 返回结果
 * @returns {string|Object} 解析后的内容
 */
function parseToolContent(result) {
    if (!result || !result.content || !result.content.length) {
        return null;
    }
    
    const content = result.content[0];
    
    if (content.type === 'text') {
        try {
            return JSON.parse(content.text);
        } catch {
            return content.text;
        }
    }
    
    if (content.type === 'image') {
        return {
            type: 'image',
            data: content.data,
            mimeType: content.mimeType,
        };
    }
    
    return content;
}

/**
 * 解析资源内容
 * @param {Object} result - readResource 返回结果
 * @returns {string|Object} 解析后的内容
 */
function parseResourceContent(result) {
    if (!result || !result.contents || !result.contents.length) {
        return null;
    }
    
    const content = result.contents[0];
    
    if (content.text) {
        try {
            return JSON.parse(content.text);
        } catch {
            return content.text;
        }
    }
    
    if (content.blob) {
        return {
            type: 'blob',
            data: content.blob,
            mimeType: content.mimeType,
        };
    }
    
    return content;
}

// 导出（支持 ES6 模块和浏览器全局）
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { McpClient, McpError, parseToolContent, parseResourceContent };
} else if (typeof window !== 'undefined') {
    window.McpClient = McpClient;
    window.McpError = McpError;
    window.parseToolContent = parseToolContent;
    window.parseResourceContent = parseResourceContent;
}
