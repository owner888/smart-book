/**
 * 助手管理模块
 */

// 助手配置（从后端加载）
let assistants = {};

// 默认助手配置（离线备用）
function getDefaultAssistants() {
    return {
        book: { name: '书籍问答助手', avatar: '📚', color: '#4caf50', systemPrompt: '我是书籍问答助手', fullSystemPrompt: '', action: 'ask' },
        continue: { name: '续写小说', avatar: '✍️', color: '#ff9800', systemPrompt: '我是小说续写助手', fullSystemPrompt: '', action: 'continue' },
        chat: { name: '通用聊天', avatar: '💬', color: '#2196f3', systemPrompt: '我是通用聊天助手', fullSystemPrompt: '', action: 'chat' },
        default: { name: 'Default Assistant', avatar: '⭐', color: '#9c27b0', systemPrompt: '我是默认助手', fullSystemPrompt: '', action: 'chat' },
    };
}

// 加载助手配置
async function loadAssistants() {
    try {
        const response = await fetch(`${ChatConfig.API_BASE}/api/assistants`);
        const result = await response.json();
        
        // 后端返回格式：{ success: true, data: { list: [...], default: '...' } }
        const data = result.data || result;
        const assistantsList = data.list || [];
        const defaultAssistant = data.default || 'chat';
        
        // 转换数组为对象格式
        for (const config of assistantsList) {
            assistants[config.id] = {
                name: config.name,
                avatar: config.avatar,
                color: config.color,
                systemPrompt: config.description,
                fullSystemPrompt: config.systemPrompt,
                action: config.action,
                useRAG: config.action === 'ask',
            };
        }
        
        // 如果还没有设置当前助手，使用API返回的默认值
        if (!ChatState.currentAssistant && assistants[defaultAssistant]) {
            ChatState.currentAssistant = defaultAssistant;
        }
        
        console.log('✅ 助手配置加载成功:', Object.keys(assistants), '默认:', defaultAssistant);
        
        // 更新初始界面
        const chatMessages = document.getElementById('chatMessages');
        const assistant = assistants[ChatState.currentAssistant];
        if (assistant && chatMessages) {
            chatMessages.innerHTML = buildWelcomeMessage(assistant);
        }
    } catch (error) {
        console.error('加载助手配置失败:', error);
        assistants = getDefaultAssistants();
    }
}

// 构建空状态引导 HTML（没有书籍时显示）
function buildEmptyState() {
    return `
        <div class="empty-state">
            <div class="empty-state-icon">📚</div>
            <div class="empty-state-title">还没有添加书籍</div>
            <div class="empty-state-desc">
                将您的电子书文件（.epub、.txt 等）放入 books 目录，然后点击下方按钮刷新书籍列表，即可开始与书籍对话。
            </div>
            <button class="empty-state-btn" onclick="ChatBooks.refreshBooks()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                    <path d="M3 3v5h5"/>
                    <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
                    <path d="M16 21h5v-5"/>
                </svg>
                刷新书籍列表
            </button>
        </div>
    `;
}

// 构建欢迎消息 HTML
function buildWelcomeMessage(assistant) {
    // 检查是否有书籍
    if (!ChatBooks.books || ChatBooks.books.length === 0) {
        return buildEmptyState();
    }
    
    return `
        <div class="message">
            <div class="message-system">
                ${assistant.systemPrompt}
                <div class="thinking-container collapsed" style="margin-top: 12px; background: linear-gradient(135deg, rgba(33, 150, 243, 0.1), rgba(3, 169, 244, 0.1)); border-color: rgba(33, 150, 243, 0.3);">
                    <div class="thinking-header" onclick="this.parentElement.classList.toggle('collapsed')" style="background: rgba(33, 150, 243, 0.15);">
                        <span class="thinking-icon">📋</span>
                        <span>系统提示词</span>
                        <span class="thinking-toggle">▼</span>
                    </div>
                    <div class="thinking-content">${ChatUtils.escapeHtml(assistant.fullSystemPrompt || '')}</div>
                </div>
            </div>
        </div>
    `;
}

// 切换助手
function switchAssistant(assistantId) {
    if (assistantId === ChatState.currentAssistant) return;
    
    const chatMessages = document.getElementById('chatMessages');
    const headerAvatar = document.getElementById('headerAvatar');
    const headerTitle = document.getElementById('headerTitle');
    
    // 保存当前助手的状态
    const prevState = ChatState.assistantStates[ChatState.currentAssistant];
    prevState.html = chatMessages.innerHTML;
    
    // 切换到新助手
    ChatState.currentAssistant = assistantId;
    const assistant = assistants[assistantId];
    const newState = ChatState.assistantStates[assistantId];
    
    // 更新 UI
    document.querySelectorAll('.assistant-item').forEach(item => {
        item.classList.toggle('active', item.dataset.assistant === assistantId);
    });
    
    headerAvatar.textContent = assistant.avatar;
    headerAvatar.style.background = assistant.color;
    headerTitle.textContent = assistant.name;
    
    // 恢复或初始化聊天内容
    if (newState.html) {
        chatMessages.innerHTML = newState.html;
    } else {
        chatMessages.innerHTML = buildWelcomeMessage(assistant);
    }
    
    // 滚动到底部
    chatMessages.scrollTop = chatMessages.scrollHeight;
    
    // 自动聚焦输入框
    const chatInput = document.getElementById('chatInput');
    setTimeout(() => chatInput?.focus(), 100);
}

// 导出
window.ChatAssistants = {
    get assistants() { return assistants; },
    getDefaultAssistants,
    loadAssistants,
    buildWelcomeMessage,
    switchAssistant
};
