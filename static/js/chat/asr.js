/**
 * ASR 语音识别模块 - 支持 Google Cloud Speech-to-Text 和浏览器 Web Speech API
 */

const ChatASR = {
    // 当前录音状态
    recording: false,
    mediaRecorder: null,
    audioChunks: [],
    stream: null,
    
    // 配置
    useCloudASR: false,  // 默认使用浏览器 ASR（云端 ASR 需要启用 Google Cloud Speech-to-Text API）
    cloudLanguages: null,
    selectedLanguage: 'cmn-Hans-CN',  // 默认中文
    
    // 浏览器 Web Speech API
    recognition: null,
    
    // 回调函数
    onResult: null,
    onError: null,
    onStateChange: null,
    
    // 初始化
    init() {
        // 加载云端语言列表
        this.loadCloudLanguages();
        
        // 从 localStorage 恢复设置
        this.useCloudASR = localStorage.getItem('asrUseCloud') === 'true';
        this.selectedLanguage = localStorage.getItem('asrLanguage') || 'cmn-Hans-CN';
        
        // 初始化浏览器 ASR（作为后备）
        this.initBrowserASR();
        
        console.log('🎤 ASR 模块已初始化');
    },
    
    // 初始化浏览器 Web Speech API
    initBrowserASR() {
        if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            this.recognition = new SpeechRecognition();
            this.recognition.continuous = false;
            this.recognition.interimResults = true;
            this.recognition.maxAlternatives = 1;
            
            // 根据选择的语言设置
            this.recognition.lang = this.getBrowserLang(this.selectedLanguage);
            
            this.recognition.onresult = (event) => {
                let transcript = '';
                let isFinal = false;
                
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    transcript += event.results[i][0].transcript;
                    if (event.results[i].isFinal) {
                        isFinal = true;
                    }
                }
                
                if (this.onResult) {
                    this.onResult(transcript, isFinal);
                }
            };
            
            this.recognition.onerror = (event) => {
                console.error('浏览器 ASR 错误:', event.error);
                this.recording = false;
                if (this.onStateChange) this.onStateChange(false);
                if (this.onError) this.onError(event.error);
            };
            
            this.recognition.onend = () => {
                this.recording = false;
                if (this.onStateChange) this.onStateChange(false);
            };
        }
    },
    
    // 将云端语言代码转换为浏览器语言代码
    getBrowserLang(cloudLang) {
        const mapping = {
            'cmn-Hans-CN': 'zh-CN',
            'cmn-Hant-TW': 'zh-TW',
            'yue-Hant-HK': 'zh-HK',
            'en-US': 'en-US',
            'en-GB': 'en-GB',
            'ja-JP': 'ja-JP',
            'ko-KR': 'ko-KR',
        };
        return mapping[cloudLang] || 'zh-CN';
    },
    
    // 加载云端语言列表
    async loadCloudLanguages() {
        try {
            const response = await fetch(`${ChatConfig.API_BASE}/api/asr/languages`);
            const data = await response.json();
            if (data.languages) {
                this.cloudLanguages = data.languages;
                console.log('🎤 云端语言已加载');
            }
        } catch (e) {
            console.warn('⚠️ 无法加载云端语言列表:', e.message);
        }
    },
    
    // 开始录音
    async start(onResult, onError, onStateChange) {
        this.onResult = onResult;
        this.onError = onError;
        this.onStateChange = onStateChange;
        
        if (this.useCloudASR) {
            await this.startCloudRecording();
        } else {
            this.startBrowserRecording();
        }
    },
    
    // 停止录音
    stop() {
        if (this.useCloudASR) {
            this.stopCloudRecording();
        } else {
            this.stopBrowserRecording();
        }
    },
    
    // 开始浏览器录音（Web Speech API）
    startBrowserRecording() {
        if (!this.recognition) {
            if (this.onError) this.onError('浏览器不支持语音识别');
            return;
        }
        
        this.recognition.lang = this.getBrowserLang(this.selectedLanguage);
        this.recording = true;
        if (this.onStateChange) this.onStateChange(true);
        
        try {
            this.recognition.start();
        } catch (e) {
            // 如果已经在录音，先停止再开始
            this.recognition.stop();
            setTimeout(() => {
                this.recognition.start();
            }, 100);
        }
    },
    
    // 停止浏览器录音
    stopBrowserRecording() {
        if (this.recognition) {
            this.recognition.stop();
        }
        this.recording = false;
        if (this.onStateChange) this.onStateChange(false);
    },
    
    // 开始云端录音（MediaRecorder）
    async startCloudRecording() {
        try {
            // 请求麦克风权限
            this.stream = await navigator.mediaDevices.getUserMedia({ 
                audio: {
                    channelCount: 1,
                    sampleRate: 48000,
                }
            });
            
            this.audioChunks = [];
            
            // 创建 MediaRecorder
            const mimeType = this.getSupportedMimeType();
            this.mediaRecorder = new MediaRecorder(this.stream, {
                mimeType: mimeType,
            });
            
            this.mediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    this.audioChunks.push(event.data);
                }
            };
            
            this.mediaRecorder.onstop = async () => {
                await this.processCloudAudio();
            };
            
            this.mediaRecorder.start();
            this.recording = true;
            if (this.onStateChange) this.onStateChange(true);
            
        } catch (e) {
            console.error('云端录音错误:', e);
            if (this.onError) this.onError(e.message);
            
            // 如果云端失败，尝试使用浏览器 ASR
            if (this.recognition) {
                layer.msg('云端 ASR 失败，使用浏览器语音识别', { icon: 0 });
                this.startBrowserRecording();
            }
        }
    },
    
    // 停止云端录音
    stopCloudRecording() {
        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
            this.mediaRecorder.stop();
        }
        
        if (this.stream) {
            this.stream.getTracks().forEach(track => track.stop());
            this.stream = null;
        }
        
        this.recording = false;
        if (this.onStateChange) this.onStateChange(false);
    },
    
    // 获取支持的 MIME 类型
    getSupportedMimeType() {
        const types = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/ogg;codecs=opus',
            'audio/mp4',
        ];
        
        for (const type of types) {
            if (MediaRecorder.isTypeSupported(type)) {
                return type;
            }
        }
        
        return 'audio/webm';
    },
    
    // 获取编码格式（与 Google ASR 对应）
    getEncoding(mimeType) {
        if (mimeType.includes('webm') && mimeType.includes('opus')) {
            return 'WEBM_OPUS';
        }
        if (mimeType.includes('ogg') && mimeType.includes('opus')) {
            return 'OGG_OPUS';
        }
        return 'WEBM_OPUS';
    },
    
    // 处理云端音频
    async processCloudAudio() {
        if (this.audioChunks.length === 0) {
            if (this.onError) this.onError('没有录到音频');
            return;
        }
        
        try {
            // 合并音频块
            const audioBlob = new Blob(this.audioChunks, { 
                type: this.mediaRecorder?.mimeType || 'audio/webm;codecs=opus' 
            });
            
            // 转换为 Base64
            const base64Audio = await this.blobToBase64(audioBlob);
            
            // 发送到服务器
            const response = await fetch(`${ChatConfig.API_BASE}/api/asr/recognize`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    audio: base64Audio,
                    encoding: this.getEncoding(audioBlob.type),
                    sample_rate: 48000,
                    language: this.selectedLanguage,
                }),
            });
            
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            if (data.transcript) {
                if (this.onResult) {
                    this.onResult(data.transcript, true);
                }
                
                // 显示消耗信息
                if (data.costFormatted) {
                    console.log(`🎤 ASR: ${data.language}, ${data.duration}s, ${data.costFormatted}`);
                }
            } else {
                if (this.onError) this.onError('未识别到语音');
            }
            
        } catch (e) {
            console.error('云端 ASR 错误:', e);
            if (this.onError) this.onError(e.message);
        }
    },
    
    // Blob 转 Base64
    blobToBase64(blob) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onloadend = () => {
                // 移除 data:audio/webm;base64, 前缀
                const base64 = reader.result.split(',')[1];
                resolve(base64);
            };
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });
    },
    
    // 显示设置
    showSettings() {
        const useCloud = this.useCloudASR;
        
        // 构建语言选项
        let languageOptions = '';
        const languages = this.cloudLanguages || {
            'cmn-Hans-CN': '普通话（中国大陆）',
            'cmn-Hant-TW': '普通话（台湾）',
            'en-US': 'English (US)',
            'en-GB': 'English (UK)',
            'ja-JP': '日本語',
            'ko-KR': '한국어',
        };
        
        for (const [code, name] of Object.entries(languages)) {
            const selected = this.selectedLanguage === code ? 'selected' : '';
            languageOptions += `<option value="${code}" ${selected}>${name}</option>`;
        }
        
        const content = `
            <div style="padding: 20px;">
                <div style="margin-bottom: 16px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="asrUseCloud" ${useCloud ? 'checked' : ''} style="width: 18px; height: 18px;">
                        <span style="font-weight: 500;">🎤 使用 Google Cloud ASR（更准确）</span>
                    </label>
                    <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">关闭后使用浏览器内置语音识别（免费）</div>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">🌐 识别语言</label>
                    <select id="asrLanguageSelect" style="width: 100%; padding: 8px; background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: 6px; color: inherit;">
                        ${languageOptions}
                    </select>
                </div>
                
                <button onclick="ChatASR.testASR()" style="width: 100%; padding: 10px; background: var(--accent-green); border: none; border-radius: 6px; color: white; cursor: pointer;">
                    🎤 测试
                </button>
            </div>
        `;
        
        layui.layer.open({
            type: 1,
            title: '🎤 语音输入设置',
            area: ['360px', 'auto'],
            shadeClose: true,
            content: content,
            end: () => {
                const useCloud = document.getElementById('asrUseCloud')?.checked;
                const language = document.getElementById('asrLanguageSelect')?.value;
                
                if (useCloud !== undefined) {
                    this.useCloudASR = useCloud;
                    localStorage.setItem('asrUseCloud', useCloud);
                }
                if (language) {
                    this.selectedLanguage = language;
                    localStorage.setItem('asrLanguage', language);
                    // 更新浏览器 ASR 的语言
                    if (this.recognition) {
                        this.recognition.lang = this.getBrowserLang(language);
                    }
                }
            }
        });
    },
    
    // 测试 ASR
    async testASR() {
        const useCloud = document.getElementById('asrUseCloud')?.checked;
        const language = document.getElementById('asrLanguageSelect')?.value;
        
        // 临时应用设置
        const originalUseCloud = this.useCloudASR;
        const originalLanguage = this.selectedLanguage;
        
        this.useCloudASR = useCloud;
        this.selectedLanguage = language;
        
        layer.msg('请说话...', { icon: 16, shade: 0.3, time: 0 });
        
        // 录音 3 秒
        this.start(
            (text, isFinal) => {
                if (isFinal) {
                    layer.closeAll();
                    layer.msg(`识别结果: ${text}`, { icon: 1, time: 3000 });
                }
            },
            (error) => {
                layer.closeAll();
                layer.msg(`错误: ${error}`, { icon: 2 });
                // 恢复设置
                this.useCloudASR = originalUseCloud;
                this.selectedLanguage = originalLanguage;
            },
            (isRecording) => {
                if (!isRecording && !this.useCloudASR) {
                    // 浏览器 ASR 自动停止
                }
            }
        );
        
        // 如果使用云端 ASR，3 秒后自动停止
        if (useCloud) {
            setTimeout(() => {
                this.stop();
            }, 3000);
        }
        
        // 恢复设置
        setTimeout(() => {
            this.useCloudASR = originalUseCloud;
            this.selectedLanguage = originalLanguage;
        }, 4000);
    },
    
    // 切换录音状态
    toggle(inputElement, button) {
        if (this.recording) {
            this.stop();
            return;
        }
        
        this.start(
            (text, isFinal) => {
                // 实时更新输入框
                if (inputElement) {
                    inputElement.value = text;
                    // 触发 input 事件以自动调整高度
                    inputElement.dispatchEvent(new Event('input'));
                }
                
                // 如果是最终结果且使用浏览器 ASR，可以自动停止
                if (isFinal && !this.useCloudASR) {
                    // 浏览器 ASR 会自动停止
                }
            },
            (error) => {
                layer.msg('语音识别错误: ' + error, { icon: 2 });
            },
            (isRecording) => {
                this.updateButtonState(button, isRecording);
            }
        );
    },
    
    // 更新按钮状态
    updateButtonState(button, isRecording) {
        if (!button) return;
        
        if (isRecording) {
            button.classList.add('recording');
            button.title = '停止录音';
            button.innerHTML = `
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                    <rect x="6" y="6" width="12" height="12" rx="2"/>
                </svg>
            `;
        } else {
            button.classList.remove('recording');
            button.title = '语音输入';
            button.innerHTML = `
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                    <line x1="12" y1="19" x2="12" y2="23"/>
                    <line x1="8" y1="23" x2="16" y2="23"/>
                </svg>
            `;
        }
    }
};

// 初始化
document.addEventListener('DOMContentLoaded', () => ChatASR.init());

// 导出
window.ChatASR = ChatASR;
