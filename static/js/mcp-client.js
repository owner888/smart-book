/**
 * MCP Client - JavaScript 版本
 * 支持 Streamable HTTP 协议连接 MCP 服务器
 * 
 * @see https://modelcontextprotocol.io/specification/2025-03-26/basic/transports
 * Protocol Revision: 2025-03-26
 */

class McpClient {
    // MCP 协议版本
    static PROTOCOL_VERSION = '2025-03-26';
    
    constructor(serverUrl, options = {}) {
        this.serverUrl = serverUrl.replace(/\/$/, '');
        this.sessionId = null;
        this.isConnected = false;
        this.serverCapabilities = {};
        this.serverInfo = {};
        this.tools = [];
        this.resources = [];
        this.requestId = 0;
        
        this.clientName = options.clientName || 'smart-book-js';
        this.clientVersion = options.clientVersion || '1.0.0';
        this.timeout = options.timeout || 30000;
        this.debug = options.debug || false;
    }
    
    /**
     * 连接到 MCP 服务器（初始化阶段）
     */
    async connect() {
        const response = await this.sendRequest('initialize', {
            protocolVersion: McpClient.PROTOCOL_VERSION,
            capabilities: {
                roots: { listChanged: true },
                sampling: {},
            },
            clientInfo: {
                name: this.clientName,
                version: this.clientVersion,
            },
        });
        
        if (response.error) {
            throw new Error('Initialize failed: ' + (response.error.message || 'Unknown error'));
        }
        
        const result = response.result || {};
        this.serverCapabilities = result.capabilities || {};
        this.serverInfo = result.serverInfo || {};
        this.isConnected = true;
        
        // 发送 initialized 通知
        await this.sendNotification('notifications/initialized');
        
        this.log(`✅ Connected to MCP server: ${this.serverInfo.name || 'Unknown'}`);
        this.log(`   Protocol: ${result.protocolVersion || 'Unknown'}`);
        
        return result;
    }
    
    /**
     * 断开连接
     * 根据规范，客户端可以发送 DELETE 请求终止会话
     */
    async disconnect() {
        if (this.sessionId) {
            try {
                await this.httpDelete();
            } catch (e) {
                this.log(`⚠️ Disconnect warning: ${e.message}`);
            }
        }
        
        this.sessionId = null;
        this.isConnected = false;
        this.tools = [];
        this.resources = [];
        this.log('🔌 Disconnected from MCP server');
    }
    
    /**
     * 获取工具列表
     */
    async listTools(cursor = null) {
        const params = {};
        if (cursor !== null) {
            params.cursor = cursor;
        }
        
        const response = await this.sendRequest('tools/list', params);
        
        if (response.error) {
            throw new Error('List tools failed: ' + (response.error.message || 'Unknown error'));
        }
        
        const result = response.result || {};
        this.tools = result.tools || [];
        this.log(`📦 Found ${this.tools.length} tools`);
        
        // 处理分页
        if (result.nextCursor) {
            const moreTools = await this.listTools(result.nextCursor);
            this.tools = [...this.tools, ...moreTools];
        }
        
        return this.tools;
    }
    
    /**
     * 调用工具
     */
    async callTool(name, args = {}) {
        const response = await this.sendRequest('tools/call', {
            name: name,
            arguments: Object.keys(args).length === 0 ? {} : args,
        });
        
        if (response.error) {
            throw new Error('Tool call failed: ' + (response.error.message || 'Unknown error'));
        }
        
        this.log(`🔧 Tool '${name}' called successfully`);
        
        return response.result || {};
    }
    
    /**
     * 获取资源列表
     */
    async listResources(cursor = null) {
        const params = {};
        if (cursor !== null) {
            params.cursor = cursor;
        }
        
        const response = await this.sendRequest('resources/list', params);
        
        if (response.error) {
            throw new Error('List resources failed: ' + (response.error.message || 'Unknown error'));
        }
        
        const result = response.result || {};
        this.resources = result.resources || [];
        
        // 处理分页
        if (result.nextCursor) {
            const moreResources = await this.listResources(result.nextCursor);
            this.resources = [...this.resources, ...moreResources];
        }
        
        return this.resources;
    }
    
    /**
     * 读取资源
     */
    async readResource(uri) {
        const response = await this.sendRequest('resources/read', { uri });
        
        if (response.error) {
            throw new Error('Read resource failed: ' + (response.error.message || 'Unknown error'));
        }
        
        return response.result?.contents || [];
    }
    
    /**
     * 获取提示词列表
     */
    async listPrompts(cursor = null) {
        const params = {};
        if (cursor !== null) {
            params.cursor = cursor;
        }
        
        const response = await this.sendRequest('prompts/list', params);
        
        if (response.error) {
            throw new Error('List prompts failed: ' + (response.error.message || 'Unknown error'));
        }
        
        return response.result?.prompts || [];
    }
    
    /**
     * 获取提示词
     */
    async getPrompt(name, args = {}) {
        const response = await this.sendRequest('prompts/get', {
            name: name,
            arguments: Object.keys(args).length === 0 ? {} : args,
        });
        
        if (response.error) {
            throw new Error('Get prompt failed: ' + (response.error.message || 'Unknown error'));
        }
        
        return response.result || {};
    }
    
    /**
     * 发送 JSON-RPC 请求
     */
    async sendRequest(method, params = {}) {
        const id = ++this.requestId;
        
        const payload = {
            jsonrpc: '2.0',
            id: id,
            method: method,
        };
        
        if (Object.keys(params).length > 0) {
            payload.params = params;
        }
        
        return await this.httpPost(payload);
    }
    
    /**
     * 发送通知（无需响应）
     */
    async sendNotification(method, params = {}) {
        const payload = {
            jsonrpc: '2.0',
            method: method,
        };
        
        if (Object.keys(params).length > 0) {
            payload.params = params;
        }
        
        await this.httpPost(payload, false);
    }
    
    /**
     * HTTP POST 请求
     * 根据规范：
     * - 必须包含 Accept header: application/json, text/event-stream
     * - 通知返回 202 Accepted
     * - 请求返回 application/json 或 text/event-stream
     */
    async httpPost(payload, expectResponse = true) {
        const jsonBody = JSON.stringify(payload);
        this.log(`📤 Request: ${jsonBody}`);
        
        // 根据规范必须同时支持 JSON 和 SSE
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json, text/event-stream',
        };
        
        if (this.sessionId) {
            headers['Mcp-Session-Id'] = this.sessionId;
        }
        
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), this.timeout);
        
        try {
            const response = await fetch(this.serverUrl, {
                method: 'POST',
                headers: headers,
                body: jsonBody,
                signal: controller.signal,
            });
            
            clearTimeout(timeoutId);
            
            // 提取 session ID（服务器在初始化响应中返回）
            const newSessionId = response.headers.get('Mcp-Session-Id');
            if (newSessionId) {
                this.sessionId = newSessionId;
                this.log(`📋 Session ID: ${this.sessionId}`);
            }
            
            const contentType = response.headers.get('Content-Type') || '';
            
            this.log(`📥 Response (HTTP ${response.status}, ${contentType})`);
            
            // 处理通知响应 (202 Accepted)
            if (!expectResponse && response.status === 202) {
                return { accepted: true };
            }
            
            // 处理会话过期 (404 Not Found)
            if (response.status === 404 && this.sessionId) {
                this.sessionId = null;
                throw new Error('Session expired, please reconnect');
            }
            
            // 处理错误
            if (response.status >= 400) {
                let errorMsg = `HTTP error ${response.status}`;
                try {
                    const errorData = await response.json();
                    errorMsg = errorData.error?.message || errorMsg;
                } catch (e) {}
                throw new Error(errorMsg);
            }
            
            // 成功响应
            if (response.status === 200) {
                // SSE 响应
                if (contentType.includes('text/event-stream')) {
                    const text = await response.text();
                    return this.parseSSEResponse(text);
                }
                // JSON 响应
                return await response.json();
            }
            
            throw new Error(`Unexpected HTTP status: ${response.status}`);
            
        } catch (error) {
            clearTimeout(timeoutId);
            if (error.name === 'AbortError') {
                throw new Error('Request timeout');
            }
            throw error;
        }
    }
    
    /**
     * HTTP DELETE 请求（终止会话）
     */
    async httpDelete() {
        if (!this.sessionId) {
            return;
        }
        
        const headers = {
            'Mcp-Session-Id': this.sessionId,
        };
        
        try {
            const response = await fetch(this.serverUrl, {
                method: 'DELETE',
                headers: headers,
            });
            
            // 405 表示服务器不支持客户端终止会话，这是允许的
            if (response.status !== 200 && response.status !== 405) {
                this.log(`⚠️ DELETE returned HTTP ${response.status}`);
            }
        } catch (error) {
            this.log(`⚠️ DELETE failed: ${error.message}`);
        }
    }
    
    /**
     * 解析 SSE 响应
     * 根据规范，SSE 流中可能包含多个事件
     */
    parseSSEResponse(body) {
        const lines = body.split('\n');
        let result = null;
        let currentData = '';
        
        for (const rawLine of lines) {
            const line = rawLine.replace(/\r$/, '');
            
            // 空行表示事件结束
            if (line === '' && currentData !== '') {
                try {
                    const parsed = JSON.parse(currentData);
                    // 保存最后一个有效的请求响应
                    if (parsed.result !== undefined || parsed.error !== undefined) {
                        result = parsed;
                    }
                } catch (e) {}
                currentData = '';
                continue;
            }
            
            // 解析 data 行
            if (line.startsWith('data:')) {
                let data = line.substring(5);
                // 处理多行数据（规范要求 data: 后可以有一个空格）
                if (data.length > 0 && data[0] === ' ') {
                    data = data.substring(1);
                }
                currentData += data;
            }
        }
        
        // 处理最后一个事件
        if (currentData !== '') {
            try {
                const parsed = JSON.parse(currentData);
                if (parsed.result !== undefined || parsed.error !== undefined) {
                    result = parsed;
                }
            } catch (e) {}
        }
        
        return result || {};
    }
    
    /**
     * 获取工具定义（OpenAI 格式）
     */
    getToolsForOpenAI() {
        return this.tools.map(tool => ({
            type: 'function',
            function: {
                name: tool.name,
                description: tool.description || '',
                parameters: tool.inputSchema || { type: 'object', properties: {} },
            },
        }));
    }
    
    /**
     * 获取工具定义（Gemini 格式）
     */
    getToolsForGemini() {
        return this.tools.map(tool => ({
            name: tool.name,
            description: tool.description || '',
            parameters: tool.inputSchema || { type: 'object', properties: {} },
        }));
    }
    
    /**
     * 日志输出
     */
    log(message) {
        if (this.debug) {
            console.log(`[MCP Client] ${message}`);
        }
    }
    
    /**
     * 获取连接状态
     */
    getIsConnected() {
        return this.isConnected;
    }
    
    /**
     * 获取会话 ID
     */
    getSessionId() {
        return this.sessionId;
    }
    
    /**
     * 获取服务器能力
     */
    getCapabilities() {
        return this.serverCapabilities;
    }
    
    /**
     * 获取服务器信息
     */
    getServerInfo() {
        return this.serverInfo;
    }
    
    /**
     * 获取已缓存的工具
     */
    getTools() {
        return this.tools;
    }
}

// 导出（兼容 ES 模块和 CommonJS）
if (typeof module !== 'undefined' && module.exports) {
    module.exports = McpClient;
}
