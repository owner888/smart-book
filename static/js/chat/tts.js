/**
 * TTS 朗读模块 - 支持 Google Cloud TTS 和浏览器 TTS
 */

const ChatTTS = {
    // 当前朗读状态
    speaking: false,
    paused: false,  // 暂停状态
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
    
    // 朗读文本（支持暂停/续播）
    async speak(text, button, messageId) {
        // 同一条消息的点击处理：暂停/续播
        if (messageId && this.currentMessageId === messageId) {
            if (this.paused) {
                // 已暂停，继续播放
                this.resume(button);
                return;
            } else if (this.speaking) {
                // 正在播放，暂停
                this.pause(button);
                return;
            }
        }
        
        // 停止当前播放
        this.stop();
        
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
    
    // 暂停
    pause(button) {
        if (this.currentAudio) {
            this.currentAudio.pause();
        }
        if ('speechSynthesis' in window) {
            speechSynthesis.pause();
        }
        this.speaking = false;
        this.paused = true;
        this.updateButtonState(button, false, false, true);  // 暂停状态
    },
    
    // 继续播放
    resume(button) {
        if (this.currentAudio) {
            this.currentAudio.play();
        }
        if ('speechSynthesis' in window) {
            speechSynthesis.resume();
        }
        this.speaking = true;
        this.paused = false;
        this.updateButtonState(button, true);  // 播放状态
    },
    
    // 分割长文本为小于 4500 字节的片段（留些余量）
    splitTextForTTS(text, maxBytes = 4500) {
        const chunks = [];
        let currentChunk = '';
        
        // 按句子分割
        const sentences = text.split(/(?<=[。！？.!?])\s*/);
        
        for (const sentence of sentences) {
            // 检查当前句子加上已有内容是否超过限制
            const testChunk = currentChunk + sentence;
            const byteLength = new Blob([testChunk]).size;
            
            if (byteLength > maxBytes) {
                // 如果当前块不为空，保存它
                if (currentChunk.trim()) {
                    chunks.push(currentChunk.trim());
                }
                
                // 如果单个句子就超过限制，需要进一步分割
                if (new Blob([sentence]).size > maxBytes) {
                    // 按字符分割
                    let remaining = sentence;
                    while (remaining) {
                        let partLength = remaining.length;
                        let part = remaining;
                        
                        // 减少长度直到满足字节限制
                        while (new Blob([part]).size > maxBytes && partLength > 0) {
                            partLength = Math.floor(partLength * 0.8);
                            part = remaining.substring(0, partLength);
                        }
                        
                        chunks.push(part.trim());
                        remaining = remaining.substring(partLength);
                    }
                    currentChunk = '';
                } else {
                    currentChunk = sentence;
                }
            } else {
                currentChunk = testChunk;
            }
        }
        
        // 添加最后一个块
        if (currentChunk.trim()) {
            chunks.push(currentChunk.trim());
        }
        
        return chunks;
    },
    
    // 使用云端 TTS（支持长文本分批处理）
    async speakWithCloud(text, button, messageId) {
        try {
            this.updateButtonState(button, true, true);  // 加载中状态
            
            const voice = localStorage.getItem('ttsCloudVoice') || null;
            const rate = parseFloat(localStorage.getItem('ttsRate') || '1.0');
            
            // 分割长文本
            const chunks = this.splitTextForTTS(text);
            console.log(`🔊 TTS: 文本分割为 ${chunks.length} 个片段`);
            
            // 存储所有音频数据
            const audioDataList = [];
            let totalCharCount = 0;
            let totalCost = 0;
            let usedVoice = voice;
            
            // 依次请求每个片段
            for (let i = 0; i < chunks.length; i++) {
                const chunk = chunks[i];
                console.log(`🔊 TTS: 处理片段 ${i + 1}/${chunks.length} (${chunk.length} 字符)`);
                
                const response = await fetch(`${ChatConfig.API_BASE}/api/tts/synthesize`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ text: chunk, voice, rate }),
                });
                
                const data = await response.json();
                
                if (data.error) {
                    throw new Error(data.error);
                }
                
                audioDataList.push(`data:audio/mp3;base64,${data.audio}`);
                totalCharCount += data.charCount || chunk.length;
                totalCost += data.cost || 0;
                usedVoice = data.voice || voice;
            }
            
            // 按顺序播放所有音频
            this.playAudioSequence(audioDataList, button, messageId, 0);
            
            // 显示总消耗
            if (usedVoice) {
                const costFormatted = totalCost < 0.01 ? '<$0.01' : '$' + totalCost.toFixed(4);
                this.showCostInfo(button, usedVoice, totalCharCount, costFormatted);
            }
            
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
    
    // 按顺序播放音频片段
    playAudioSequence(audioDataList, button, messageId, index) {
        if (index >= audioDataList.length) {
            // 全部播放完成
            this.speaking = false;
            this.currentAudio = null;
            this.currentButton = null;
            this.currentMessageId = null;
            this.updateButtonState(button, false);
            return;
        }
        
        const audio = new Audio(audioDataList[index]);
        
        audio.onplay = () => {
            this.speaking = true;
            this.currentAudio = audio;
            this.currentButton = button;
            this.currentMessageId = messageId;
            this.updateButtonState(button, true);
        };
        
        audio.onended = () => {
            // 播放下一个片段
            this.playAudioSequence(audioDataList, button, messageId, index + 1);
        };
        
        audio.onerror = (e) => {
            console.error('音频播放错误:', e);
            this.speaking = false;
            this.updateButtonState(button, false);
            layer.msg('音频播放失败', { icon: 2 });
        };
        
        audio.play();
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
        this.paused = false;
        if (this.currentButton) {
            this.updateButtonState(this.currentButton, false);
        }
        this.currentButton = null;
        this.currentMessageId = null;
    },
    
    // 更新按钮状态
    updateButtonState(button, isSpeaking, isLoading = false, isPaused = false) {
        if (!button) return;
        
        if (isLoading) {
            button.classList.add('loading');
            button.classList.remove('speaking', 'paused');
            button.title = '加载中...';
            button.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin">
                    <circle cx="12" cy="12" r="10" stroke-dasharray="30 70"/>
                </svg>
            `;
        } else if (isPaused) {
            button.classList.remove('loading', 'speaking');
            button.classList.add('paused');
            button.title = '继续播放';
            button.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <polygon points="5 3 19 12 5 21 5 3"/>
                </svg>
            `;
        } else if (isSpeaking) {
            button.classList.remove('loading', 'paused');
            button.classList.add('speaking');
            button.title = '暂停';
            button.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <rect x="6" y="4" width="4" height="16" rx="1"/>
                    <rect x="14" y="4" width="4" height="16" rx="1"/>
                </svg>
            `;
        } else {
            button.classList.remove('loading', 'speaking', 'paused');
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
    
    // 清理 Markdown 格式和工具调用信息
    cleanMarkdown(text) {
        return text
            // 过滤工具调用信息（如 "> 🔧 执行工具: `search_book`" 和 "> ✅ 工具执行成功"）
            .replace(/^>\s*🔧.*$/gm, '')
            .replace(/^>\s*✅.*$/gm, '')
            .replace(/^>\s*❌.*$/gm, '')
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
    
    // 预估 TTS 消耗（供消息渲染时使用）
    estimateCost(text) {
        if (!text) return null;
        
        // 清理 Markdown，获取纯文本
        const cleanText = this.cleanMarkdown(text);
        const charCount = cleanText.length;
        
        if (charCount === 0) return null;
        
        // 获取当前语音设置
        const savedVoice = localStorage.getItem('ttsCloudVoice') || 'cmn-CN-Wavenet-D';
        const shortVoice = savedVoice.replace(/^(cmn-CN|cmn-TW|en-US)-/, '');
        
        // 判断语音类型并计算费用
        const isWavenet = savedVoice.includes('Wavenet');
        const isNeural2 = savedVoice.includes('Neural2');
        const pricePerMillion = (isWavenet || isNeural2) ? 16 : 4;
        
        const cost = (charCount / 1000000) * pricePerMillion;
        const costFormatted = cost < 0.01 ? '<$0.01' : '$' + cost.toFixed(4);
        
        return {
            voice: shortVoice,
            charCount: charCount,
            cost: costFormatted
        };
    },
    
    // 显示消耗信息（使用 usage-container 样式）- 播放后显示实际消耗
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
    
    // 对话模式专用：朗读并回调（支持 onEnd 和 onError 回调）
    async speakForConversation(text, options = {}) {
        const { onEnd, onError } = options;
        
        console.log('🔊 speakForConversation: 收到原始文本:', typeof text, text ? text.length : 0);
        console.log('🔊 speakForConversation: 原始文本内容（前100字符）:', text ? text.substring(0, 100) : 'null/undefined');
        
        // 如果没有文本，直接返回
        if (!text) {
            console.log('🔊 speakForConversation: 没有文本参数');
            if (onEnd) onEnd();
            return;
        }
        
        // 停止当前播放
        this.stop();
        
        // 清理 Markdown（但不要过度清理）
        let cleanText = text;
        try {
            cleanText = this.cleanMarkdown(text);
        } catch (e) {
            console.error('🔊 cleanMarkdown 错误:', e);
            cleanText = text.replace(/<[^>]+>/g, '').trim();  // 简单清理
        }
        
        console.log('🔊 speakForConversation: 清理后文本长度:', cleanText.length);
        
        if (!cleanText.trim()) {
            console.log('🔊 speakForConversation: 清理后文本为空');
            // 如果清理后为空，尝试直接使用原文本（去除HTML）
            cleanText = text.replace(/<[^>]+>/g, '').trim();
            console.log('🔊 speakForConversation: 使用简单清理后长度:', cleanText.length);
            if (!cleanText.trim()) {
                if (onEnd) onEnd();
                return;
            }
        }
        
        console.log('🔊 speakForConversation: 使用云端TTS:', this.useCloudTTS);
        console.log('🔊 speakForConversation: 最终文本长度:', cleanText.length);
        
        // 返回 Promise，确保等待完成
        return new Promise((resolve) => {
            const wrappedOnEnd = () => {
                console.log('🔊 speakForConversation: 播放真正完成');
                if (onEnd) onEnd();
                resolve();
            };
            
            const wrappedOnError = (err) => {
                console.log('🔊 speakForConversation: 播放错误', err);
                if (onError) onError(err);
                resolve();
            };
            
            // 优先使用云端 TTS
            if (this.useCloudTTS) {
                this.speakWithCloudCallback(cleanText, wrappedOnEnd, wrappedOnError);
            } else {
                this.speakWithBrowserCallback(cleanText, wrappedOnEnd, wrappedOnError);
            }
        });
    },
    
    // 云端 TTS 带回调
    async speakWithCloudCallback(text, onEnd, onError) {
        try {
            const voice = localStorage.getItem('ttsCloudVoice') || null;
            const rate = parseFloat(localStorage.getItem('ttsRate') || '1.0');
            
            // 分割长文本
            const chunks = this.splitTextForTTS(text);
            console.log(`🔊 对话TTS: 文本分割为 ${chunks.length} 个片段`);
            
            // 存储所有音频数据
            const audioDataList = [];
            
            // 依次请求每个片段
            for (let i = 0; i < chunks.length; i++) {
                const chunk = chunks[i];
                console.log(`🔊 对话TTS: 请求片段 ${i + 1}/${chunks.length}, 字符数: ${chunk.length}`);
                
                const response = await fetch(`${ChatConfig.API_BASE}/api/tts/synthesize`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ text: chunk, voice, rate }),
                });
                
                console.log(`🔊 对话TTS: 响应状态: ${response.status}`);
                
                const data = await response.json();
                
                console.log(`🔊 对话TTS: 响应数据:`, data.error ? `错误: ${data.error}` : `音频长度: ${data.audio?.length || 0}`);
                
                if (data.error) {
                    throw new Error(data.error);
                }
                
                audioDataList.push(`data:audio/mp3;base64,${data.audio}`);
            }
            
            console.log(`🔊 对话TTS: 所有音频已准备，共 ${audioDataList.length} 个`);
            
            // 按顺序播放所有音频
            this.playAudioSequenceCallback(audioDataList, 0, onEnd, onError);
            
        } catch (e) {
            console.error('对话TTS云端错误:', e);
            
            // 如果云端失败，尝试使用浏览器 TTS
            if ('speechSynthesis' in window) {
                console.log('降级到浏览器TTS');
                this.speakWithBrowserCallback(text, onEnd, onError);
            } else {
                if (onError) onError(e);
            }
        }
    },
    
    // 按顺序播放音频片段（带回调）
    playAudioSequenceCallback(audioDataList, index, onEnd, onError) {
        if (index >= audioDataList.length) {
            // 全部播放完成
            this.speaking = false;
            this.currentAudio = null;
            console.log('🔊 对话TTS: 播放完成');
            if (onEnd) onEnd();
            return;
        }
        
        const audio = new Audio(audioDataList[index]);
        
        audio.onplay = () => {
            this.speaking = true;
            this.currentAudio = audio;
        };
        
        audio.onended = () => {
            // 播放下一个片段
            this.playAudioSequenceCallback(audioDataList, index + 1, onEnd, onError);
        };
        
        audio.onerror = (e) => {
            console.error('对话TTS音频播放错误:', e);
            this.speaking = false;
            if (onError) onError(e);
        };
        
        audio.play().catch(e => {
            console.error('对话TTS播放失败:', e);
            if (onError) onError(e);
        });
    },
    
    // 浏览器 TTS 带回调
    speakWithBrowserCallback(text, onEnd, onError) {
        console.log('🔊 speakWithBrowserCallback: 开始, 文本长度:', text.length);
        
        if (!('speechSynthesis' in window)) {
            console.error('🔊 浏览器不支持语音朗读');
            if (onError) onError(new Error('浏览器不支持语音朗读'));
            return;
        }
        
        // 先取消任何正在进行的语音
        speechSynthesis.cancel();
        
        const utterance = new SpeechSynthesisUtterance(text);
        
        if (this.selectedBrowserVoice) {
            utterance.voice = this.selectedBrowserVoice;
            console.log('🔊 使用语音:', this.selectedBrowserVoice.name);
        } else {
            console.log('🔊 使用默认语音');
        }
        
        utterance.rate = parseFloat(localStorage.getItem('ttsRate') || '1.0');
        utterance.pitch = parseFloat(localStorage.getItem('ttsPitch') || '1.0');
        utterance.volume = parseFloat(localStorage.getItem('ttsVolume') || '1.0');
        utterance.lang = 'zh-CN';
        
        console.log('🔊 语音参数: rate=', utterance.rate, ', pitch=', utterance.pitch, ', volume=', utterance.volume);
        
        utterance.onstart = () => {
            this.speaking = true;
            console.log('🔊 浏览器TTS: 开始播放');
        };
        
        utterance.onend = () => {
            this.speaking = false;
            console.log('🔊 浏览器TTS: 播放完成 (onend)');
            if (onEnd) onEnd();
        };
        
        utterance.onerror = (event) => {
            this.speaking = false;
            console.error('🔊 浏览器TTS错误:', event.error, event);
            if (event.error !== 'interrupted') {
                if (onError) onError(new Error(event.error));
            } else {
                // 被中断不算错误，直接回调结束
                if (onEnd) onEnd();
            }
        };
        
        // 检查 speechSynthesis 状态
        console.log('🔊 speechSynthesis.speaking:', speechSynthesis.speaking);
        console.log('🔊 speechSynthesis.pending:', speechSynthesis.pending);
        console.log('🔊 speechSynthesis.paused:', speechSynthesis.paused);
        
        // 开始播放
        speechSynthesis.speak(utterance);
        
        console.log('🔊 speak() 已调用');
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
