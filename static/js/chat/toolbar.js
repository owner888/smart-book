/**
 * 工具栏模块
 */

// RAG 开关状态（默认关闭，使用 AI 预训练知识）
let ragEnabled = false;
// 关键词权重（0-1，0=纯向量，1=纯关键词，默认0.5=混合）
let keywordWeight = 0.5;

// 切换 RAG 开关（关闭时显示设置，开启时直接关闭）
function toggleRAG() {
    if (ragEnabled) {
        // 已开启，直接关闭
        ragEnabled = false;
        const btn = document.getElementById('ragToggle');
        if (btn) {
            btn.classList.remove('active');
            btn.title = 'RAG 检索 (已关闭)';
        }
        layer.msg('🤖 RAG 检索已关闭 - 使用 AI 预训练知识');
    } else {
        // 未开启，显示设置面板让用户选择
        showRAGSettings();
    }
}

// 显示 RAG 设置面板
function showRAGSettings() {
    const weights = [
        { value: 0, name: '纯向量搜索', desc: '使用语义相似度' },
        { value: 0.3, name: '向量为主', desc: '70% 向量 + 30% 关键词' },
        { value: 0.5, name: '均衡混合', desc: '50% 向量 + 50% 关键词' },
        { value: 0.7, name: '关键词为主', desc: '30% 向量 + 70% 关键词' },
        { value: 1, name: '纯关键词', desc: '使用关键词匹配' },
    ];
    
    const items = weights.map(w => {
        const isSelected = w.value === keywordWeight;
        const style = isSelected 
            ? 'background: var(--accent-green); color: white;' 
            : 'background: var(--bg-tertiary);';
        return `
            <div style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; margin-bottom: 8px; border-radius: 8px; cursor: pointer; ${style}" 
                 onclick="ChatToolbar.setKeywordWeight(${w.value})">
                <span style="flex: 1;">
                    <div style="font-size: 14px; font-weight: 500;">${w.name}</div>
                    <div style="font-size: 12px; opacity: 0.7;">${w.desc}</div>
                </span>
                ${isSelected ? '<span>✓</span>' : ''}
            </div>
        `;
    }).join('');
    
    layui.layer.open({
        type: 1,
        title: '⚙️ RAG 搜索设置',
        area: ['340px', 'auto'],
        shadeClose: true,
        content: `<div style="padding: 16px;">${items}</div>`
    });
}

// 设置关键词权重并激活 RAG
function setKeywordWeight(weight) {
    keywordWeight = weight;
    ragEnabled = true;  // 选择后自动激活 RAG
    
    const btn = document.getElementById('ragToggle');
    if (btn) {
        btn.classList.add('active');
        btn.title = 'RAG 检索 (已开启)';
    }
    
    layer.closeAll();
    const pct = Math.round(weight * 100);
    layer.msg(`📚 RAG 已开启 - ${pct}% 关键词 + ${100 - pct}% 向量`);
}

// 获取 RAG 状态
function getRAGConfig() {
    return {
        enabled: ragEnabled,
        keywordWeight: keywordWeight
    };
}

// 搜索引擎配置
const searchEngines = [
    { id: 'google', name: 'Google', icon: 'G', free: true },
    { id: 'mcp', name: 'MCP 工具', icon: '🔧', free: true },
    { id: 'off', name: '关闭搜索', icon: '⊘', free: true },
];
let currentSearchEngine = 'google';

// 点击搜索按钮显示选择菜单
function toggleWebSearch() {
    const menuItems = searchEngines.map(engine => {
        const isSelected = engine.id === currentSearchEngine;
        const style = isSelected 
            ? 'background: var(--accent-green); color: white;' 
            : 'background: var(--bg-tertiary);';
        return `
            <div style="display: flex; align-items: center; gap: 12px; padding: 14px 16px; margin-bottom: 8px; border-radius: 8px; cursor: pointer; ${style}" 
                 onclick="ChatToolbar.selectSearchEngine('${engine.id}')" 
                 onmouseover="this.style.opacity='0.85'" 
                 onmouseout="this.style.opacity='1'">
                <span style="font-size: 18px;">${engine.icon}</span>
                <span style="flex: 1; font-size: 15px;">${engine.name}</span>
                <span style="font-size: 12px; opacity: 0.7;">Free</span>
            </div>
        `;
    }).join('');
    
    layui.layer.open({
        type: 1,
        title: '🌐 选择搜索引擎',
        area: ['340px', 'auto'],
        shadeClose: true,
        content: `<div style="padding: 16px;">${menuItems}</div>`
    });
}

// 选择搜索引擎
function selectSearchEngine(engineId) {
    currentSearchEngine = engineId;
    const engine = searchEngines.find(e => e.id === engineId);
    
    const btn = document.querySelector('.toolbar-icon[title="网页搜索"]');
    if (btn) btn.classList.toggle('active', engineId !== 'off');
    
    layer.closeAll();
    layer.msg(`🌐 已切换到: ${engine?.name || engineId}`);
}

// 获取搜索状态
function getSearchConfig() {
    return {
        enabled: currentSearchEngine !== 'off',
        engine: currentSearchEngine
    };
}

// 显示 AI 工具菜单
function showAITools() {
    layui.layer.open({
        type: 1,
        title: 'AI 工具',
        area: ['300px', '250px'],
        shadeClose: true,
        content: `
            <div style="padding: 20px;">
                <div style="margin-bottom: 12px; padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="ChatUtils.insertPrompt('请帮我总结这段内容')">📝 内容总结</div>
                <div style="margin-bottom: 12px; padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="ChatUtils.insertPrompt('请帮我翻译成英文')">🌍 翻译文本</div>
                <div style="margin-bottom: 12px; padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="ChatUtils.insertPrompt('请帮我解释这段代码')">💻 解释代码</div>
                <div style="padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="ChatUtils.insertPrompt('请帮我改写这段文字，使其更加正式')">✏️ 改写文本</div>
            </div>
        `
    });
}

// 全屏切换
function toggleFullscreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen();
        layer.msg('⛶ 已进入全屏模式');
    } else {
        document.exitFullscreen();
        layer.msg('⛶ 已退出全屏模式');
    }
}

// 导出
window.ChatToolbar = {
    searchEngines,
    toggleWebSearch,
    selectSearchEngine,
    getSearchConfig,
    showAITools,
    toggleFullscreen,
    toggleRAG,
    getRAGConfig,
    showRAGSettings,
    setKeywordWeight,
    get ragEnabled() { return ragEnabled; },
    get keywordWeight() { return keywordWeight; }
};

// 全局函数
window.toggleRAG = toggleRAG;
window.showRAGSettings = showRAGSettings;
