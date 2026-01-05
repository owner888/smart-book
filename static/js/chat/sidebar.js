/**
 * 移动端侧边栏模块
 */

let sidebar, sidebarToggle, sidebarOverlay;

// 初始化移动端侧边栏
function initMobileSidebar() {
    sidebar = document.getElementById('sidebar');
    sidebarToggle = document.getElementById('sidebarToggle');
    sidebarOverlay = document.getElementById('sidebarOverlay');
    
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

// 导出
window.ChatSidebar = {
    initMobileSidebar,
    toggleSidebar,
    openSidebar,
    closeSidebar
};
