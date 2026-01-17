/**
 * 配置模块
 * 从 API 动态加载服务器配置
 */

// 导出全局配置对象（默认值作为回退）
window.ChatConfig = {
    API_BASE: 'http://localhost:8088',
    MCP_URL: 'http://localhost:8089/mcp',
    WS_URL: 'ws://localhost:8081',
    PHRASES_STORAGE_KEY: 'smart_book_phrases',
    
    // 提供异步刷新配置的方法
    async refreshConfig() {
        try {
            const response = await fetch(this.API_BASE + '/api/config');
            if (response.ok) {
                const config = await response.json();
                this.API_BASE = config.webServer.url;
                this.MCP_URL = config.mcpServer.url;
                this.WS_URL = config.wsServer.url;
                console.log('✅ 配置已刷新:', config);
                return config;
            }
        } catch (error) {
            console.warn('⚠️ 刷新配置失败:', error);
        }
    }
};

// 页面加载时同步加载配置（使用 XMLHttpRequest）
(function loadConfigSync() {
    try {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', window.ChatConfig.API_BASE + '/api/config', false); // 同步请求
        xhr.send();
        
        if (xhr.status === 200) {
            const config = JSON.parse(xhr.responseText);
            window.ChatConfig.API_BASE = config.webServer.url;
            window.ChatConfig.MCP_URL = config.mcpServer.url;
            window.ChatConfig.WS_URL = config.wsServer.url;
            console.log('✅ 配置已从 API 加载:', config);
        } else {
            console.warn('⚠️ 无法加载配置，使用默认值');
        }
    } catch (error) {
        console.warn('⚠️ 加载配置失败，使用默认值:', error);
    }
})();
