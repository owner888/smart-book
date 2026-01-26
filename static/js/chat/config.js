/**
 * 配置模块
 * 必须最先加载，获取服务器配置
 * 
 * 依赖：
 * - config.local.js 必须先加载（包含 LocalConfig）
 */

// 同步加载配置（使用 XMLHttpRequest）
function loadConfigSync() {
    // 检查是否已加载本地配置
    if (typeof window.LocalConfig === 'undefined') {
        throw new Error('本地配置文件未加载！请确保 config.local.js 存在并已加载。\n\n如果是首次使用，请复制 config.example.js 为 config.local.js');
    }
    
    const configServerUrl = window.LocalConfig.CONFIG_SERVER_URL;
    const xhr = new XMLHttpRequest();
    xhr.open('GET', `${configServerUrl}/api/config`, false); // 使用本地配置的地址
    
    try {
        xhr.send();
        
        if (xhr.status === 200) {
            const response = JSON.parse(xhr.responseText);
            // ResponseMiddleware 包装了响应，需要从 data 中获取
            const config = response.data || response;
            return {
                API_BASE: config.webServer.url,
                MCP_URL: config.mcpServer.url,
                WS_URL: config.wsServer.url,
            };
        } else {
            throw new Error(`配置加载失败: HTTP ${xhr.status}`);
        }
    } catch (error) {
        throw new Error(`无法加载配置: ${error.message}\n配置服务器: ${configServerUrl}`);
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
                const result = await response.json();
                // ResponseMiddleware 包装了响应，需要从 data 中获取
                const config = result.data || result;
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
