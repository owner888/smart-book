/**
 * Chat MCP 模块 - 处理 MCP 工具调用
 */

const ChatMCP = {
    clients: {},       // MCP client 实例
    servers: [],       // MCP 服务器配置
    tools: [],         // 所有可用工具
    enabled: false,    // 是否启用 MCP
    
    /**
     * 初始化
     */
    async init() {
        await this.loadServers();
        await this.connectEnabledServers();
    },
    
    /**
     * 加载服务器配置
     */
    async loadServers() {
        try {
            const response = await fetch('/api/mcp/servers');
            const data = await response.json();
            this.servers = (data.servers || []).filter(s => s.enabled);
        } catch (error) {
            // 从 localStorage 加载
            const saved = localStorage.getItem('mcp_servers');
            if (saved) {
                this.servers = JSON.parse(saved).filter(s => s.enabled);
            } else {
                // 默认配置
                this.servers = [{
                    name: 'smart-book',
                    type: 'http',
                    url: 'http://localhost:8089/mcp',
                    enabled: true
                }];
            }
        }
    },
    
    /**
     * 连接所有启用的服务器
     */
    async connectEnabledServers() {
        const httpServers = this.servers.filter(s => s.type === 'http' && s.enabled);
        
        for (const server of httpServers) {
            try {
                await this.connectServer(server);
            } catch (error) {
                console.warn(`MCP 服务器 ${server.name} 连接失败:`, error);
            }
        }
        
        // 收集所有工具
        this.collectTools();
        this.enabled = this.tools.length > 0;
        
        console.log(`MCP 初始化完成，共 ${this.tools.length} 个工具可用`);
    },
    
    /**
     * 连接单个服务器
     */
    async connectServer(server) {
        if (this.clients[server.name]) {
            return; // 已连接
        }
        
        const client = new McpClient(server.url, {
            clientName: 'smart-book-chat',
            clientVersion: '1.0.0',
            debug: false
        });
        
        await client.connect();
        this.clients[server.name] = client;
        
        // 获取工具列表
        const tools = await client.listTools();
        server.tools = tools;
        
        console.log(`✅ MCP 服务器 ${server.name} 已连接，${tools.length} 个工具`);
    },
    
    /**
     * 收集所有可用工具
     */
    collectTools() {
        this.tools = [];
        for (const server of this.servers) {
            if (server.tools && server.tools.length > 0) {
                for (const tool of server.tools) {
                    this.tools.push({
                        ...tool,
                        serverName: server.name
                    });
                }
            }
        }
    },
    
    /**
     * 获取工具供 AI 使用的格式
     */
    getToolsForAI() {
        return this.tools.map(tool => ({
            type: 'function',
            function: {
                name: tool.name,
                description: tool.description,
                parameters: tool.inputSchema || { type: 'object', properties: {} }
            }
        }));
    },
    
    /**
     * 调用工具
     */
    async callTool(toolName, args = {}) {
        // 找到工具所属的服务器
        const tool = this.tools.find(t => t.name === toolName);
        if (!tool) {
            throw new Error(`工具 ${toolName} 不存在`);
        }
        
        const client = this.clients[tool.serverName];
        if (!client || !client.isConnected) {
            throw new Error(`服务器 ${tool.serverName} 未连接`);
        }
        
        return await client.callTool(toolName, args);
    },
    
    /**
     * 处理 AI 的工具调用请求
     */
    async handleToolCalls(toolCalls) {
        const results = [];
        
        for (const call of toolCalls) {
            const toolName = call.function?.name || call.name;
            const args = typeof call.function?.arguments === 'string' 
                ? JSON.parse(call.function.arguments) 
                : (call.function?.arguments || call.arguments || {});
            
            try {
                const result = await this.callTool(toolName, args);
                results.push({
                    tool_call_id: call.id,
                    role: 'tool',
                    name: toolName,
                    content: JSON.stringify(result)
                });
            } catch (error) {
                results.push({
                    tool_call_id: call.id,
                    role: 'tool',
                    name: toolName,
                    content: JSON.stringify({ error: error.message })
                });
            }
        }
        
        return results;
    },
    
    /**
     * 断开所有连接
     */
    async disconnect() {
        for (const [name, client] of Object.entries(this.clients)) {
            try {
                await client.disconnect();
            } catch (error) {
                console.warn(`断开 ${name} 失败:`, error);
            }
        }
        this.clients = {};
        this.tools = [];
        this.enabled = false;
    },
    
    /**
     * 获取可用工具列表（用于 UI 显示）
     */
    getAvailableTools() {
        return this.tools.map(tool => ({
            name: tool.name,
            description: tool.description,
            server: tool.serverName
        }));
    },
    
    /**
     * 检查是否有可用工具
     */
    hasTools() {
        return this.enabled && this.tools.length > 0;
    }
};

// 页面加载时初始化
// 注意：JS MCP Client 已禁用，改用服务器端 PHP 实现
// 这避免了客户端直接连接 MCP 服务器的复杂性
// document.addEventListener('DOMContentLoaded', () => {
//     setTimeout(() => {
//         ChatMCP.init().catch(err => {
//             console.warn('MCP 初始化失败:', err);
//         });
//     }, 500);
// });
