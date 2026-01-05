/**
 * 工具函数模块
 */

// HTML 转义
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// 将 code 标签中的 URL 转为可点击链接
function makeUrlsClickable(html) {
    const urlPattern = /<code>(https?:\/\/[^\s<]+)<\/code>/gi;
    return html.replace(urlPattern, (match, url) => {
        return `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`;
    });
}

// 显示提示
function showTip(feature) {
    layer.msg(`🔧 ${feature} 功能开发中...`);
}

// 插入提示词到输入框
function insertPrompt(text) {
    const chatInput = document.getElementById('chatInput');
    if (chatInput) {
        chatInput.value = text;
        chatInput.focus();
    }
    layui.layer.closeAll();
}

// 导出
window.ChatUtils = {
    escapeHtml,
    makeUrlsClickable,
    showTip,
    insertPrompt
};
