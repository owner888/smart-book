/**
 * TTS 朗读模块 - 使用 Web Speech API
 */

const ChatTTS = {
    // 当前朗读状态
    speaking: false,
    currentUtterance: null,
    currentButton: null,
    
    // 可用的语音列表
    voices: [],
    selectedVoice: null,
    
    // 初始化
    init() {
        // 加载可用语音
        if ('speechSynthesis' in window) {
            // 语音列表可能异步加载
            speechSynthesis.onvoiceschanged = () => {
                this.loadVoices();
            };
            this.loadVoices();
        } else {
            console.warn('⚠️ 浏览器不支持 Web Speech API');
        }
    },
    
    // 加载可用语音
    loadVoices() {
        this.voices = speechSynthesis.getVoices();
        
        // 从 localStorage 恢复上次选择的语音
        const savedVoiceName = localStorage.getItem('ttsVoice');
        if (savedVoiceName) {
            this.selectedVoice = this.voices.find(v => v.name === savedVoiceName);
        }
        
        // 默认选择中文语音，优先选择自然声音
        if (!this.selectedVoice) {
            // 优先级：中文自然声音 > 中文声音 > 英文自然声音 > 第一个
            this.selectedVoice = 
                this.voices.find(v => v.lang.includes('zh') && v.name.toLowerCase().includes('natural')) ||
                this.voices.find(v => v.lang.includes('zh')) ||
                this.voices.find(v => v.lang.includes('en') && v.name.toLowerCase().includes('natural')) ||
                this.voices[0];
        }
        
        console.log('🔊 TTS 语音已加载:', this.voices.length, '个');
    },
    
    // 朗读文本
    speak(text, button) {
        if (!('speechSynthesis' in window)) {
            layer.msg('⚠️ 浏览器不支持语音朗读', { icon: 0 });
            return;
        }
        
        // 如果正在朗读，停止
        if (this.speaking) {
            this.stop();
            // 如果点击的是同一个按钮，只是停止
            if (this.currentButton === button) {
                return;
            }
        }
        
        // 清理 Markdown 格式，只保留纯文本
        const cleanText = this.cleanMarkdown(text);
        
        if (!cleanText.trim()) {
            layer.msg('没有可朗读的内容', { icon: 0 });
            return;
        }
        
        // 创建语音实例
        const utterance = new SpeechSynthesisUtterance(cleanText);
        
        // 设置语音参数
        if (this.selectedVoice) {
            utterance.voice = this.selectedVoice;
        }
        utterance.rate = parseFloat(localStorage.getItem('ttsRate') || '1.0');
        utterance.pitch = parseFloat(localStorage.getItem('ttsPitch') || '1.0');
        utterance.volume = parseFloat(localStorage.getItem('ttsVolume') || '1.0');
        
        // 事件处理
        utterance.onstart = () => {
            this.speaking = true;
            this.currentButton = button;
            this.updateButtonState(button, true);
        };
        
        utterance.onend = () => {
            this.speaking = false;
            this.currentButton = null;
            this.updateButtonState(button, false);
        };
        
        utterance.onerror = (event) => {
            console.error('TTS 错误:', event.error);
            this.speaking = false;
            this.currentButton = null;
            this.updateButtonState(button, false);
            if (event.error !== 'interrupted') {
                layer.msg('朗读出错: ' + event.error, { icon: 2 });
            }
        };
        
        // 开始朗读
        this.currentUtterance = utterance;
        speechSynthesis.speak(utterance);
    },
    
    // 停止朗读
    stop() {
        if ('speechSynthesis' in window) {
            speechSynthesis.cancel();
        }
        this.speaking = false;
        if (this.currentButton) {
            this.updateButtonState(this.currentButton, false);
        }
        this.currentButton = null;
        this.currentUtterance = null;
    },
    
    // 暂停/继续
    togglePause() {
        if (!this.speaking) return;
        
        if (speechSynthesis.paused) {
            speechSynthesis.resume();
        } else {
            speechSynthesis.pause();
        }
    },
    
    // 更新按钮状态
    updateButtonState(button, isSpeaking) {
        if (!button) return;
        
        if (isSpeaking) {
            button.classList.add('speaking');
            button.title = '停止朗读';
            button.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <rect x="6" y="6" width="12" height="12" rx="2"/>
                </svg>
            `;
        } else {
            button.classList.remove('speaking');
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
            // 移除代码块
            .replace(/```[\s\S]*?```/g, '')
            // 移除行内代码
            .replace(/`[^`]+`/g, '')
            // 移除链接，保留文本
            .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
            // 移除图片
            .replace(/!\[.*?\]\(.*?\)/g, '')
            // 移除加粗
            .replace(/\*\*([^*]+)\*\*/g, '$1')
            // 移除斜体
            .replace(/\*([^*]+)\*/g, '$1')
            .replace(/_([^_]+)_/g, '$1')
            // 移除标题标记
            .replace(/^#+\s+/gm, '')
            // 移除列表标记
            .replace(/^[\s]*[-*+]\s+/gm, '')
            .replace(/^[\s]*\d+\.\s+/gm, '')
            // 移除引用标记
            .replace(/^>\s+/gm, '')
            // 移除分隔线
            .replace(/^[-*_]{3,}$/gm, '')
            // 移除 HTML 标签
            .replace(/<[^>]+>/g, '')
            // 规范化空白
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    },
    
    // 显示语音设置
    showSettings() {
        if (this.voices.length === 0) {
            this.loadVoices();
        }
        
        // 按语言分组
        const zhVoices = this.voices.filter(v => v.lang.includes('zh'));
        const enVoices = this.voices.filter(v => v.lang.includes('en'));
        const otherVoices = this.voices.filter(v => !v.lang.includes('zh') && !v.lang.includes('en'));
        
        const buildVoiceOptions = (voices, label) => {
            if (voices.length === 0) return '';
            return `
                <optgroup label="${label}">
                    ${voices.map(v => `
                        <option value="${v.name}" ${this.selectedVoice?.name === v.name ? 'selected' : ''}>
                            ${v.name} (${v.lang})
                        </option>
                    `).join('')}
                </optgroup>
            `;
        };
        
        const currentRate = localStorage.getItem('ttsRate') || '1.0';
        const currentPitch = localStorage.getItem('ttsPitch') || '1.0';
        const currentVolume = localStorage.getItem('ttsVolume') || '1.0';
        
        const content = `
            <div style="padding: 20px;">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">🎙️ 语音</label>
                    <select id="ttsVoiceSelect" style="width: 100%; padding: 8px; background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: 6px; color: inherit;">
                        ${buildVoiceOptions(zhVoices, '中文')}
                        ${buildVoiceOptions(enVoices, 'English')}
                        ${buildVoiceOptions(otherVoices, '其他')}
                    </select>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">⏩ 语速: <span id="rateValue">${currentRate}x</span></label>
                    <input type="range" id="ttsRateSlider" min="0.5" max="2" step="0.1" value="${currentRate}" 
                           style="width: 100%;" onchange="document.getElementById('rateValue').textContent = this.value + 'x'">
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">🎵 音调: <span id="pitchValue">${currentPitch}</span></label>
                    <input type="range" id="ttsPitchSlider" min="0.5" max="2" step="0.1" value="${currentPitch}"
                           style="width: 100%;" onchange="document.getElementById('pitchValue').textContent = this.value">
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">🔊 音量: <span id="volumeValue">${Math.round(currentVolume * 100)}%</span></label>
                    <input type="range" id="ttsVolumeSlider" min="0" max="1" step="0.1" value="${currentVolume}"
                           style="width: 100%;" onchange="document.getElementById('volumeValue').textContent = Math.round(this.value * 100) + '%'">
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
            end: () => {
                // 保存设置
                const voice = document.getElementById('ttsVoiceSelect')?.value;
                const rate = document.getElementById('ttsRateSlider')?.value;
                const pitch = document.getElementById('ttsPitchSlider')?.value;
                const volume = document.getElementById('ttsVolumeSlider')?.value;
                
                if (voice) {
                    this.selectedVoice = this.voices.find(v => v.name === voice);
                    localStorage.setItem('ttsVoice', voice);
                }
                if (rate) localStorage.setItem('ttsRate', rate);
                if (pitch) localStorage.setItem('ttsPitch', pitch);
                if (volume) localStorage.setItem('ttsVolume', volume);
            }
        });
    },
    
    // 试听
    testVoice() {
        const voiceName = document.getElementById('ttsVoiceSelect')?.value;
        const rate = parseFloat(document.getElementById('ttsRateSlider')?.value || '1.0');
        const pitch = parseFloat(document.getElementById('ttsPitchSlider')?.value || '1.0');
        const volume = parseFloat(document.getElementById('ttsVolumeSlider')?.value || '1.0');
        
        this.stop();
        
        const testText = '你好，这是一段测试语音。Hello, this is a test voice.';
        const utterance = new SpeechSynthesisUtterance(testText);
        
        const voice = this.voices.find(v => v.name === voiceName);
        if (voice) utterance.voice = voice;
        utterance.rate = rate;
        utterance.pitch = pitch;
        utterance.volume = volume;
        
        speechSynthesis.speak(utterance);
    }
};

// 页面加载时初始化
document.addEventListener('DOMContentLoaded', () => {
    ChatTTS.init();
});

// 导出
window.ChatTTS = ChatTTS;
