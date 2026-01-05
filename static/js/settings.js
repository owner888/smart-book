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
