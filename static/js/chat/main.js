/**
 * 主入口模块
 */

// 初始化
document.addEventListener('DOMContentLoaded', () => {
    // 初始化移动端侧边栏
    ChatSidebar.initMobileSidebar();
    
    // 切换助手
    document.querySelectorAll('.assistant-item').forEach(item => {
        item.addEventListener('click', () => {
            const assistant = item.dataset.assistant;
            ChatAssistants.switchAssistant(assistant);
        });
    });
    
    // 发送消息
    const sendBtn = document.getElementById('sendBtn');
    const chatInput = document.getElementById('chatInput');
    
    sendBtn.addEventListener('click', ChatMessage.sendMessage);
    chatInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            ChatMessage.sendMessage();
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
    
    // 加载模型列表、助手配置和书籍列表并初始化
    Promise.all([
        ChatModels.loadModels(),
        ChatAssistants.loadAssistants(),
        ChatBooks.init()
    ]).then(() => {
        setTimeout(() => chatInput.focus(), 100);
    });
});

// 全局函数映射（供 HTML onclick 使用）
window.sendMessage = () => ChatMessage.sendMessage();
window.clearChat = () => ChatMessage.clearChat();
window.switchAssistant = (id) => ChatAssistants.switchAssistant(id);
window.toggleWebSearch = () => ChatToolbar.toggleWebSearch();
window.showAITools = () => ChatToolbar.showAITools();
window.toggleFullscreen = () => ChatToolbar.toggleFullscreen();
window.showQuickCommands = () => ChatPhrases.showQuickCommands();
window.showTip = (feature) => ChatUtils.showTip(feature);
window.insertPrompt = (text) => ChatUtils.insertPrompt(text);
window.escapeHtml = (text) => ChatUtils.escapeHtml(text);
