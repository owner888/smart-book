/**
 * AI 书籍助手 - 主脚本
 */

// 配置
const API_BASE = 'http://localhost:8088';

// 当前状态
let currentAssistant = 'book';
let isLoading = false;
let currentMessageDiv = null;
let currentContent = '';
let currentThinking = '';
let currentSources = null;
let currentSummaryInfo = null;
let abortController = null;

// 每个助手独立的状态存储
const assistantStates = {
    book: { history: [], chatId: generateChatId(), html: null },
    continue: { history: [], chatId: generateChatId(), html: null },
    chat: { history: [], chatId: generateChatId(), html: null },
    default: { history: [], chatId: generateChatId(), html: null },
};

// 获取当前助手的状态
function getCurrentState() {
    return assistantStates[currentAssistant];
}

// 生成 Chat ID
function generateChatId() {
    return 'chat_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
}

// 助手配置
const assistants = {
    book: {
        name: '书籍问答助手',
        avatar: '📚',
        color: '#4caf50',
        systemPrompt: '我是书籍问答助手，可以帮你分析《西游记》的内容。你可以问我关于书中人物、情节、主题等问题。',
        action: 'ask',
        useRAG: true
    },
    continue: {
        name: '续写小说',
        avatar: '✍️',
        color: '#ff9800',
        systemPrompt: '我是小说续写助手，擅长模仿《西游记》的章回体风格续写故事。告诉我你想要的情节设定，我会为你创作新章节。',
        action: 'continue',
        useRAG: false
    },
    chat: {
        name: '通用聊天',
        avatar: '💬',
        color: '#2196f3',
        systemPrompt: '我是通用聊天助手，可以和你讨论任何话题。',
        action: 'chat',
        useRAG: false
    },
    default: {
        name: 'Default Assistant',
        avatar: '⭐',
        color: '#9c27b0',
        systemPrompt: '我是默认助手，有什么可以帮你的吗？',
        action: 'chat',
        useRAG: false
    }
};

// DOM 元素
let chatMessages, chatInput, sendBtn, headerAvatar, headerTitle, systemPrompt;
let sidebar, sidebarToggle, sidebarOverlay;

// 初始化
document.addEventListener('DOMContentLoaded', () => {
    // 获取 DOM 元素
    chatMessages = document.getElementById('chatMessages');
    chatInput = document.getElementById('chatInput');
    sendBtn = document.getElementById('sendBtn');
    headerAvatar = document.getElementById('headerAvatar');
    headerTitle = document.getElementById('headerTitle');
    systemPrompt = document.getElementById('systemPrompt');
    
    // 移动端侧边栏元素
    sidebar = document.getElementById('sidebar');
    sidebarToggle = document.getElementById('sidebarToggle');
    sidebarOverlay = document.getElementById('sidebarOverlay');
    
    // 初始化移动端侧边栏
    initMobileSidebar();
    
    // 切换助手
    document.querySelectorAll('.assistant-item').forEach(item => {
        item.addEventListener('click', () => {
            const assistant = item.dataset.assistant;
            switchAssistant(assistant);
        });
    });
    
    // 发送消息
    sendBtn.addEventListener('click', sendMessage);
    chatInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
    
    // 自动调整输入框高度
    chatInput.addEventListener('input', () => {
        chatInput.style.height = 'auto';
        chatInput.style.height = Math.min(chatInput.scrollHeight, 200) + 'px';
    });
    
    // 标签切换
    document.querySelectorAll('.sidebar-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.sidebar-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
        });
    });
    
    // 首次加载时自动聚焦输入框
    setTimeout(() => chatInput.focus(), 100);
});

// 切换助手
function switchAssistant(assistantId) {
    if (assistantId === currentAssistant) return;
    
    // 保存当前助手的状态
    const prevState = assistantStates[currentAssistant];
    prevState.html = chatMessages.innerHTML;
    
    // 切换到新助手
    currentAssistant = assistantId;
    const assistant = assistants[assistantId];
    const newState = assistantStates[assistantId];
    
    // 更新 UI
    document.querySelectorAll('.assistant-item').forEach(item => {
        item.classList.toggle('active', item.dataset.assistant === assistantId);
    });
    
    headerAvatar.textContent = assistant.avatar;
    headerAvatar.style.background = assistant.color;
    headerTitle.textContent = assistant.name;
    systemPrompt.textContent = assistant.systemPrompt;
    
    // 恢复或初始化聊天内容
    if (newState.html) {
        chatMessages.innerHTML = newState.html;
    } else {
        chatMessages.innerHTML = `
            <div class="message">
                <div class="message-system">${assistant.systemPrompt}</div>
            </div>
        `;
    }
    
    // 滚动到底部
    chatMessages.scrollTop = chatMessages.scrollHeight;
    
    // 自动聚焦输入框
    setTimeout(() => chatInput.focus(), 100);
}

// 发送消息（SSE 流式）
async function sendMessage() {
    const message = chatInput.value.trim();
    if (!message || isLoading) return;
    
    isLoading = true;
    sendBtn.disabled = true;
    chatInput.value = '';
    chatInput.style.height = 'auto';
    
    // 添加用户消息
    addMessage('user', message);
    getCurrentState().history.push({ role: 'user', content: message });
    
    // 重置流式状态
    currentContent = '';
    currentThinking = '';
    currentSources = null;
    currentSummaryInfo = null;
    
    // 创建空的助手消息容器
    const assistant = assistants[currentAssistant];
    currentMessageDiv = document.createElement('div');
    currentMessageDiv.className = 'message message-assistant';
    currentMessageDiv.innerHTML = `
        <div class="message-avatar" style="background: ${assistant.color};">${assistant.avatar}</div>
        <div class="message-content">
            <div class="typing-indicator">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
            </div>
        </div>
    `;
    chatMessages.appendChild(currentMessageDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    
    // 构建请求（使用 Chat ID，传递 search 开关状态）
    let url, body;
    if (assistant.action === 'ask') {
        url = `${API_BASE}/api/stream/ask`;
        body = { question: message, chat_id: getCurrentState().chatId, search: webSearchEnabled };
    } else if (assistant.action === 'continue') {
        url = `${API_BASE}/api/stream/continue`;
        body = { prompt: message, search: webSearchEnabled };
    } else {
        url = `${API_BASE}/api/stream/chat`;
        body = { message: message, chat_id: getCurrentState().chatId, search: webSearchEnabled };
    }
    
    // 使用 fetch + SSE
    try {
        abortController = new AbortController();
        
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            signal: abortController.signal
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            
            buffer += decoder.decode(value, { stream: true });
            
            // 解析 SSE 事件
            const lines = buffer.split('\n');
            buffer = lines.pop() || '';
            
            let currentEvent = null;
            let dataLines = [];
            for (const line of lines) {
                if (line.startsWith('event: ')) {
                    currentEvent = line.slice(7);
                    dataLines = [];
                } else if (line.startsWith('data: ')) {
                    dataLines.push(line.slice(6));
                } else if (line === '' && currentEvent && dataLines.length > 0) {
                    // 空行表示事件结束，合并所有 data 行
                    const data = dataLines.join('\n');
                    
                    if (currentEvent === 'sources') {
                        try {
                            currentSources = JSON.parse(data);
                        } catch (e) {}
                    } else if (currentEvent === 'summary_used') {
                        // 使用了上下文摘要 - 保存信息用于显示
                        try {
                            currentSummaryInfo = JSON.parse(data);
                        } catch (e) {
                            currentSummaryInfo = { rounds_summarized: 0, recent_messages: 0 };
                        }
                    } else if (currentEvent === 'cached') {
                        // 语义缓存命中提示
                        try {
                            const cacheInfo = JSON.parse(data);
                            if (cacheInfo.hit) {
                                layer.msg(`📦 语义缓存命中！\n原问题: "${cacheInfo.original_question}"`, { time: 2500 });
                            }
                        } catch (e) {
                            layer.msg('📦 来自缓存，秒回！', { time: 1500 });
                        }
                    } else if (currentEvent === 'thinking') {
                        // AI 思考过程
                        currentThinking += data;
                        updateStreamingMessage();
                    } else if (currentEvent === 'content') {
                        currentContent += data;
                        updateStreamingMessage();
                    } else if (currentEvent === 'error') {
                        // 服务端错误
                        currentContent = `❌ 服务端错误: ${data}`;
                        finishStreamingMessage(true);
                    } else if (currentEvent === 'done') {
                        finishStreamingMessage();
                    }
                    currentEvent = null;
                }
            }
        }
        
        // 处理缓冲区剩余内容
        if (buffer.trim()) {
            finishStreamingMessage();
        }
        
    } catch (error) {
        if (error.name === 'AbortError') {
            currentContent += '\n\n⏹️ 已停止生成';
        } else {
            currentContent = `❌ 请求失败: ${error.message}\n\n请确保 Workerman 服务已启动:\n\`php workerman_ai_server.php start\``;
        }
        finishStreamingMessage(error.name !== 'AbortError');
    } finally {
        isLoading = false;
        sendBtn.disabled = false;
        abortController = null;
    }
}

// 更新流式消息显示
function updateStreamingMessage() {
    if (!currentMessageDiv) return;
    
    const contentDiv = currentMessageDiv.querySelector('.message-content');
    
    // 构建思考过程 HTML
    let thinkingHtml = '';
    if (currentThinking) {
        thinkingHtml = `
            <div class="thinking-container">
                <div class="thinking-header" onclick="this.parentElement.classList.toggle('collapsed')">
                    <span class="thinking-icon">🧠</span>
                    <span>Thinking...</span>
                    <span class="thinking-toggle">▼</span>
                </div>
                <div class="thinking-content">${escapeHtml(currentThinking)}</div>
            </div>
        `;
    }
    
    // 渲染 Markdown（实时）
    const htmlContent = currentContent ? marked.parse(currentContent) : '';
    contentDiv.innerHTML = thinkingHtml + htmlContent;
    
    // 滚动到底部
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// 完成流式消息
function finishStreamingMessage(isError = false) {
    if (!currentMessageDiv) return;
    
    const contentDiv = currentMessageDiv.querySelector('.message-content');
    
    // 构建思考过程 HTML（可折叠，默认收起）
    let thinkingHtml = '';
    if (currentThinking) {
        thinkingHtml = `
            <div class="thinking-container collapsed">
                <div class="thinking-header" onclick="this.parentElement.classList.toggle('collapsed')">
                    <span class="thinking-icon">🧠</span>
                    <span>已完成思考</span>
                    <span class="thinking-toggle">▶</span>
                </div>
                <div class="thinking-content">${escapeHtml(currentThinking)}</div>
            </div>
        `;
    }
    
    // 渲染最终内容
    let htmlContent = isError 
        ? escapeHtml(currentContent).replace(/\n/g, '<br>') 
        : marked.parse(currentContent);
    
    // 将 code 标签中的 URL 转为可点击链接
    htmlContent = makeUrlsClickable(htmlContent);
    
    // 添加上下文摘要信息
    let summaryHtml = '';
    if (currentSummaryInfo) {
        summaryHtml = `
            <div class="sources-container" style="border-left-color: #9c27b0;">
                <div class="sources-title">📝 上下文摘要</div>
                <div class="source-item" style="background: rgba(156, 39, 176, 0.1);">
                    已压缩 <strong>${currentSummaryInfo.rounds_summarized}</strong> 轮历史对话 + 保留最近 <strong>${currentSummaryInfo.recent_messages}</strong> 轮
                </div>
            </div>
        `;
    }
    
    // 添加检索来源
    let sourcesHtml = '';
    if (currentSources && currentSources.length > 0) {
        sourcesHtml = `
            <div class="sources-container">
                <div class="sources-title">📚 检索来源 (${currentSources.length})</div>
                ${currentSources.slice(0, 3).map(s => `
                    <div class="source-item">
                        <span class="source-score">${s.score}%</span>
                        ${escapeHtml(s.text)}
                    </div>
                `).join('')}
            </div>
        `;
    }
    
    contentDiv.innerHTML = thinkingHtml + htmlContent + summaryHtml + sourcesHtml;
    
    // 保存到历史
    if (!isError) {
        getCurrentState().history.push({ role: 'assistant', content: currentContent });
    }
    
    // 重置状态
    currentMessageDiv = null;
    currentContent = '';
    currentSources = null;
    
    // 滚动到底部
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// 添加消息
function addMessage(role, content, sources = null, isError = false) {
    const assistant = assistants[currentAssistant];
    const messageDiv = document.createElement('div');
    messageDiv.className = `message message-${role}`;
    
    if (role === 'user') {
        messageDiv.innerHTML = `
            <div class="message-content">${escapeHtml(content)}</div>
        `;
    } else {
        const htmlContent = isError ? escapeHtml(content).replace(/\n/g, '<br>') : marked.parse(content);
        let sourcesHtml = '';
        
        if (sources && sources.length > 0) {
            sourcesHtml = `
                <div class="sources-container">
                    <div class="sources-title">📚 检索来源 (${sources.length})</div>
                    ${sources.slice(0, 3).map(s => `
                        <div class="source-item">
                            <span class="source-score">${s.score}%</span>
                            ${escapeHtml(s.text)}
                        </div>
                    `).join('')}
                </div>
            `;
        }
        
        messageDiv.innerHTML = `
            <div class="message-avatar" style="background: ${assistant.color};">${assistant.avatar}</div>
            <div class="message-content">
                ${htmlContent}
                ${sourcesHtml}
            </div>
        `;
    }
    
    chatMessages.appendChild(messageDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// 添加加载消息
function addLoadingMessage() {
    const id = 'loading-' + Date.now();
    const assistant = assistants[currentAssistant];
    const messageDiv = document.createElement('div');
    messageDiv.className = 'message message-assistant';
    messageDiv.id = id;
    messageDiv.innerHTML = `
        <div class="message-avatar" style="background: ${assistant.color};">${assistant.avatar}</div>
        <div class="message-content">
            <div class="typing-indicator">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
            </div>
        </div>
    `;
    chatMessages.appendChild(messageDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    return id;
}

// 移除加载消息
function removeLoadingMessage(id) {
    const element = document.getElementById(id);
    if (element) element.remove();
}

// HTML 转义
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// 将 code 标签中的 URL 转为可点击链接
function makeUrlsClickable(html) {
    // 匹配 <code> 标签中的 URL
    const urlPattern = /<code>(https?:\/\/[^\s<]+)<\/code>/gi;
    return html.replace(urlPattern, (match, url) => {
        return `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`;
    });
}

// ===== 工具栏功能 =====

// 显示提示（使用 layui.layer.msg 无图标模式）
function showTip(feature) {
    layer.msg(`🔧 ${feature} 功能开发中...`);
}

// 切换网页搜索（默认开启）
let webSearchEnabled = true;
function toggleWebSearch() {
    webSearchEnabled = !webSearchEnabled;
    const btn = event.currentTarget;
    btn.classList.toggle('active', webSearchEnabled);
    layer.msg(webSearchEnabled ? '🌐 网页搜索已开启' : '🌐 网页搜索已关闭');
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
                <div style="margin-bottom: 12px; padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="insertPrompt('请帮我总结这段内容')">📝 内容总结</div>
                <div style="margin-bottom: 12px; padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="insertPrompt('请帮我翻译成英文')">🌍 翻译文本</div>
                <div style="margin-bottom: 12px; padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="insertPrompt('请帮我解释这段代码')">💻 解释代码</div>
                <div style="padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="insertPrompt('请帮我改写这段文字，使其更加正式')">✏️ 改写文本</div>
            </div>
        `
    });
}

// 显示快捷指令
function showQuickCommands() {
    layui.layer.open({
        type: 1,
        title: '⚡ 快捷指令',
        area: ['350px', '300px'],
        shadeClose: true,
        content: `
            <div style="padding: 20px;">
                <div style="margin-bottom: 12px; padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="insertPrompt('孙悟空大闹天宫的经过')">🐵 大闹天宫</div>
                <div style="margin-bottom: 12px; padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="insertPrompt('介绍一下唐僧师徒四人')">👨‍👩‍👦‍👦 师徒四人</div>
                <div style="margin-bottom: 12px; padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="insertPrompt('西游记中有哪些著名的妖怪')">👹 著名妖怪</div>
                <div style="margin-bottom: 12px; padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="insertPrompt('续写一个唐僧师徒穿越到现代的章节')">✍️ 现代穿越</div>
                <div style="padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="insertPrompt('以诗词形式总结西游记的主题')">📜 诗词总结</div>
            </div>
        `
    });
}

// 显示提示词模板
function showPromptTemplates() {
    layui.layer.open({
        type: 1,
        title: '📄 提示词模板',
        area: ['400px', '350px'],
        shadeClose: true,
        content: `
            <div style="padding: 20px;">
                <div style="margin-bottom: 12px; padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="insertPrompt('请用简洁的语言解释：')">📖 简洁解释</div>
                <div style="margin-bottom: 12px; padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="insertPrompt('请从以下几个方面分析：1. 背景 2. 人物 3. 主题 4. 影响')">📊 多维分析</div>
                <div style="margin-bottom: 12px; padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="insertPrompt('请模仿原著风格续写以下情节：')">🎭 风格模仿</div>
                <div style="margin-bottom: 12px; padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="insertPrompt('请对比分析以下两个角色的异同：')">⚖️ 对比分析</div>
                <div style="padding: 12px; background: #2d2d2d; border-radius: 8px; cursor: pointer;" onclick="insertPrompt('请以时间线的形式梳理以下事件：')">📅 时间线</div>
            </div>
        `
    });
}

// 插入提示词
function insertPrompt(text) {
    chatInput.value = text;
    chatInput.focus();
    layui.layer.closeAll();
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

// 清空对话
function clearChat() {
    layer.confirm('确定要清空当前对话吗？', {
        btn: ['确定', '取消'],
        title: '清空对话'
    }, function(index) {
        const state = getCurrentState();
        state.history = [];
        state.chatId = generateChatId();
        state.html = null;
        const assistant = assistants[currentAssistant];
        chatMessages.innerHTML = `
            <div class="message">
                <div class="message-system">${assistant.systemPrompt}</div>
            </div>
        `;
        layer.close(index);
        layer.msg('🗑️ 对话已清空');
    });
}

// 代码模式切换
let codeMode = false;
function toggleCodeMode() {
    codeMode = !codeMode;
    const btn = event.currentTarget;
    btn.classList.toggle('active', codeMode);
    if (codeMode) {
        chatInput.placeholder = '输入代码或技术问题...';
        layer.msg('💻 代码模式已开启');
    } else {
        chatInput.placeholder = 'Type your message here, press Enter to send';
        layer.msg('代码模式已关闭');
    }
}

// ===== 移动端侧边栏 =====

function initMobileSidebar() {
    if (!sidebarToggle || !sidebar || !sidebarOverlay) return;
    
    // 点击汉堡菜单打开侧边栏
    sidebarToggle.addEventListener('click', openSidebar);
    
    // 点击遮罩层关闭侧边栏
    sidebarOverlay.addEventListener('click', closeSidebar);
    
    // 点击助手项后关闭侧边栏（移动端）
    document.querySelectorAll('.assistant-item').forEach(item => {
        item.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                closeSidebar();
            }
        });
    });
    
    // ESC 键关闭侧边栏
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('open')) {
            closeSidebar();
        }
    });
    
    // 窗口大小改变时重置侧边栏状态
    window.addEventListener('resize', () => {
        if (window.innerWidth > 768) {
            closeSidebar();
        }
    });
    
    // 触摸滑动支持
    let touchStartX = 0;
    let touchEndX = 0;
    
    document.addEventListener('touchstart', (e) => {
        touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });
    
    document.addEventListener('touchend', (e) => {
        touchEndX = e.changedTouches[0].screenX;
        handleSwipe();
    }, { passive: true });
    
    function handleSwipe() {
        const swipeDistance = touchEndX - touchStartX;
        const minSwipeDistance = 50;
        
        // 从左边缘向右滑动打开侧边栏
        if (touchStartX < 30 && swipeDistance > minSwipeDistance && !sidebar.classList.contains('open')) {
            openSidebar();
        }
        
        // 向左滑动关闭侧边栏
        if (swipeDistance < -minSwipeDistance && sidebar.classList.contains('open')) {
            closeSidebar();
        }
    }
}

function toggleSidebar() {
    if (sidebar.classList.contains('open')) {
        closeSidebar();
    } else {
        openSidebar();
    }
}

function openSidebar() {
    sidebar.classList.add('open');
    sidebarOverlay.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeSidebar() {
    sidebar.classList.remove('open');
    sidebarOverlay.classList.remove('show');
    document.body.style.overflow = '';
}
