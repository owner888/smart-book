/**
 * 消息处理模块
 */

// 发送消息（SSE 流式）
async function sendMessage() {
    const chatInput = document.getElementById('chatInput');
    const sendBtn = document.getElementById('sendBtn');
    const chatMessages = document.getElementById('chatMessages');
    
    const message = chatInput.value.trim();
    if (!message || ChatState.isLoading) return;
    
    ChatState.isLoading = true;
    sendBtn.disabled = true;
    chatInput.value = '';
    chatInput.style.height = 'auto';
    
    // 添加用户消息
    addMessage('user', message);
    ChatState.getCurrentState().history.push({ role: 'user', content: message });
    
    // 重置流式状态
    ChatState.currentContent = '';
    ChatState.currentThinking = '';
    ChatState.currentSources = null;
    ChatState.currentSummaryInfo = null;
    ChatState.currentSystemPrompt = null;
    ChatState.currentUsage = null;
    
    // 创建空的助手消息容器
    const assistant = ChatAssistants.assistants[ChatState.currentAssistant] || {
        color: '#4caf50',
        avatar: '📚'
    };
    ChatState.currentMessageDiv = document.createElement('div');
    ChatState.currentMessageDiv.className = 'message message-assistant';
    ChatState.currentMessageDiv.innerHTML = `
        <div class="message-avatar" style="background: ${assistant.color};">${assistant.avatar}</div>
        <div class="message-content">
            <div class="typing-indicator">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
            </div>
        </div>
    `;
    chatMessages.appendChild(ChatState.currentMessageDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    
    // 构建请求
    const searchConfig = ChatToolbar.getSearchConfig();
    const ragConfig = ChatToolbar.getRAGConfig();
    const modelId = ChatModels.getCurrentModelId();
    let url, body;
    if (assistant.action === 'ask') {
        url = `${ChatConfig.API_BASE}/api/stream/ask`;
        body = { 
            question: message, 
            chat_id: ChatState.getCurrentState().chatId, 
            search: searchConfig.enabled, 
            engine: searchConfig.engine,
            rag: ragConfig.enabled,  // RAG 开关
            keyword_weight: ragConfig.keywordWeight,  // 关键词权重
            model: modelId  // 模型
        };
    } else if (assistant.action === 'continue') {
        url = `${ChatConfig.API_BASE}/api/stream/continue`;
        body = { 
            prompt: message, 
            search: searchConfig.enabled, 
            engine: searchConfig.engine, 
            rag: ragConfig.enabled,
            keyword_weight: ragConfig.keywordWeight,
            model: modelId 
        };
    } else {
        url = `${ChatConfig.API_BASE}/api/stream/chat`;
        body = { message: message, chat_id: ChatState.getCurrentState().chatId, search: searchConfig.enabled, engine: searchConfig.engine, model: modelId };
    }
    
    // 使用 fetch + SSE
    try {
        ChatState.abortController = new AbortController();
        
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            signal: ChatState.abortController.signal
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
                    const data = dataLines.join('\n');
                    handleSSEEvent(currentEvent, data);
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
            ChatState.currentContent += '\n\n⏹️ 已停止生成';
        } else {
            ChatState.currentContent = `❌ 请求失败: ${error.message}\n\n请确保 Workerman 服务已启动:\n\`php workerman_ai_server.php start\``;
        }
        finishStreamingMessage(error.name !== 'AbortError');
    } finally {
        ChatState.isLoading = false;
        sendBtn.disabled = false;
        ChatState.abortController = null;
    }
}

// 处理 SSE 事件
function handleSSEEvent(eventType, data) {
    if (eventType === 'sources') {
        try { ChatState.currentSources = JSON.parse(data); } catch (e) {}
    } else if (eventType === 'summary_used') {
        try { ChatState.currentSummaryInfo = JSON.parse(data); } catch (e) {}
    } else if (eventType === 'usage') {
        try { 
            ChatState.currentUsage = JSON.parse(data);
            updateUsageDisplay(ChatState.currentUsage);
        } catch (e) {}
    } else if (eventType === 'cached') {
        try {
            const cacheInfo = JSON.parse(data);
            if (cacheInfo.hit) layer.msg(`📦 语义缓存命中！`, { time: 1500 });
        } catch (e) {}
    } else if (eventType === 'system_prompt') {
        ChatState.currentSystemPrompt = data;
        updateStreamingMessage();
    } else if (eventType === 'thinking') {
        ChatState.currentThinking += data;
        updateStreamingMessage();
    } else if (eventType === 'content') {
        ChatState.currentContent += data;
        updateStreamingMessage();
    } else if (eventType === 'error') {
        ChatState.currentContent = `❌ 服务端错误: ${data}`;
        finishStreamingMessage(true);
    } else if (eventType === 'done') {
        finishStreamingMessage();
    }
}

// 更新流式消息显示
function updateStreamingMessage() {
    if (!ChatState.currentMessageDiv) return;
    
    const contentDiv = ChatState.currentMessageDiv.querySelector('.message-content');
    
    let thinkingHtml = '';
    if (ChatState.currentThinking) {
        thinkingHtml = `
            <div class="thinking-container">
                <div class="thinking-header" onclick="this.parentElement.classList.toggle('collapsed')">
                    <span class="thinking-icon">🧠</span>
                    <span>Thinking...</span>
                    <span class="thinking-toggle">▼</span>
                </div>
                <div class="thinking-content">${ChatUtils.escapeHtml(ChatState.currentThinking)}</div>
            </div>
        `;
    }
    
    const htmlContent = ChatState.currentContent ? marked.parse(ChatState.currentContent) : '';
    contentDiv.innerHTML = thinkingHtml + htmlContent;
    
    const chatMessages = document.getElementById('chatMessages');
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// 完成流式消息
function finishStreamingMessage(isError = false) {
    if (!ChatState.currentMessageDiv) return;
    
    const contentDiv = ChatState.currentMessageDiv.querySelector('.message-content');
    const chatMessages = document.getElementById('chatMessages');
    
    // 构建系统提示词 HTML
    let systemPromptHtml = '';
    if (ChatState.currentSystemPrompt) {
        systemPromptHtml = `
            <div class="thinking-container collapsed" style="background: linear-gradient(135deg, rgba(33, 150, 243, 0.1), rgba(3, 169, 244, 0.1)); border-color: rgba(33, 150, 243, 0.3);">
                <div class="thinking-header" onclick="this.parentElement.classList.toggle('collapsed')" style="background: rgba(33, 150, 243, 0.15);">
                    <span class="thinking-icon">📋</span>
                    <span>系统提示词</span>
                    <span class="thinking-toggle">▼</span>
                </div>
                <div class="thinking-content">${ChatUtils.escapeHtml(ChatState.currentSystemPrompt)}</div>
            </div>
        `;
    }
    
    // 构建思考过程 HTML
    let thinkingHtml = '';
    if (ChatState.currentThinking) {
        thinkingHtml = `
            <div class="thinking-container collapsed">
                <div class="thinking-header" onclick="this.parentElement.classList.toggle('collapsed')">
                    <span class="thinking-icon">🧠</span>
                    <span>已完成思考</span>
                    <span class="thinking-toggle">▼</span>
                </div>
                <div class="thinking-content">${ChatUtils.escapeHtml(ChatState.currentThinking)}</div>
            </div>
        `;
    }
    
    // 渲染最终内容
    let htmlContent = isError 
        ? ChatUtils.escapeHtml(ChatState.currentContent).replace(/\n/g, '<br>') 
        : marked.parse(ChatState.currentContent);
    
    htmlContent = ChatUtils.makeUrlsClickable(htmlContent);
    
    // 摘要信息
    let summaryHtml = '';
    if (ChatState.currentSummaryInfo) {
        summaryHtml = `
            <div class="sources-container" style="border-left-color: #9c27b0;">
                <div class="sources-title">📝 上下文摘要</div>
                <div class="source-item" style="background: rgba(156, 39, 176, 0.1);">
                    已压缩 <strong>${ChatState.currentSummaryInfo.rounds_summarized}</strong> 轮历史对话
                </div>
            </div>
        `;
    }
    
    // 检索来源
    let sourcesHtml = '';
    if (ChatState.currentSources && ChatState.currentSources.length > 0) {
        sourcesHtml = `
            <div class="sources-container">
                <div class="sources-title">📚 检索来源 (${ChatState.currentSources.length})</div>
                ${ChatState.currentSources.slice(0, 3).map(s => `
                    <div class="source-item">
                        <span class="source-score">${s.score}%</span>
                        ${ChatUtils.escapeHtml(s.text)}
                    </div>
                `).join('')}
            </div>
        `;
    }
    
    // 使用统计
    let usageHtml = '';
    if (ChatState.currentUsage) {
        const usage = ChatState.currentUsage;
        const tokens = usage.tokens || {};
        usageHtml = `
            <div class="usage-container">
                <span class="usage-item">🤖 ${usage.model || 'unknown'}</span>
                <span class="usage-item">📊 ${formatTokens(tokens.total || 0)}</span>
                <span class="usage-item">↗ ${formatTokens(tokens.input || 0)}</span>
                <span class="usage-item">↙ ${formatTokens(tokens.output || 0)}</span>
                <span class="usage-item">💰 ${usage.cost_formatted || 'Free'}</span>
            </div>
        `;
    }
    
    // TTS 预估消耗（如果启用云端 TTS）
    let ttsUsageHtml = '';
    if (window.ChatTTS && ChatTTS.useCloudTTS && ChatState.currentContent) {
        const ttsEstimate = ChatTTS.estimateCost(ChatState.currentContent);
        if (ttsEstimate) {
            ttsUsageHtml = `
                <div class="usage-container tts-estimate">
                    <span class="usage-item">🔊 ${ttsEstimate.voice}</span>
                    <span class="usage-item">📝 ${ttsEstimate.charCount}</span>
                    <span class="usage-item">💰 ${ttsEstimate.cost}</span>
                </div>
            `;
        }
    }
    
    // 添加消息操作按钮
    // 使用 data 属性存储消息内容，避免内联事件处理器的转义问题
    const messageId = 'msg-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
    const messageContent = ChatState.currentContent;
    
    // 将消息内容存储到全局缓存中
    if (!window.ChatMessageCache) {
        window.ChatMessageCache = {};
    }
    window.ChatMessageCache[messageId] = messageContent;
    
    // 使用纯 data 属性方式，不在 onclick 中传递消息内容，完全避免转义问题
    const actionsHtml = `
        <div class="message-actions">
            <button class="action-btn" title="朗读" data-message-id="${messageId}" onclick="ChatMessage.speakMessage(this)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                    <path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
                    <path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>
                </svg>
            </button>
            <button class="action-btn" title="复制" data-message-id="${messageId}" onclick="ChatMessage.copyMessage(this)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                </svg>
            </button>
            <button class="action-btn" title="重新生成" onclick="ChatMessage.regenerateMessage()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="23 4 23 10 17 10"/>
                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                </svg>
            </button>
        </div>
    `;
    
    contentDiv.innerHTML = systemPromptHtml + thinkingHtml + htmlContent + summaryHtml + sourcesHtml + usageHtml + ttsUsageHtml + actionsHtml;
    
    if (!isError) {
        ChatState.getCurrentState().history.push({ role: 'assistant', content: ChatState.currentContent });
    }
    
    ChatState.currentMessageDiv = null;
    ChatState.currentContent = '';
    ChatState.currentSources = null;
    
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// 添加消息
function addMessage(role, content) {
    const chatMessages = document.getElementById('chatMessages');
    const assistant = ChatAssistants.assistants[ChatState.currentAssistant];
    const messageDiv = document.createElement('div');
    messageDiv.className = `message message-${role}`;
    
    if (role === 'user') {
        messageDiv.innerHTML = `<div class="message-content">${ChatUtils.escapeHtml(content)}</div>`;
    } else {
        const htmlContent = marked.parse(content);
        messageDiv.innerHTML = `
            <div class="message-avatar" style="background: ${assistant.color};">${assistant.avatar}</div>
            <div class="message-content">${htmlContent}</div>
        `;
    }
    
    chatMessages.appendChild(messageDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// 格式化 token 数量
function formatTokens(num) {
    if (num >= 1000000) {
        return (num / 1000000).toFixed(2) + 'M';
    } else if (num >= 1000) {
        return (num / 1000).toFixed(1) + 'K';
    }
    return num.toString();
}

// 更新使用统计显示（实时）
function updateUsageDisplay(usage) {
    // 可以在此添加实时统计更新逻辑，比如更新底部状态栏
    console.log('📊 Usage:', usage);
}

// 清空对话
function clearChat() {
    layer.confirm('确定要清空当前对话吗？', {
        btn: ['确定', '取消'],
        title: '清空对话'
    }, function(index) {
        const chatMessages = document.getElementById('chatMessages');
        const state = ChatState.getCurrentState();
        state.history = [];
        state.chatId = ChatState.generateChatId();
        state.html = null;
        const assistant = ChatAssistants.assistants[ChatState.currentAssistant];
        chatMessages.innerHTML = ChatAssistants.buildWelcomeMessage(assistant);
        layer.close(index);
        layer.msg('🗑️ 对话已清空');
    });
}

// 朗读消息
function speakMessage(button, text) {
    // 如果 text 为空，尝试从 data 属性获取
    if (!text) {
        const messageId = button.getAttribute('data-message-id');
        if (messageId && window.ChatMessageCache) {
            text = window.ChatMessageCache[messageId];
        }
    }
    
    if (!text) {
        layer.msg('⚠️ 无法获取消息内容', { icon: 0 });
        return;
    }
    
    if (window.ChatTTS) {
        // 使用 messageId 来判断是否是同一条消息，而不是按钮引用
        const messageId = button.getAttribute('data-message-id');
        ChatTTS.speak(text, button, messageId);
    } else {
        layer.msg('⚠️ TTS 模块未加载', { icon: 0 });
    }
}

// 复制消息
function copyMessage(buttonOrText) {
    let text = buttonOrText;
    
    // 如果传入的是按钮元素，从缓存中获取文本
    if (buttonOrText && typeof buttonOrText === 'object' && buttonOrText.getAttribute) {
        const messageId = buttonOrText.getAttribute('data-message-id');
        if (messageId && window.ChatMessageCache) {
            text = window.ChatMessageCache[messageId];
        }
    }
    
    if (!text) {
        layer.msg('⚠️ 无法获取消息内容', { icon: 0 });
        return;
    }
    
    // 过滤工具调用信息
    text = text
        .replace(/^>\s*🔧.*$/gm, '')
        .replace(/^>\s*✅.*$/gm, '')
        .replace(/^>\s*❌.*$/gm, '')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
    
    navigator.clipboard.writeText(text).then(() => {
        layer.msg('✅ 已复制到剪贴板');
    }).catch(err => {
        console.error('复制失败:', err);
        layer.msg('复制失败', { icon: 2 });
    });
}

// 重新生成消息
function regenerateMessage() {
    const state = ChatState.getCurrentState();
    if (state.history.length < 2) {
        layer.msg('没有可重新生成的消息', { icon: 0 });
        return;
    }
    
    // 移除最后一条助手消息
    state.history.pop();
    
    // 获取最后一条用户消息
    const lastUserMsg = state.history[state.history.length - 1];
    if (lastUserMsg && lastUserMsg.role === 'user') {
        // 移除用户消息（会在 sendMessage 中重新添加）
        state.history.pop();
        
        // 移除 DOM 中的最后两条消息
        const chatMessages = document.getElementById('chatMessages');
        const messages = chatMessages.querySelectorAll('.message');
        if (messages.length >= 2) {
            messages[messages.length - 1].remove();
            messages[messages.length - 2].remove();
        }
        
        // 重新发送
        document.getElementById('chatInput').value = lastUserMsg.content;
        sendMessage();
    }
}

// 导出
window.ChatMessage = {
    sendMessage,
    addMessage,
    clearChat,
    updateStreamingMessage,
    finishStreamingMessage,
    speakMessage,
    copyMessage,
    regenerateMessage
};
