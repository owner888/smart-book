/**
 * Settings 页面脚本
 */

// AI 提供商配置数据
const providers = [
    { id: 'gemini', name: 'Google Gemini', icon: '🔮', color: '#4285f4', enabled: true, apiKey: '', apiHost: 'https://generativelanguage.googleapis.com' },
    { id: 'cherryIn', name: 'CherryIN', icon: 'IN', color: '#e91e63', enabled: true, apiKey: '', apiHost: 'https://open.cherryin.net' },
    { id: 'siliconflow', name: 'SiliconFlow', icon: '⚡', color: '#9c27b0', enabled: true, apiKey: '', apiHost: 'https://api.siliconflow.cn' },
    { id: 'aihubmix', name: 'AiHubMix', icon: '🔀', color: '#ff9800', enabled: false, apiKey: '', apiHost: '' },
    { id: 'o3', name: 'O3', icon: '🌐', color: '#2196f3', enabled: false, apiKey: '', apiHost: '' },
    { id: 'ocoolai', name: 'ocoolAI', icon: '🎯', color: '#607d8b', enabled: false, apiKey: '', apiHost: '' },
    { id: 'openrouter', name: 'OpenRouter', icon: '🔗', color: '#00bcd4', enabled: false, apiKey: '', apiHost: 'https://openrouter.ai' },
    { id: 'deepseek', name: 'DeepSeek', icon: '🔍', color: '#3f51b5', enabled: false, apiKey: '', apiHost: 'https://api.deepseek.com' },
    { id: 'ollama', name: 'Ollama', icon: '🦙', color: '#795548', enabled: false, apiKey: '', apiHost: 'http://localhost:11434' },
    { id: 'lmstudio', name: 'LM Studio', icon: '🎬', color: '#009688', enabled: false, apiKey: '', apiHost: 'http://localhost:1234' },
    { id: 'aionly', name: 'AiOnly', icon: '⭐', color: '#ff5722', enabled: false, apiKey: '', apiHost: '' },
    { id: 'ppio', name: 'PPIO', icon: '🅿️', color: '#673ab7', enabled: false, apiKey: '', apiHost: '' },
    { id: 'burncloud', name: 'BurnCloud', icon: '🔥', color: '#f44336', enabled: false, apiKey: '', apiHost: '' },
    { id: 'alayanew', name: 'Alaya NeW', icon: '🌊', color: '#00acc1', enabled: false, apiKey: '', apiHost: '' },
    { id: 'infini', name: 'Infini', icon: 'ℹ️', color: '#5c6bc0', enabled: false, apiKey: '', apiHost: '' },
];

let currentProvider = null;

// 初始化
document.addEventListener('DOMContentLoaded', () => {
    initMenuNav();
    initIconNav();
    initProviders();
    initProviderSearch();
});

// 菜单导航切换
function initMenuNav() {
    document.querySelectorAll('.menu-item').forEach(item => {
        item.addEventListener('click', () => {
            const page = item.dataset.page;
            
            // 更新菜单激活状态
            document.querySelectorAll('.menu-item').forEach(m => m.classList.remove('active'));
            item.classList.add('active');
            
            // 切换页面
            document.querySelectorAll('.settings-page').forEach(p => p.classList.remove('active'));
            const targetPage = document.getElementById(`page-${page}`);
            if (targetPage) {
                targetPage.classList.add('active');
            }
        });
    });
}

// 图标导航切换
function initIconNav() {
    document.querySelectorAll('.icon-nav-item').forEach(item => {
        item.addEventListener('click', () => {
            document.querySelectorAll('.icon-nav-item').forEach(i => i.classList.remove('active'));
            item.classList.add('active');
            
            // 可以根据 section 显示不同的菜单组
            const section = item.dataset.section;
            console.log('Switch to section:', section);
        });
    });
}

// 初始化提供商列表
function initProviders() {
    const container = document.getElementById('providerItems');
    container.innerHTML = '';
    
    providers.forEach(provider => {
        const div = document.createElement('div');
        div.className = `provider-item${provider.id === 'cherryIn' ? ' active' : ''}`;
        div.dataset.id = provider.id;
        
        div.innerHTML = `
            <div class="provider-icon" style="background: ${provider.color}; color: white;">
                ${provider.icon.length > 2 ? provider.icon : `<span>${provider.icon}</span>`}
            </div>
            <div class="provider-name">${provider.name}</div>
            ${provider.enabled ? '<span class="provider-status on">ON</span>' : ''}
        `;
        
        div.addEventListener('click', () => selectProvider(provider.id));
        container.appendChild(div);
    });
    
    // 默认选中第一个启用的
    const firstEnabled = providers.find(p => p.enabled);
    if (firstEnabled) {
        selectProvider(firstEnabled.id);
    }
}

// 选择提供商
function selectProvider(providerId) {
    currentProvider = providers.find(p => p.id === providerId);
    if (!currentProvider) return;
    
    // 更新列表激活状态
    document.querySelectorAll('.provider-item').forEach(item => {
        item.classList.toggle('active', item.dataset.id === providerId);
    });
    
    // 渲染配置面板
    renderProviderConfig(currentProvider);
}

// 渲染提供商配置
function renderProviderConfig(provider) {
    const container = document.getElementById('providerConfig');
    
    const apiPreview = provider.apiHost 
        ? `Preview: ${provider.apiHost}/v1/chat/completions` 
        : '';
    
    container.innerHTML = `
        <div class="config-header">
            <div class="config-header-left">
                <h2>${provider.name}</h2>
                <a href="#" target="_blank">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                        <polyline points="15 3 21 3 21 9"/>
                        <line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                </a>
            </div>
            <div class="config-toggle ${provider.enabled ? 'active' : ''}" onclick="toggleProvider('${provider.id}')"></div>
        </div>
        
        <div class="config-section">
            <div class="config-section-header">
                <div class="config-section-title">
                    API Key
                </div>
                <div class="config-section-action">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </div>
            </div>
            <div class="config-input-wrapper">
                <input type="password" class="config-input" id="apiKeyInput" placeholder="API Key" value="${provider.apiKey}">
                <button class="config-btn" onclick="toggleApiKeyVisibility()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                </button>
                <button class="config-btn" onclick="checkApiKey()">Check</button>
            </div>
            <div class="config-hint">
                <a href="#" onclick="getApiKey('${provider.id}')">Get API Key</a>
                <span style="float: right;">Use commas to separate multiple keys</span>
            </div>
        </div>
        
        <div class="config-section">
            <div class="config-section-header">
                <div class="config-section-title">
                    API Host
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="16" x2="12" y2="12"/>
                        <line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                </div>
                <div class="config-section-action">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </div>
            </div>
            <input type="text" class="config-input" id="apiHostInput" placeholder="https://api.example.com" value="${provider.apiHost}">
            <div class="config-preview">${apiPreview}</div>
        </div>
        
        <div class="config-section">
            <div class="models-header">
                <h3>Models</h3>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-left: auto;">
                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                </svg>
            </div>
            <div class="models-hint">
                Check <a href="#">${provider.name} Docs</a> and <a href="#">Models</a> for more details
            </div>
            <div class="models-actions">
                <button class="config-btn primary" onclick="manageModels('${provider.id}')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="8" y1="6" x2="21" y2="6"/>
                        <line x1="8" y1="12" x2="21" y2="12"/>
                        <line x1="8" y1="18" x2="21" y2="18"/>
                        <line x1="3" y1="6" x2="3.01" y2="6"/>
                        <line x1="3" y1="12" x2="3.01" y2="12"/>
                        <line x1="3" y1="18" x2="3.01" y2="18"/>
                    </svg>
                    Manage
                </button>
                <button class="config-btn" onclick="addModel('${provider.id}')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Add
                </button>
            </div>
        </div>
    `;
}

// 切换提供商启用状态
function toggleProvider(providerId) {
    const provider = providers.find(p => p.id === providerId);
    if (provider) {
        provider.enabled = !provider.enabled;
        initProviders();
        selectProvider(providerId);
        saveSettings();
    }
}

// 切换 API Key 可见性
function toggleApiKeyVisibility() {
    const input = document.getElementById('apiKeyInput');
    input.type = input.type === 'password' ? 'text' : 'password';
}

// 检查 API Key
function checkApiKey() {
    const apiKey = document.getElementById('apiKeyInput').value;
    if (!apiKey) {
        layer.msg('请输入 API Key');
        return;
    }
    
    layer.msg('正在检查...');
    // TODO: 实际检查 API Key
    setTimeout(() => {
        layer.msg('✅ API Key 有效');
    }, 1000);
}

// 获取 API Key 链接
function getApiKey(providerId) {
    const urls = {
        gemini: 'https://aistudio.google.com/app/apikey',
        cherryIn: 'https://open.cherryin.net',
        openrouter: 'https://openrouter.ai/keys',
        deepseek: 'https://platform.deepseek.com/api_keys',
    };
    
    const url = urls[providerId];
    if (url) {
        window.open(url, '_blank');
    } else {
        layer.msg('请访问提供商官网获取 API Key');
    }
}

// 管理模型
function manageModels(providerId) {
    layer.open({
        type: 1,
        title: `管理 ${currentProvider.name} 模型`,
        area: ['500px', '400px'],
        shadeClose: true,
        content: `
            <div style="padding: 20px;">
                <p style="color: #a0a0a0; margin-bottom: 16px;">选择要启用的模型：</p>
                <div id="modelList" style="max-height: 280px; overflow-y: auto;">
                    <div style="padding: 12px; background: #2d2d2d; border-radius: 8px; margin-bottom: 8px;">
                        <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                            <input type="checkbox" checked> gemini-2.5-flash
                        </label>
                    </div>
                    <div style="padding: 12px; background: #2d2d2d; border-radius: 8px; margin-bottom: 8px;">
                        <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                            <input type="checkbox" checked> gemini-2.5-pro
                        </label>
                    </div>
                    <div style="padding: 12px; background: #2d2d2d; border-radius: 8px; margin-bottom: 8px;">
                        <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                            <input type="checkbox"> gemini-1.5-flash
                        </label>
                    </div>
                </div>
            </div>
        `
    });
}

// 添加模型
function addModel(providerId) {
    layer.prompt({
        title: '添加自定义模型',
        formType: 0,
        value: ''
    }, function(value, index) {
        if (value) {
            layer.msg(`已添加模型: ${value}`);
            layer.close(index);
        }
    });
}

// 提供商搜索
function initProviderSearch() {
    const searchInput = document.getElementById('providerSearch');
    searchInput.addEventListener('input', (e) => {
        const query = e.target.value.toLowerCase();
        document.querySelectorAll('.provider-item').forEach(item => {
            const name = item.querySelector('.provider-name').textContent.toLowerCase();
            item.style.display = name.includes(query) ? 'flex' : 'none';
        });
    });
}

// 保存设置到 localStorage
function saveSettings() {
    localStorage.setItem('ai_providers', JSON.stringify(providers));
}

// 加载设置
function loadSettings() {
    const saved = localStorage.getItem('ai_providers');
    if (saved) {
        const savedProviders = JSON.parse(saved);
        savedProviders.forEach(sp => {
            const provider = providers.find(p => p.id === sp.id);
            if (provider) {
                Object.assign(provider, sp);
            }
        });
    }
}

// 页面加载时恢复设置
loadSettings();

// ===================================
// MCP Servers 管理模块
// ===================================

const MCPSettings = {
    servers: [],
    currentServer: null,
    mcpClients: {},  // 存储 MCP client 实例
    
    // 初始化
    async init() {
        await this.loadServers();
        this.renderServerList();
    },
    
    // 加载服务器列表
    async loadServers() {
        try {
            const response = await fetch('/api/mcp/servers');
            const data = await response.json();
            this.servers = data.servers || [];
        } catch (error) {
            console.error('加载 MCP 服务器失败:', error);
            // 使用默认配置（HTTP/SSE 协议）
            this.servers = [
                {
                    name: 'smart-book',
                    type: 'http',
                    url: window.ChatConfig.MCP_URL,
                    enabled: true,
                    tools: ['search_book', 'get_book_info', 'list_books', 'select_book']
                }
            ];
        }
    },
    
    // 渲染服务器列表
    renderServerList() {
        const container = document.getElementById('mcpServerItems');
        if (!container) return;
        
        if (this.servers.length === 0) {
            container.innerHTML = `
                <div style="padding: 40px 20px; text-align: center; color: var(--text-secondary);">
                    <p>没有配置的 MCP 服务器</p>
                    <p style="font-size: 12px; margin-top: 8px;">点击「Add」添加新服务器</p>
                </div>
            `;
            return;
        }
        
        container.innerHTML = this.servers.map((server, index) => `
            <div class="mcp-server-item ${this.currentServer === index ? 'active' : ''}" 
                 onclick="MCPSettings.selectServer(${index})">
                <div class="mcp-server-icon">📦</div>
                <div class="mcp-server-info">
                    <div class="mcp-server-name">${server.name}</div>
                    <div class="mcp-server-type">${server.type.toUpperCase()}</div>
                </div>
                <div class="mcp-server-toggle ${server.enabled ? 'active' : ''}" 
                     onclick="MCPSettings.toggleServer(${index}, event)"></div>
            </div>
        `).join('');
    },
    
    // 选择服务器
    selectServer(index) {
        this.currentServer = index;
        this.renderServerList();
        this.renderServerConfig(this.servers[index]);
    },
    
    // 切换服务器启用状态
    toggleServer(index, event) {
        event.stopPropagation();
        this.servers[index].enabled = !this.servers[index].enabled;
        this.renderServerList();
        this.saveServers();
    },
    
    // 渲染服务器配置
    renderServerConfig(server) {
        const container = document.getElementById('mcpServerConfig');
        if (!container || !server) return;
        
        container.innerHTML = `
            <div class="mcp-config-header">
                <div class="mcp-config-title">
                    <h2>${server.name}</h2>
                </div>
                <div class="mcp-config-actions">
                    <button class="mcp-delete-btn" onclick="MCPSettings.deleteServer()">删除</button>
                    <button class="mcp-save-btn" onclick="MCPSettings.saveCurrentServer()">保存</button>
                </div>
            </div>
            
            <div class="mcp-config-form">
                <div class="mcp-form-group">
                    <label class="mcp-form-label required">Name</label>
                    <input type="text" class="mcp-form-input" id="mcpServerName" value="${server.name}">
                </div>
                
                <div class="mcp-form-group">
                    <label class="mcp-form-label">Description</label>
                    <input type="text" class="mcp-form-input" id="mcpServerDesc" 
                           value="${server.description || ''}" placeholder="Description">
                </div>
                
                <div class="mcp-form-group">
                    <label class="mcp-form-label required">Type</label>
                    <select class="mcp-form-select" id="mcpServerType">
                        <option value="stdio" ${server.type === 'stdio' ? 'selected' : ''}>Standard Input/Output (stdio)</option>
                        <option value="http" ${server.type === 'http' ? 'selected' : ''}>HTTP/SSE</option>
                    </select>
                </div>
                
                <div class="mcp-form-group">
                    <label class="mcp-form-label required">Command</label>
                    <input type="text" class="mcp-form-input" id="mcpServerCommand" 
                           value="${server.command}" placeholder="php 或 node">
                </div>
                
                <div class="mcp-form-group">
                    <label class="mcp-form-label">Arguments</label>
                    <textarea class="mcp-form-input mcp-form-textarea" id="mcpServerArgs" 
                              placeholder="每行一个参数">${(server.args || []).join('\n')}</textarea>
                    <div class="mcp-form-hint">每行一个参数</div>
                </div>
                
                <div class="mcp-form-group">
                    <label class="mcp-form-label">Environment Variables</label>
                    <textarea class="mcp-form-input mcp-form-textarea" id="mcpServerEnv" 
                              placeholder="KEY1=value1&#10;KEY2=value2">${this.envToString(server.env)}</textarea>
                    <div class="mcp-form-hint">格式: KEY=value，每行一个</div>
                </div>
            </div>
            
            ${server.tools && server.tools.length > 0 ? `
            <div class="mcp-tools-section">
                <div class="mcp-tools-header">
                    <h3>Tools (${server.tools.length})</h3>
                </div>
                ${server.tools.map(tool => `
                    <div class="mcp-tool-item">
                        <div class="mcp-tool-icon">🔧</div>
                        <div class="mcp-tool-info">
                            <div class="mcp-tool-name">${typeof tool === 'string' ? tool : tool.name}</div>
                            <div class="mcp-tool-desc">${typeof tool === 'string' ? '' : (tool.description || '')}</div>
                        </div>
                    </div>
                `).join('')}
            </div>
            ` : ''}
        `;
    },
    
    // 环境变量对象转字符串
    envToString(env) {
        if (!env) return '';
        return Object.entries(env).map(([k, v]) => `${k}=${v}`).join('\n');
    },
    
    // 字符串转环境变量对象
    stringToEnv(str) {
        if (!str) return {};
        const env = {};
        str.split('\n').forEach(line => {
            const [key, ...values] = line.split('=');
            if (key && key.trim()) {
                env[key.trim()] = values.join('=').trim();
            }
        });
        return env;
    },
    
    // 显示添加对话框
    showAddDialog() {
        layer.open({
            type: 1,
            title: '添加 MCP Server',
            area: ['500px', '500px'],
            content: `
                <div style="padding: 20px;">
                    <div class="mcp-form-group">
                        <label class="mcp-form-label required">Name</label>
                        <input type="text" class="mcp-form-input" id="newMcpName" placeholder="MCP Server">
                    </div>
                    
                    <div class="mcp-form-group">
                        <label class="mcp-form-label">Description</label>
                        <input type="text" class="mcp-form-input" id="newMcpDesc" placeholder="Description">
                    </div>
                    
                    <div class="mcp-form-group">
                        <label class="mcp-form-label required">Type</label>
                        <select class="mcp-form-select" id="newMcpType">
                            <option value="stdio">Standard Input/Output (stdio)</option>
                            <option value="http">HTTP/SSE</option>
                        </select>
                    </div>
                    
                    <div class="mcp-form-group">
                        <label class="mcp-form-label required">Command</label>
                        <input type="text" class="mcp-form-input" id="newMcpCommand" placeholder="php 或 npx">
                    </div>
                    
                    <div class="mcp-form-group">
                        <label class="mcp-form-label">Arguments</label>
                        <textarea class="mcp-form-input mcp-form-textarea" id="newMcpArgs" 
                                  placeholder="每行一个参数"></textarea>
                    </div>
                    
                    <div style="margin-top: 20px; text-align: right;">
                        <button class="config-btn" onclick="layer.closeAll()">取消</button>
                        <button class="config-btn primary" onclick="MCPSettings.addServer()" style="margin-left: 10px;">添加</button>
                    </div>
                </div>
            `
        });
    },
    
    // 添加服务器
    addServer() {
        const name = document.getElementById('newMcpName').value.trim();
        const command = document.getElementById('newMcpCommand').value.trim();
        
        if (!name || !command) {
            layer.msg('请填写名称和命令');
            return;
        }
        
        const server = {
            name: name,
            description: document.getElementById('newMcpDesc').value.trim(),
            type: document.getElementById('newMcpType').value,
            command: command,
            args: document.getElementById('newMcpArgs').value.split('\n').filter(a => a.trim()),
            enabled: true,
            tools: []
        };
        
        this.servers.push(server);
        this.saveServers();
        this.renderServerList();
        layer.closeAll();
        layer.msg('添加成功');
        
        // 选中新添加的服务器
        this.selectServer(this.servers.length - 1);
    },
    
    // 保存当前服务器配置
    saveCurrentServer() {
        if (this.currentServer === null) return;
        
        const server = this.servers[this.currentServer];
        server.name = document.getElementById('mcpServerName').value.trim();
        server.description = document.getElementById('mcpServerDesc').value.trim();
        server.type = document.getElementById('mcpServerType').value;
        server.command = document.getElementById('mcpServerCommand').value.trim();
        server.args = document.getElementById('mcpServerArgs').value.split('\n').filter(a => a.trim());
        server.env = this.stringToEnv(document.getElementById('mcpServerEnv').value);
        
        this.saveServers();
        this.renderServerList();
        layer.msg('保存成功');
    },
    
    // 删除服务器
    deleteServer() {
        if (this.currentServer === null) return;
        
        layer.confirm('确定要删除这个 MCP Server 吗？', {
            btn: ['删除', '取消']
        }, () => {
            this.servers.splice(this.currentServer, 1);
            this.currentServer = null;
            this.saveServers();
            this.renderServerList();
            
            // 清空配置面板
            const container = document.getElementById('mcpServerConfig');
            container.innerHTML = `
                <div class="mcp-config-placeholder">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/>
                        <rect x="2" y="14" width="20" height="8" rx="2" ry="2"/>
                        <line x1="6" y1="6" x2="6.01" y2="6"/>
                        <line x1="6" y1="18" x2="6.01" y2="18"/>
                    </svg>
                    <p>选择一个 MCP Server 查看配置</p>
                </div>
            `;
            
            layer.closeAll();
            layer.msg('已删除');
        });
    },
    
    // 保存到后端/localStorage
    async saveServers() {
        try {
            await fetch('/api/mcp/servers', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ servers: this.servers })
            });
        } catch (error) {
            // 保存到 localStorage 作为备份
            localStorage.setItem('mcp_servers', JSON.stringify(this.servers));
        }
    },
    
    // 测试 MCP 连接
    async testConnection(index) {
        const server = this.servers[index];
        if (!server || server.type !== 'http') {
            layer.msg('只支持 HTTP 类型的连接测试');
            return;
        }
        
        const loadingIndex = layer.load(1, { shade: [0.3, '#000'] });
        
        try {
            // 断开旧连接
            if (this.mcpClients[server.name]) {
                await this.mcpClients[server.name].disconnect();
            }
            
            // 创建新连接
            const client = new McpClient(server.url, {
                clientName: 'smart-book-settings',
                clientVersion: '1.0.0',
                debug: true
            });
            
            await client.connect();
            this.mcpClients[server.name] = client;
            
            // 获取工具列表
            const tools = await client.listTools();
            server.tools = tools;
            server.status = 'connected';
            
            layer.close(loadingIndex);
            layer.msg(`✅ 连接成功，获取到 ${tools.length} 个工具`);
            
            // 刷新界面
            this.renderServerList();
            if (this.currentServer === index) {
                this.renderServerConfig(server);
            }
            
            // 保存工具信息
            this.saveServers();
            
        } catch (error) {
            layer.close(loadingIndex);
            server.status = 'error';
            server.error = error.message;
            layer.msg(`❌ 连接失败: ${error.message}`, { icon: 2 });
            console.error('MCP 连接失败:', error);
        }
    },
    
    // 断开 MCP 连接
    async disconnectServer(index) {
        const server = this.servers[index];
        if (!server) return;
        
        if (this.mcpClients[server.name]) {
            await this.mcpClients[server.name].disconnect();
            delete this.mcpClients[server.name];
        }
        
        server.status = 'disconnected';
        this.renderServerList();
        layer.msg('已断开连接');
    },
    
    // 调用 MCP 工具
    async callTool(serverName, toolName, args = {}) {
        const client = this.mcpClients[serverName];
        if (!client || !client.isConnected) {
            throw new Error('服务器未连接');
        }
        return await client.callTool(toolName, args);
    }
};

// MCP Servers 页面激活时初始化
document.addEventListener('DOMContentLoaded', () => {
    // 监听页面切换
    document.querySelectorAll('.menu-item').forEach(item => {
        item.addEventListener('click', () => {
            if (item.dataset.page === 'mcp-servers') {
                MCPSettings.init();
            }
        });
    });
});
