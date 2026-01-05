/**
 * 模型管理模块
 */

// 模型配置列表（从后端加载）
let modelsList = [];
let currentModel = null;

// 从后端加载模型列表
async function loadModels() {
    try {
        const response = await fetch(`${ChatConfig.API_BASE}/api/models`);
        const data = await response.json();
        
        modelsList = data.models || [];
        
        // 设置默认模型
        const defaultId = data.default || 'gemini-2.5-flash';
        currentModel = modelsList.find(m => m.id === defaultId) || modelsList.find(m => m.default) || modelsList[0];
        
        // 更新 UI
        updateModelDisplay();
        
        console.log('✅ 模型列表加载完成:', modelsList.length, '个模型');
    } catch (error) {
        console.error('❌ 加载模型列表失败:', error);
        // 使用默认配置
        modelsList = [
            { id: 'gemini-2.5-flash', name: 'Gemini 2.5 Flash', provider: 'google', rate: '0.33x', default: true }
        ];
        currentModel = modelsList[0];
    }
}

// 显示模型选择菜单
function showModelSelector() {
    let menuHtml = '<div style="padding: 8px 0;">';
    
    // 按价格分组排序
    const rateOrder = ['0x', '0.33x', '1x', '3x'];
    const sortedModels = [...modelsList].sort((a, b) => {
        return rateOrder.indexOf(a.rate) - rateOrder.indexOf(b.rate);
    });
    
    sortedModels.forEach(model => {
        const isSelected = model.id === currentModel?.id;
        const isDisabled = model.disabled;
        
        const selectedStyle = isSelected ? 'background: #0066b8; color: white;' : '';
        const disabledStyle = isDisabled ? 'opacity: 0.5; cursor: not-allowed;' : 'cursor: pointer;';
        const hoverAttr = !isDisabled ? `onmouseover="this.style.background='#3d3d3d'" onmouseout="this.style.background='${isSelected ? '#0066b8' : 'transparent'}'"` : '';
        
        menuHtml += `
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; ${selectedStyle} ${disabledStyle}"
                 ${!isDisabled ? `onclick="ChatModels.selectModel('${model.id}')"` : ''}
                 ${hoverAttr}>
                <div style="display: flex; align-items: center; gap: 8px;">
                    ${isSelected ? '<span style="width: 16px;">✓</span>' : '<span style="width: 16px;"></span>'}
                    <span>${model.name}</span>
                    ${isDisabled ? '<span style="font-size: 11px; opacity: 0.7;">(即将支持)</span>' : ''}
                </div>
                <span style="font-size: 12px; opacity: 0.7;">${model.rate}</span>
            </div>
        `;
    });
    
    // 分隔线
    menuHtml += '<div style="border-top: 1px solid #404040; margin: 8px 0;"></div>';
    
    // 管理模型链接
    menuHtml += `
        <div style="padding: 10px 16px; color: #569cd6; cursor: pointer;" 
             onclick="ChatModels.showManageModels()"
             onmouseover="this.style.background='#3d3d3d'" 
             onmouseout="this.style.background='transparent'">
            Manage Models...
        </div>
    `;
    
    menuHtml += '</div>';
    
    layui.layer.open({
        type: 1,
        title: false,
        closeBtn: 0,
        shadeClose: true,
        shade: 0.3,
        area: ['280px', 'auto'],
        offset: ['60px', '350px'],
        skin: 'model-selector-layer',
        content: menuHtml
    });
}

// 选择模型
function selectModel(modelId) {
    const model = modelsList.find(m => m.id === modelId);
    if (!model || model.disabled) return;
    
    currentModel = model;
    
    // 更新 UI
    updateModelDisplay();
    
    layer.closeAll();
    layer.msg(`🤖 已切换到: ${model.name}`);
}

// 更新模型显示
function updateModelDisplay() {
    const modelSelector = document.querySelector('.model-selector');
    if (modelSelector && currentModel) {
        const spans = modelSelector.querySelectorAll('span');
        if (spans.length >= 2) {
            spans[1].textContent = currentModel.name;
        }
    }
}

// 获取当前模型
function getCurrentModel() {
    return currentModel;
}

// 获取当前模型 ID（用于 API 请求）
function getCurrentModelId() {
    return currentModel?.id || 'gemini-2.5-flash';
}

// 显示管理模型对话框
function showManageModels() {
    layer.closeAll();
    
    let modelsHtml = '<div style="padding: 16px;">';
    modelsHtml += '<div style="margin-bottom: 16px; color: #888;">管理你的 AI 模型配置</div>';
    
    modelsList.forEach(model => {
        const statusColor = model.disabled ? '#f44336' : '#4caf50';
        const statusText = model.disabled ? '未配置' : '已启用';
        
        modelsHtml += `
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px; margin-bottom: 8px; background: #2d2d2d; border-radius: 8px;">
                <div>
                    <div style="font-weight: bold;">${model.name}</div>
                    <div style="font-size: 12px; color: #888;">Provider: ${model.provider}</div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 12px; color: ${statusColor};">● ${statusText}</span>
                    <span style="font-size: 12px; background: #404040; padding: 2px 8px; border-radius: 4px;">${model.rate}</span>
                </div>
            </div>
        `;
    });
    
    modelsHtml += `
        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #404040;">
            <div style="color: #888; font-size: 13px;">
                💡 提示: 更多模型需要在 <a href="/pages/settings.html" style="color: #569cd6;">设置页面</a> 配置 API Key
            </div>
        </div>
    `;
    
    modelsHtml += '</div>';
    
    layui.layer.open({
        type: 1,
        title: '⚙️ Manage Models',
        area: ['400px', 'auto'],
        shadeClose: true,
        content: modelsHtml
    });
}

// 导出
window.ChatModels = {
    loadModels,
    getCurrentModel,
    getCurrentModelId,
    selectModel,
    showModelSelector,
    showManageModels,
    updateModelDisplay,
    get modelsList() { return modelsList; }
};
