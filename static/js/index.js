/**
 * 首页框架脚本
 */

// 页面映射
const pageMap = {
    chat: 'pages/chat.html',
    assistant: 'pages/placeholder.html',
    library: 'pages/placeholder.html',
    plugins: 'pages/placeholder.html',
    search: 'pages/placeholder.html',
    notes: 'pages/placeholder.html',
    settings: 'pages/settings.html',
};

// 初始化导航
function initNav() {
    document.querySelectorAll('.icon-nav-item').forEach(item => {
        item.addEventListener('click', () => {
            const page = item.dataset.page;
            if (!page) return;
            
            // 更新激活状态
            document.querySelectorAll('.icon-nav-item').forEach(i => i.classList.remove('active'));
            item.classList.add('active');
            
            // 切换页面
            const frame = document.getElementById('mainFrame');
            const url = pageMap[page];
            if (url) {
                frame.src = url;
            }
            
            // 保存当前页面
            localStorage.setItem('currentPage', page);
        });
    });
}

// 恢复上次页面
function restorePage() {
    const savedPage = localStorage.getItem('currentPage') || 'chat';
    const savedItem = document.querySelector(`.icon-nav-item[data-page="${savedPage}"]`);
    if (savedItem) {
        savedItem.click();
    }
}

// DOM 加载完成后初始化
document.addEventListener('DOMContentLoaded', () => {
    initNav();
    restorePage();
});
