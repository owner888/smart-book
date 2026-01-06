/**
 * TTS 朗读模块 - 支持 Google Cloud TTS 和浏览器 TTS
 */

const ChatTTS = {
    // 当前朗读状态
    speaking: false,
    currentAudio: null,
    currentButton: null,
    currentMessageId: null,
    
    // 配置
    useCloudTTS: false,  // 默认使用浏览器 TTS（云端 TTS 需要启用 Google Cloud Text-to-Speech API）
    cloudVoices: null,
    
    // 浏览器 TTS 配置
    browserVoices: [],
    selectedBrowserVoice: null,
    
    // 初始化
    init() {
        // 页面加载时先停止任何残留的语音
        if ('speechSynthesis' in window) {
            speechSynthesis.cancel();
        }
        
        // 加载浏览器语音（作为后备）
        if ('speechSynthesis' in window) {
            speechSynthesis.onvoiceschanged = () => this.loadBrowserVoices();
            this.loadBrowserVoices();
        }
        
        // 加载云端语音列表
        this.loadCloudVoices();
        
        // 从 localStorage 恢复设置
        this.useCloudTTS = localStorage.getItem('ttsUseCloud') !== 'false';
        
        console.log('🔊 TTS 模块已初始化');
    },
    
    // 加载浏览器语音
    loadBrowserVoices() {
        this.browserVoices = speechSynthesis.getVoices();
        const savedVoiceName = localStorage.getItem('ttsBrowserVoice');
        if (savedVoiceName) {
            this.selectedBrowserVoice = this.browserVoices.find(v => v.name === savedVoiceName);
        }
        if (!this.selectedBrowserVoice) {
            this.selectedBrowserVoice = 
                this.browserVoices.find(v => v.lang.includes('zh') && v.name.toLowerCase().includes('natural')) ||
                this.browserVoices.find(v => v.lang.includes('zh')) ||
                this.browserVoices[0];
        }
    },
    
    // 加载云端语音列表
    async loadCloudVoices() {
        try {
            const response = await fetch(`${ChatConfig.API_BASE}/api/tts/voices`);
            const data = await response.json();
            if (data.voices) {
                this.cloudVoices = data.voices;
                console.log('🔊 云端语音已加载');
            }
        } catch (e) {
            console.warn('⚠️ 无法加载云端语音列表:', e.message);
        }
    },
    
    // 朗读文本
    async speak(text, button, messageId) {
        // 保存当前状态
        const wasPlayingMessageId = this.currentMessageId;
        const wasOurSpeaking = this.speaking;
        
        // 停止当前播放
        this.stop();
        
        // 如果点击的是同一条消息，只停止不播放
        if (wasOurSpeaking && messageId && wasPlayingMessageId === messageId) {
            return;
        }
        
        // 清理 Markdown
        const cleanText = this.cleanMarkdown(text);
        if (!cleanText.trim()) {
            layer.msg('没有可朗读的内容', { icon: 0 });
            return;
        }
        
        // 优先使用云端 TTS
        if (this.useCloudTTS) {
            await this.speakWithCloud(cleanText, button, messageId);
        } else {
            this.speakWithBrowser(cleanText, button, messageId);
        }
    },
    
    // 使用云端 TTS
    async speakWithCloud(text, button, messageId) {
        try {
            this.updateButtonState(button, true, true);  // 加载中状态
            
            const voice = localStorage.getItem('ttsCloudVoice') || null;
            const rate = parseFloat(localStorage.getItem('ttsRate') || '1.0');
            
            const response = await fetch(`${ChatConfig.API_BASE}/api/tts/synthesize`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ text, voice, rate }),
            });
            
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            // 播放音频
            const audioData = `data:audio/mp3;base64,${data.audio}`;
            const audio = new Audio(audioData);
            
            audio.onplay = () => {
                this.speaking = true;
                this.currentAudio = audio;
                this.currentButton = button;
                this.currentMessageId = messageId;
                this.updateButtonState(button, true);
            };
            
            audio.onended = () => {
                this.speaking = false;
                this.currentAudio = null;
                this.currentButton = null;
                this.currentMessageId = null;
                this.updateButtonState(button, false);
                // 显示消耗信息
                if (data.charCount !== undefined) {
                    this.showCostInfo(button, data.voice || 'auto', data.charCount, data.costFormatted || '<$0.01');
                }
            };
            
            audio.onerror = (e) => {
                console.error('音频播放错误:', e);
                this.speaking = false;
                this.updateButtonState(button, false);
                layer.msg('音频播放失败', { icon: 2 });
            };
            
            audio.play();
            
        } catch (e) {
            console.error('云端 TTS 错误:', e);
            this.updateButtonState(button, false);
            
            // 如果云端失败，尝试使用浏览器 TTS
            if ('speechSynthesis' in window) {
                layer.msg('云端 TTS 失败，使用浏览器语音', { icon: 0 });
                this.speakWithBrowser(text, button, messageId);
            } else {
                layer.msg('TTS 错误: ' + e.message, { icon: 2 });
            }
        }
    },
    
    // 使用浏览器 TTS
    speakWithBrowser(text, button, messageId) {
        if (!('speechSynthesis' in window)) {
            layer.msg('⚠️ 浏览器不支持语音朗读', { icon: 0 });
            return;
        }
        
        const utterance = new SpeechSynthesisUtterance(text);
        
        if (this.selectedBrowserVoice) {
            utterance.voice = this.selectedBrowserVoice;
        }
        utterance.rate = parseFloat(localStorage.getItem('ttsRate') || '1.0');
        utterance.pitch = parseFloat(localStorage.getItem('ttsPitch') || '1.0');
        utterance.volume = parseFloat(localStorage.getItem('ttsVolume') || '1.0');
        
        utterance.onstart = () => {
            this.speaking = true;
            this.currentButton = button;
            this.currentMessageId = messageId;
            this.updateButtonState(button, true);
        };
        
        utterance.onend = () => {
            this.speaking = false;
            this.currentButton = null;
            this.currentMessageId = null;
            this.updateButtonState(button, false);
        };
        
        utterance.onerror = (event) => {
            this.speaking = false;
            this.currentButton = null;
            this.currentMessageId = null;
            this.updateButtonState(button, false);
            if (event.error !== 'interrupted') {
                layer.msg('朗读出错: ' + event.error, { icon: 2 });
            }
        };
        
        speechSynthesis.speak(utterance);
    },
    
    // 停止朗读
    stop() {
        // 停止云端音频
        if (this.currentAudio) {
            this.currentAudio.pause();
            this.currentAudio.currentTime = 0;
            this.currentAudio = null;
        }
        
        // 停止浏览器 TTS
        if ('speechSynthesis' in window) {
            speechSynthesis.cancel();
        }
        
        this.speaking = false;
        if (this.currentButton) {
            this.updateButtonState(this.currentButton, false);
        }
        this.currentButton = null;
        this.currentMessageId = null;
    },
    
    // 更新按钮状态
    updateButtonState(button, isSpeaking, isLoading = false) {
        if (!button) return;
        
        if (isLoading) {
            button.classList.add('loading');
            button.title = '加载中...';
            button.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin">
                    <circle cx="12" cy="12" r="10" stroke-dasharray="30 70"/>
                </svg>
            `;
        } else if (isSpeaking) {
            button.classList.remove('loading');
            button.classList.add('speaking');
            button.title = '停止朗读';
            button.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <rect x="6" y="6" width="12" height="12" rx="2"/>
                </svg>
            `;
        } else {
            button.classList.remove('loading', 'speaking');
            button.title = '朗读';
            button.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                    <path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
                    <path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>
                </svg>
            `;
        }
    },
    
    // 清理 Markdown 格式
    cleanMarkdown(text) {
        return text
            .replace(/```[\s\S]*?```/g, '')
            .replace(/`[^`]+`/g, '')
            .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
            .replace(/!\[.*?\]\(.*?\)/g, '')
            .replace(/\*\*([^*]+)\*\*/g, '$1')
            .replace(/\*([^*]+)\*/g, '$1')
            .replace(/_([^_]+)_/g, '$1')
            .replace(/^#+\s+/gm, '')
            .replace(/^[\s]*[-*+]\s+/gm, '')
            .replace(/^[\s]*\d+\.\s+/gm, '')
            .replace(/^>\s+/gm, '')
            .replace(/^[-*_]{3,}$/gm, '')
            .replace(/<[^>]+>/g, '')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    },
    
    // 显示设置
    showSettings() {
        const currentRate = localStorage.getItem('ttsRate') || '1.0';
        const useCloud = this.useCloudTTS;
        
        // 构建云端语音选项
        let cloudVoiceOptions = '<option value="">自动选择</option>';
        if (this.cloudVoices) {
            for (const [lang, voices] of Object.entries(this.cloudVoices)) {
                const langLabel = lang === 'zh-CN' ? '中文' : 'English';
                cloudVoiceOptions += `<optgroup label="${langLabel}">`;
                for (const [voiceId, info] of Object.entries(voices)) {
                    const selected = localStorage.getItem('ttsCloudVoice') === voiceId ? 'selected' : '';
                    cloudVoiceOptions += `<option value="${voiceId}" ${selected}>${info.name}</option>`;
                }
                cloudVoiceOptions += '</optgroup>';
            }
        }
        
        const content = `
            <div style="padding: 20px;">
                <div style="margin-bottom: 16px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="ttsUseCloud" ${useCloud ? 'checked' : ''} style="width: 18px; height: 18px;">
                        <span style="font-weight: 500;">🔊 使用 Google Cloud TTS（更自然）</span>
                    </label>
                    <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">关闭后使用浏览器内置语音</div>
                </div>
                
                <div id="cloudVoiceSection" style="margin-bottom: 16px; ${useCloud ? '' : 'display: none;'}">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">🎙️ 云端语音</label>
                    <select id="ttsCloudVoiceSelect" style="width: 100%; padding: 8px; background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: 6px; color: inherit;">
                        ${cloudVoiceOptions}
                    </select>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">⏩ 语速: <span id="rateValue">${currentRate}x</span></label>
                    <input type="range" id="ttsRateSlider" min="0.5" max="2" step="0.1" value="${currentRate}" 
                           style="width: 100%;" onchange="document.getElementById('rateValue').textContent = this.value + 'x'">
                </div>
                
                <button onclick="ChatTTS.testVoice()" style="width: 100%; padding: 10px; background: var(--accent-green); border: none; border-radius: 6px; color: white; cursor: pointer;">
                    🔊 试听
                </button>
            </div>
        `;
        
        layui.layer.open({
            type: 1,
            title: '🔊 朗读设置',
            area: ['360px', 'auto'],
            shadeClose: true,
            content: content,
            success: () => {
                document.getElementById('ttsUseCloud').onchange = (e) => {
                    document.getElementById('cloudVoiceSection').style.display = e.target.checked ? '' : 'none';
                };
            },
            end: () => {
                const useCloud = document.getElementById('ttsUseCloud')?.checked;
                const cloudVoice = document.getElementById('ttsCloudVoiceSelect')?.value;
                const rate = document.getElementById('ttsRateSlider')?.value;
                
                if (useCloud !== undefined) {
                    this.useCloudTTS = useCloud;
                    localStorage.setItem('ttsUseCloud', useCloud);
                }
                if (cloudVoice) localStorage.setItem('ttsCloudVoice', cloudVoice);
                if (rate) localStorage.setItem('ttsRate', rate);
            }
        });
    },
    
    // 显示消耗信息（使用 usage-container 样式）
    showCostInfo(button, voice, charCount, costFormatted) {
        // 找到消息容器
        const messageEl = button.closest('.message');
        if (!messageEl) return;
        
        // 移除旧的消耗信息
        const oldCost = messageEl.querySelector('.tts-usage');
        if (oldCost) oldCost.remove();
        
        // 简化语音名称（如 cmn-CN-Wavenet-D → Wavenet-D）
        const shortVoice = voice.replace(/^(cmn-CN|cmn-TW|en-US)-/, '');
        
        // 创建消耗信息元素（使用 usage-container 样式）
        const usageEl = document.createElement('div');
        usageEl.className = 'tts-usage';
        usageEl.innerHTML = `
            <div class="usage-container">
                <span class="usage-item">🔊 ${shortVoice}</span>
                <span class="usage-item">📝 ${charCount}</span>
                <span class="usage-item">💰 ${costFormatted}</span>
            </div>
        `;
        
        // 找到 usage-container 或 sources-section，插入到它后面
        const existingUsage = messageEl.querySelector('.usage-container');
        const sourcesSection = messageEl.querySelector('.sources-section');
        
        if (existingUsage && !existingUsage.closest('.tts-usage')) {
            // 如果有模型统计行，插入到它后面
            existingUsage.insertAdjacentElement('afterend', usageEl.firstElementChild);
            // 直接插入内部的 usage-container，避免嵌套
        } else if (sourcesSection) {
            // 如果有检索来源区域，插入到它后面
            sourcesSection.insertAdjacentElement('afterend', usageEl);
        } else {
            // 否则插入到消息内容后面
            const contentEl = messageEl.querySelector('.message-content');
            if (contentEl) {
                contentEl.insertAdjacentElement('afterend', usageEl);
            }
        }
        
        // 5秒后淡出
        const ttsUsage = messageEl.querySelector('.tts-usage');
        if (ttsUsage) {
            setTimeout(() => {
                ttsUsage.style.transition = 'opacity 0.5s';
                ttsUsage.style.opacity = '0';
                setTimeout(() => ttsUsage.remove(), 500);
            }, 5000);
        }
    },
    
    // 试听
    async testVoice() {
        const useCloud = document.getElementById('ttsUseCloud')?.checked;
        const testText = '你好，这是一段测试语音。Hello, this is a test.';
        
        this.stop();
        
        if (useCloud) {
            const voice = document.getElementById('ttsCloudVoiceSelect')?.value || null;
            const rate = parseFloat(document.getElementById('ttsRateSlider')?.value || '1.0');
            
            try {
                const response = await fetch(`${ChatConfig.API_BASE}/api/tts/synthesize`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ text: testText, voice, rate }),
                });
                const data = await response.json();
                if (data.audio) {
                    const audio = new Audio(`data:audio/mp3;base64,${data.audio}`);
                    audio.play();
                } else {
                    throw new Error(data.error || '未知错误');
                }
            } catch (e) {
                layer.msg('试听失败: ' + e.message, { icon: 2 });
            }
        } else {
            const utterance = new SpeechSynthesisUtterance(testText);
            const rate = parseFloat(document.getElementById('ttsRateSlider')?.value || '1.0');
            utterance.rate = rate;
            speechSynthesis.speak(utterance);
        }
    }
};

// 添加 CSS 动画
const style = document.createElement('style');
style.textContent = `
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .action-btn .spin { animation: spin 1s linear infinite; }
`;
document.head.appendChild(style);

// 初始化
document.addEventListener('DOMContentLoaded', () => ChatTTS.init());

// 导出
window.ChatTTS = ChatTTS;
