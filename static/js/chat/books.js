/**
 * 书籍管理模块
 */

const ChatBooks = {
    books: [],
    currentBook: null,
    sectionOpen: true,
    
    // 初始化
    async init() {
        await this.loadBooks();
        
        // 从 localStorage 恢复上次选择的书籍
        const savedBook = localStorage.getItem('selectedBook');
        if (savedBook && this.books.length > 0) {
            const bookExists = this.books.find(b => b.file === savedBook);
            if (bookExists && (!this.currentBook || this.currentBook.file !== savedBook)) {
                // 静默选择上次的书籍
                await this.selectBookSilent(savedBook);
            }
        }
        
        this.updateCurrentBookDisplay();
    },
    
    // 加载书籍列表
    async loadBooks() {
        try {
            const response = await fetch(`${ChatConfig.API_BASE}/api/books`);
            const data = await response.json();
            this.books = data.books || [];
            this.currentBook = this.books.find(b => b.isSelected) || this.books[0];
        } catch (error) {
            console.error('加载书籍列表失败:', error);
        }
    },
    
    // 更新当前书籍显示
    updateCurrentBookDisplay() {
        const container = document.getElementById('currentBook');
        if (!container) return;
        
        if (this.currentBook) {
            const statusIcon = this.currentBook.hasIndex ? '✅' : '⚠️';
            const statusText = this.currentBook.hasIndex 
                ? `${this.currentBook.chunkCount} 块` 
                : '未索引';
            container.innerHTML = `
                <span class="book-name" title="${this.currentBook.title}">${this.currentBook.title}</span>
                <span class="book-status">${statusIcon} ${statusText}</span>
            `;
        } else {
            container.innerHTML = `
                <span class="book-name">未选择书籍</span>
                <span class="book-status">📂 请选择</span>
            `;
        }
    },
    
    // 切换区域展开/收起
    toggleSection() {
        this.sectionOpen = !this.sectionOpen;
        const content = document.getElementById('bookSelectorContent');
        const toggle = document.querySelector('.section-toggle');
        if (content) {
            content.style.display = this.sectionOpen ? 'block' : 'none';
        }
        if (toggle) {
            toggle.style.transform = this.sectionOpen ? 'rotate(0deg)' : 'rotate(-90deg)';
        }
    },
    
    // 显示书籍列表弹窗
    showBookList() {
        const content = this.books.length === 0 
            ? '<div style="padding: 20px; text-align: center; color: #999;">books 目录中没有找到书籍文件<br><small>支持 .epub 和 .txt 格式</small></div>'
            : `
                <div class="book-list">
                    ${this.books.map(book => `
                        <div class="book-list-item ${book.isSelected ? 'selected' : ''}" data-file="${book.file}">
                            <div class="book-info">
                                <div class="book-title">${book.title}</div>
                                <div class="book-meta">
                                    ${book.author ? `<span>${book.author}</span>` : ''}
                                    <span class="book-format">${book.format}</span>
                                    <span>${book.fileSize}</span>
                                </div>
                            </div>
                            <div class="book-index-status">
                                ${book.hasIndex 
                                    ? `<span class="index-ready">✅ 已索引<br><small>${book.chunkCount} 块</small></span>`
                                    : `<button class="index-btn" onclick="ChatBooks.indexBook('${book.file}', event)">🔧 创建索引</button>`
                                }
                            </div>
                        </div>
                    `).join('')}
                </div>
            `;
        
        layer.open({
            type: 1,
            title: '📚 选择书籍',
            area: ['500px', '400px'],
            content: content,
            success: function(layero) {
                // 绑定书籍选择事件
                layero.find('.book-list-item').on('click', function(e) {
                    if (e.target.classList.contains('index-btn')) return;
                    const file = this.dataset.file;
                    ChatBooks.selectBook(file);
                });
            }
        });
    },
    
    // 选择书籍
    async selectBook(file) {
        try {
            const response = await fetch(`${ChatConfig.API_BASE}/api/books/select`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ book: file })
            });
            const result = await response.json();
            
            if (result.success) {
                // 更新本地状态
                this.books.forEach(b => b.isSelected = (b.file === file));
                this.currentBook = this.books.find(b => b.file === file);
                this.updateCurrentBookDisplay();
                
                // 保存到 localStorage 记住选择
                localStorage.setItem('selectedBook', file);
                
                layer.closeAll();
                layer.msg(result.message);
                
                // 重新加载助手配置以更新书籍相关的提示词
                await ChatAssistants.loadAssistants();
                // 刷新当前助手的欢迎消息
                ChatAssistants.switchAssistant(ChatState.currentAssistant);
                
                // 如果没有索引，提示创建
                if (!result.hasIndex) {
                    layer.confirm('该书籍还没有创建向量索引，是否现在创建？', {
                        btn: ['创建索引', '稍后再说']
                    }, () => {
                        layer.closeAll();
                        this.indexBook(file);
                    });
                }
            } else {
                layer.msg(result.error || '选择失败', { icon: 2 });
            }
        } catch (error) {
            layer.msg('选择书籍失败: ' + error.message, { icon: 2 });
        }
    },
    
    // 静默选择书籍（页面加载时恢复，不显示提示）
    async selectBookSilent(file) {
        try {
            const response = await fetch(`${ChatConfig.API_BASE}/api/books/select`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ book: file })
            });
            const result = await response.json();
            
            if (result.success) {
                // 更新本地状态
                this.books.forEach(b => b.isSelected = (b.file === file));
                this.currentBook = this.books.find(b => b.file === file);
                console.log('📚 已恢复上次选择的书籍:', this.currentBook?.title);
            }
        } catch (error) {
            console.error('恢复书籍选择失败:', error);
        }
    },
    
    // 创建书籍索引
    async indexBook(file, event) {
        if (event) event.stopPropagation();
        
        // 显示进度弹窗
        const progressLayer = layer.open({
            type: 1,
            title: '🔧 创建索引',
            area: ['400px', '200px'],
            closeBtn: 0,
            content: `
                <div class="index-progress">
                    <div class="progress-text" id="indexProgressText">准备中...</div>
                    <div class="progress-bar-container">
                        <div class="progress-bar" id="indexProgressBar" style="width: 0%"></div>
                    </div>
                </div>
            `
        });
        
        try {
            const response = await fetch(`${ChatConfig.API_BASE}/api/books/index`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ book: file })
            });
            
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                
                buffer += decoder.decode(value, { stream: true });
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
                    } else if (line === '' && currentEvent) {
                        const data = dataLines.join('\n');
                        this.handleIndexProgress(currentEvent, data);
                        currentEvent = null;
                    }
                }
            }
            
        } catch (error) {
            document.getElementById('indexProgressText').textContent = '❌ 索引创建失败: ' + error.message;
        }
    },
    
    // 获取当前选中的书籍文件名
    getCurrentBook() {
        return this.currentBook?.file || null;
    },
    
    // 处理索引进度
    handleIndexProgress(event, data) {
        const progressText = document.getElementById('indexProgressText');
        const progressBar = document.getElementById('indexProgressBar');
        
        try {
            const info = JSON.parse(data);
            
            if (event === 'progress') {
                progressText.textContent = info.message;
                if (info.progress) {
                    progressBar.style.width = info.progress + '%';
                }
            } else if (event === 'done') {
                progressText.textContent = '✅ ' + info.message;
                progressBar.style.width = '100%';
                progressBar.style.background = '#4caf50';
                
                // 刷新书籍列表
                setTimeout(async () => {
                    await this.loadBooks();
                    this.updateCurrentBookDisplay();
                    layer.closeAll();
                    layer.msg('索引创建成功！');
                }, 1500);
            } else if (event === 'error') {
                progressText.textContent = '❌ 错误: ' + data;
                progressBar.style.background = '#f44336';
            }
        } catch (e) {
            progressText.textContent = data;
        }
    },
    
    // 刷新书籍列表（供空状态引导使用）
    async refreshBooks() {
        try {
            layer.load(2, { time: 0 });
            await this.loadBooks();
            this.updateCurrentBookDisplay();
            
            // 重新加载助手配置以更新欢迎消息
            await ChatAssistants.loadAssistants();
            // 刷新当前助手的欢迎消息
            ChatAssistants.switchAssistant(ChatState.currentAssistant);
            
            layer.closeAll();
            
            if (this.books.length > 0) {
                layer.msg(`✅ 已发现 ${this.books.length} 本书籍`);
            } else {
                layer.msg('未找到新的书籍文件', { icon: 0 });
            }
        } catch (error) {
            layer.closeAll();
            layer.msg('刷新书籍列表失败: ' + error.message, { icon: 2 });
        }
    }
};

// 导出
window.ChatBooks = ChatBooks;
