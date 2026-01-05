/**
 * 状态管理模块
 */

// 生成 Chat ID
function generateChatId() {
    return 'chat_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
}

// 每个助手独立的状态存储
const assistantStates = {
    book: { history: [], chatId: generateChatId(), html: null },
    continue: { history: [], chatId: generateChatId(), html: null },
    chat: { history: [], chatId: generateChatId(), html: null },
    default: { history: [], chatId: generateChatId(), html: null },
};

// 当前状态
let currentAssistant = 'book';
let isLoading = false;
let currentMessageDiv = null;
let currentContent = '';
let currentThinking = '';
let currentSources = null;
let currentSummaryInfo = null;
let currentSystemPrompt = null;
let abortController = null;

// 获取当前助手的状态
function getCurrentState() {
    return assistantStates[currentAssistant];
}

// 导出
window.ChatState = {
    assistantStates,
    getCurrentState,
    generateChatId,
    get currentAssistant() { return currentAssistant; },
    set currentAssistant(v) { currentAssistant = v; },
    get isLoading() { return isLoading; },
    set isLoading(v) { isLoading = v; },
    get currentMessageDiv() { return currentMessageDiv; },
    set currentMessageDiv(v) { currentMessageDiv = v; },
    get currentContent() { return currentContent; },
    set currentContent(v) { currentContent = v; },
    get currentThinking() { return currentThinking; },
    set currentThinking(v) { currentThinking = v; },
    get currentSources() { return currentSources; },
    set currentSources(v) { currentSources = v; },
    get currentSummaryInfo() { return currentSummaryInfo; },
    set currentSummaryInfo(v) { currentSummaryInfo = v; },
    get currentSystemPrompt() { return currentSystemPrompt; },
    set currentSystemPrompt(v) { currentSystemPrompt = v; },
    get abortController() { return abortController; },
    set abortController(v) { abortController = v; },
};
