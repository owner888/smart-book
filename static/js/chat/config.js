/**
 * 配置模块
 * 必须最先加载，获取服务器配置
 */

// 同步加载配置（使用 XMLHttpRequest）
function loadConfigSync() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'http://localhost:8081/api/config', false); // 使用默认地址加载配置
    
    try {
        xhr.send();
        
        if (xhr.status === 200) {
            const config = JSON.parse(xhr.responseText);
            return {
                API_BASE: config.webServer.url,
                MCP_URL: config.mcpServer.url,
                WS_URL: config.wsServer.url,
            };
        } else {
            throw new Error(`配置加载失败: HTTP ${xhr.status}`);
        }
    } catch (error) {
        throw new Error(`无法加载配置: ${error.message}`);
    }
}

// 立即同步加载配置
let config;
try {
    config = loadConfigSync();
    console.log('✅ 配置加载成功:', config);
} catch (error) {
    console.error('❌ 配置加载失败:', error);
    alert(`配置加载失败：${error.message}\n\n请确保服务器正在运行：php server.php start`);
    throw error; // 阻止页面继续加载
}

// 导出全局配置
window.ChatConfig = {
    API_BASE: config.API_BASE,
    MCP_URL: config.MCP_URL,
    WS_URL: config.WS_URL,
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
            } else {
                throw new Error(`HTTP ${response.status}`);
            }
        } catch (error) {
            console.error('⚠️ 刷新配置失败:', error);
            throw error;
        }
    }
};
