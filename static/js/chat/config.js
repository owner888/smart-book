/**
 * 配置模块
 * 从 API 动态加载服务器配置
 */

// 默认配置（作为回退）
let API_BASE = 'http://localhost:8088';
let MCP_URL = 'http://localhost:8089/mcp';

// 同步加载配置（使用 XMLHttpRequest）
function loadConfigSync() {
    try {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', API_BASE + '/api/config', false); // 同步请求
        xhr.send();
        
        if (xhr.status === 200) {
            const config = JSON.parse(xhr.responseText);
            API_BASE = config.webServer.url;
            MCP_URL = config.mcpServer.url;
            console.log('✅ 配置已从 API 加载:', config);
        } else {
            console.warn('⚠️ 无法加载配置，使用默认值');
        }
    } catch (error) {
        console.warn('⚠️ 加载配置失败，使用默认值:', error);
    }
}

// 立即同步加载配置
loadConfigSync();

// 导出全局配置
window.ChatConfig = {
    API_BASE,
    MCP_URL,
    PHRASES_STORAGE_KEY: 'smart_book_phrases',
    
    // 提供异步刷新配置的方法
    async refreshConfig() {
        try {
            const response = await fetch(API_BASE + '/api/config');
            if (response.ok) {
                const config = await response.json();
                this.API_BASE = config.webServer.url;
                this.MCP_URL = config.mcpServer.url;
                API_BASE = config.webServer.url;
                MCP_URL = config.mcpServer.url;
                console.log('✅ 配置已刷新:', config);
                return config;
            }
        } catch (error) {
            console.warn('⚠️ 刷新配置失败:', error);
        }
    }
};
