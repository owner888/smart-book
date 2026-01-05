/**
 * 短语管理模块
 */

// 默认短语
const defaultPhrases = [
    { id: 'default_1', title: '大闹天宫', content: '孙悟空大闹天宫的经过', icon: '🐵', scope: 'global' },
    { id: 'default_2', title: '师徒四人', content: '介绍一下唐僧师徒四人', icon: '👨‍👩‍👦‍👦', scope: 'global' },
    { id: 'default_3', title: '著名妖怪', content: '西游记中有哪些著名的妖怪', icon: '👹', scope: 'global' },
    { id: 'default_4', title: '现代穿越', content: '续写一个唐僧师徒穿越到现代的章节', icon: '✍️', scope: 'global' },
    { id: 'default_5', title: '诗词总结', content: '以诗词形式总结西游记的主题', icon: '📜', scope: 'global' },
];

// 加载短语
function loadPhrases() {
    try {
        const saved = localStorage.getItem(ChatConfig.PHRASES_STORAGE_KEY);
        if (saved) return JSON.parse(saved);
    } catch (e) {}
    return [...defaultPhrases];
}

// 保存短语
function savePhrases(phrases) {
    localStorage.setItem(ChatConfig.PHRASES_STORAGE_KEY, JSON.stringify(phrases));
}

// 显示快捷指令
function showQuickCommands() {
    const phrases = loadPhrases();
    const globalPhrases = phrases.filter(p => p.scope === 'global');
    const assistantPhrases = phrases.filter(p => p.scope === 'assistant' && p.assistantId === ChatState.currentAssistant);
    
    const renderPhraseItem = (p) => `
        <div class="phrase-item" style="display: flex; align-items: center; margin-bottom: 10px; padding: 12px; background: var(--bg-tertiary); border-radius: 8px; cursor: pointer;">
            <span style="flex: 1; display: flex; align-items: center; gap: 8px;" onclick="ChatPhrases.usePhrase('${p.id}')">
                <span>${p.icon || '⚡'}</span>
                <span>${ChatUtils.escapeHtml(p.title)}</span>
            </span>
            ${!p.id.startsWith('default_') ? `
                <span class="phrase-actions" style="display: flex; gap: 8px;">
                    <span onclick="ChatPhrases.editPhrase('${p.id}')" style="cursor: pointer; opacity: 0.6;" title="编辑">✏️</span>
                    <span onclick="ChatPhrases.deletePhrase('${p.id}')" style="cursor: pointer; opacity: 0.6;" title="删除">🗑️</span>
                </span>
            ` : ''}
        </div>
    `;
    
    layui.layer.open({
        type: 1,
        title: '⚡ 快捷指令',
        area: ['400px', 'auto'],
        maxHeight: 500,
        shadeClose: true,
        content: `
            <div style="padding: 16px;">
                <div style="margin-bottom: 16px;">
                    <button onclick="ChatPhrases.showAddPhraseDialog()" style="width: 100%; padding: 12px; background: var(--accent-green); color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px;">
                        ➕ 添加新短语
                    </button>
                </div>
                <div style="margin-bottom: 16px;">
                    <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 8px;">⚡ 全局短语</div>
                    ${globalPhrases.map(renderPhraseItem).join('')}
                </div>
                ${assistantPhrases.length > 0 ? `
                    <div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 8px;">🤖 助手专属</div>
                        ${assistantPhrases.map(renderPhraseItem).join('')}
                    </div>
                ` : ''}
            </div>
        `
    });
}

// 使用短语
function usePhrase(phraseId) {
    const phrases = loadPhrases();
    const phrase = phrases.find(p => p.id === phraseId);
    if (phrase) {
        const variables = phrase.content.match(/\$\{(\w+)\}/g);
        if (variables && variables.length > 0) {
            showVariableInputDialog(phrase);
        } else {
            ChatUtils.insertPrompt(phrase.content);
            layer.closeAll();
        }
    }
}

// 显示变量输入对话框
function showVariableInputDialog(phrase) {
    const variables = [...new Set(phrase.content.match(/\$\{(\w+)\}/g))];
    const varNames = variables.map(v => v.replace(/\$\{|\}/g, ''));
    
    const inputFields = varNames.map((name) => `
        <div style="margin-bottom: 12px;">
            <label style="display: block; font-size: 12px; color: var(--text-secondary); margin-bottom: 4px;">\${${name}}</label>
            <input type="text" id="var_${name}" class="layui-input" placeholder="请输入 ${name}" style="background: var(--bg-tertiary); border: 1px solid var(--border-color); color: var(--text-primary); padding: 8px 12px; border-radius: 6px; width: 100%;">
        </div>
    `).join('');
    
    layui.layer.open({
        type: 1,
        title: `📝 填写变量 - ${phrase.title}`,
        area: ['360px', 'auto'],
        btn: ['确定', '取消'],
        content: `<div style="padding: 16px;">${inputFields}</div>`,
        yes: function(index) {
            let content = phrase.content;
            varNames.forEach(name => {
                const value = document.getElementById(`var_${name}`).value || name;
                content = content.replace(new RegExp(`\\$\\{${name}\\}`, 'g'), value);
            });
            ChatUtils.insertPrompt(content);
            layer.closeAll();
        }
    });
}

// 显示添加短语对话框
function showAddPhraseDialog(editPhrase = null) {
    const isEdit = editPhrase !== null;
    
    layui.layer.open({
        type: 1,
        title: isEdit ? '✏️ 编辑短语' : '➕ 添加短语',
        area: ['420px', 'auto'],
        btn: ['确定', '取消'],
        content: `
            <div style="padding: 20px;">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 14px; margin-bottom: 6px;">标题</label>
                    <input type="text" id="phrase_title" class="layui-input" placeholder="请输入短语标题" value="${isEdit ? ChatUtils.escapeHtml(editPhrase.title) : ''}" style="background: var(--bg-tertiary); border: 1px solid var(--border-color); color: var(--text-primary); padding: 10px 12px; border-radius: 6px; width: 100%;">
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 14px; margin-bottom: 6px;">内容</label>
                    <textarea id="phrase_content" class="layui-textarea" placeholder="请输入短语内容，支持变量如 \${from}、\${to}" style="background: var(--bg-tertiary); border: 1px solid var(--border-color); color: var(--text-primary); padding: 10px 12px; border-radius: 6px; width: 100%; min-height: 120px; resize: vertical;">${isEdit ? ChatUtils.escapeHtml(editPhrase.content) : ''}</textarea>
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 14px; margin-bottom: 6px;">图标（可选）</label>
                    <input type="text" id="phrase_icon" class="layui-input" placeholder="输入一个 emoji，如 🚀" value="${isEdit ? (editPhrase.icon || '') : ''}" style="background: var(--bg-tertiary); border: 1px solid var(--border-color); color: var(--text-primary); padding: 10px 12px; border-radius: 6px; width: 100%;">
                </div>
                <div>
                    <label style="display: block; font-size: 14px; margin-bottom: 10px;">添加位置</label>
                    <div style="display: flex; gap: 20px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="radio" name="phrase_scope" value="global" ${!isEdit || editPhrase.scope === 'global' ? 'checked' : ''}>
                            <span>⚡ 全局短语</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="radio" name="phrase_scope" value="assistant" ${isEdit && editPhrase.scope === 'assistant' ? 'checked' : ''}>
                            <span>🤖 助手专属</span>
                        </label>
                    </div>
                </div>
            </div>
        `,
        yes: function(index) {
            const title = document.getElementById('phrase_title').value.trim();
            const content = document.getElementById('phrase_content').value.trim();
            const icon = document.getElementById('phrase_icon').value.trim() || '⚡';
            const scope = document.querySelector('input[name="phrase_scope"]:checked').value;
            
            if (!title) { layer.msg('请输入标题'); return; }
            if (!content) { layer.msg('请输入内容'); return; }
            
            const phrases = loadPhrases();
            
            if (isEdit) {
                const idx = phrases.findIndex(p => p.id === editPhrase.id);
                if (idx !== -1) {
                    phrases[idx] = { ...phrases[idx], title, content, icon, scope, assistantId: scope === 'assistant' ? ChatState.currentAssistant : null };
                }
            } else {
                phrases.push({
                    id: 'custom_' + Date.now(),
                    title, content, icon, scope,
                    assistantId: scope === 'assistant' ? ChatState.currentAssistant : null
                });
            }
            
            savePhrases(phrases);
            layer.closeAll();
            layer.msg(isEdit ? '✅ 短语已更新' : '✅ 短语已添加');
            setTimeout(() => showQuickCommands(), 300);
        }
    });
}

// 编辑短语
function editPhrase(phraseId) {
    const phrases = loadPhrases();
    const phrase = phrases.find(p => p.id === phraseId);
    if (phrase) {
        layer.closeAll();
        setTimeout(() => showAddPhraseDialog(phrase), 200);
    }
}

// 删除短语
function deletePhrase(phraseId) {
    layer.confirm('确定要删除这个短语吗？', {
        btn: ['删除', '取消'],
        title: '删除短语'
    }, function(index) {
        const phrases = loadPhrases();
        const newPhrases = phrases.filter(p => p.id !== phraseId);
        savePhrases(newPhrases);
        layer.closeAll();
        layer.msg('🗑️ 短语已删除');
        setTimeout(() => showQuickCommands(), 300);
    });
}

// 导出
window.ChatPhrases = {
    loadPhrases,
    savePhrases,
    showQuickCommands,
    usePhrase,
    showAddPhraseDialog,
    editPhrase,
    deletePhrase
};
