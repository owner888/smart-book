/**
 * ASR 语音识别模块 - 支持 Google Cloud Speech-to-Text 和浏览器 Web Speech API
 * 包含对话模式（持续监听、自动发送、TTS 回复）
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
    
    // ========== 对话模式 ==========
    conversationMode: false,      // 是否在对话模式中
    conversationActive: false,    // 对话是否正在进行
    silenceTimer: null,           // 静默计时器
    silenceTimeout: 2000,         // 后备静默超时时间（毫秒）
    currentTranscript: '',        // 当前累积的文本
    waitingForResponse: false,    // 是否在等待 AI 回复
    autoTTS: true,                // 自动播放 TTS
    smartDetection: true,         // 智能检测问题完整性
    minSentenceLength: 3,         // 最短句子长度
    
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
    },
    
    // ========== 对话模式功能 ==========
    
    // 开始对话模式
    startConversation() {
        if (this.conversationActive) {
            this.stopConversation();
            return;
        }
        
        this.conversationMode = true;
        this.conversationActive = true;
        this.currentTranscript = '';
        this.waitingForResponse = false;
        
        // 更新 UI
        this.updateConversationUI(true);
        
        // 显示提示
        layer.msg('🎙️ 对话模式已开启，请开始说话', { icon: 1, time: 2000 });
        
        // 开始持续监听
        this.startContinuousListening();
    },
    
    // 停止对话模式
    stopConversation() {
        this.conversationMode = false;
        this.conversationActive = false;
        this.currentTranscript = '';
        this.waitingForResponse = false;
        
        // 停止录音
        this.stop();
        
        // 清除计时器
        if (this.silenceTimer) {
            clearTimeout(this.silenceTimer);
            this.silenceTimer = null;
        }
        
        // 停止 TTS
        if (typeof ChatTTS !== 'undefined') {
            ChatTTS.stop();
        }
        
        // 更新 UI
        this.updateConversationUI(false);
        
        layer.msg('🎙️ 对话模式已关闭', { icon: 0, time: 1500 });
    },
    
    // 开始持续监听
    startContinuousListening() {
        if (!this.conversationActive || this.waitingForResponse) return;
        
        // 初始化浏览器 ASR 为持续模式
        if (this.recognition) {
            this.recognition.continuous = true;  // 持续监听
            this.recognition.interimResults = true;
            
            // 重新绑定事件
            this.recognition.onresult = (event) => {
                this.handleConversationResult(event);
            };
            
            this.recognition.onend = () => {
                // 如果对话模式仍然激活，自动重新开始
                if (this.conversationActive && !this.waitingForResponse) {
                    setTimeout(() => {
                        this.restartListening();
                    }, 100);
                }
            };
            
            this.recognition.onerror = (event) => {
                console.warn('对话模式 ASR 错误:', event.error);
                if (event.error === 'no-speech') {
                    // 没有检测到语音，重新开始
                    if (this.conversationActive && !this.waitingForResponse) {
                        this.restartListening();
                    }
                } else if (event.error === 'aborted') {
                    // 被中止，可能是因为我们停止了
                } else {
                    layer.msg('语音识别错误: ' + event.error, { icon: 2 });
                }
            };
        }
        
        this.restartListening();
    },
    
    // 重新开始监听
    restartListening() {
        if (!this.conversationActive || this.waitingForResponse) return;
        
        try {
            this.recognition.start();
            this.recording = true;
            console.log('🎤 持续监听中...');
        } catch (e) {
            // 可能已经在运行，先停止
            try {
                this.recognition.stop();
            } catch (e2) {}
            
            setTimeout(() => {
                if (this.conversationActive && !this.waitingForResponse) {
                    try {
                        this.recognition.start();
                        this.recording = true;
                    } catch (e3) {
                        console.warn('无法重启语音识别:', e3);
                    }
                }
            }, 200);
        }
    },
    
    // 处理对话模式的语音结果
    handleConversationResult(event) {
        let transcript = '';
        let isFinal = false;
        
        for (let i = event.resultIndex; i < event.results.length; i++) {
            transcript += event.results[i][0].transcript;
            if (event.results[i].isFinal) {
                isFinal = true;
            }
        }
        
        // 更新当前文本
        this.currentTranscript = transcript;
        
        // 显示在输入框中
        const input = document.getElementById('chatInput');
        if (input) {
            input.value = transcript;
            input.dispatchEvent(new Event('input'));
        }
        
        // 重置静默计时器
        if (this.silenceTimer) {
            clearTimeout(this.silenceTimer);
        }
        
        // 智能检测：如果句子已完整，使用较短的超时
        if (transcript.trim()) {
            let timeout = this.silenceTimeout;
            
            if (this.smartDetection) {
                const completeness = this.checkSentenceCompleteness(transcript.trim());
                if (completeness.isComplete) {
                    // 句子已完整，使用短超时（500ms）
                    timeout = 500;
                    console.log('🎤 检测到完整句子:', completeness.reason);
                } else if (completeness.confidence > 0.7) {
                    // 可能完整，使用中等超时
                    timeout = 800;
                }
            }
            
            this.silenceTimer = setTimeout(() => {
                this.handleSilence();
            }, timeout);
        }
    },
    
    // 检测句子是否完整
    checkSentenceCompleteness(text) {
        const result = {
            isComplete: false,
            confidence: 0,
            reason: ''
        };
        
        if (!text || text.length < this.minSentenceLength) {
            return result;
        }
        
        // 获取最后一个字符
        const lastChar = text.slice(-1);
        const lastTwoChars = text.slice(-2);
        
        // 1. 检测明确的句末标点
        const endPunctuations = ['？', '?', '。', '！', '!', '…'];
        if (endPunctuations.includes(lastChar)) {
            result.isComplete = true;
            result.confidence = 1;
            result.reason = '句末标点: ' + lastChar;
            return result;
        }
        
        // 2. 检测省略号
        if (text.endsWith('...') || text.endsWith('。。。')) {
            result.isComplete = true;
            result.confidence = 0.9;
            result.reason = '省略号结尾';
            return result;
        }
        
        // 3. 检测常见的问句结尾词（中文）
        const questionEndings = ['吗', '呢', '吧', '啊', '呀', '哦', '嘛', '么', '了'];
        if (questionEndings.includes(lastChar) && text.length > 5) {
            result.isComplete = true;
            result.confidence = 0.85;
            result.reason = '问句结尾词: ' + lastChar;
            return result;
        }
        
        // 4. 检测英文问句
        const englishQuestionWords = ['what', 'where', 'when', 'who', 'why', 'how', 'which', 'whose', 'whom'];
        const lowerText = text.toLowerCase();
        const startsWithQuestion = englishQuestionWords.some(w => lowerText.startsWith(w + ' '));
        if (startsWithQuestion && text.length > 10) {
            result.confidence = 0.75;
            result.reason = '英文疑问句';
            // 检查是否有动词等表示句子完整
            if (text.split(' ').length >= 4) {
                result.isComplete = true;
            }
        }
        
        // 5. 检测中文疑问词开头
        const chineseQuestionStarters = ['什么', '怎么', '为什么', '哪里', '哪个', '谁', '几', '多少', '是否', '能不能', '可不可以'];
        const hasQuestionStarter = chineseQuestionStarters.some(w => text.includes(w));
        if (hasQuestionStarter && text.length > 8) {
            result.confidence = 0.7;
            result.reason = '包含疑问词';
        }
        
        // 6. 检测祈使句/命令
        const imperativeStarters = ['请', '帮我', '给我', '告诉我', '说说', '讲讲', '介绍', '解释'];
        const hasImperativeStarter = imperativeStarters.some(w => text.startsWith(w));
        if (hasImperativeStarter && text.length > 6) {
            result.confidence = 0.65;
            result.reason = '祈使句';
        }
        
        return result;
    },
    
    // 处理静默（用户停止说话）
    async handleSilence() {
        const text = this.currentTranscript.trim();
        if (!text) {
            // 没有内容，继续监听
            return;
        }
        
        console.log('🎤 检测到静默，准备发送:', text);
        
        // 停止录音
        try {
            this.recognition.stop();
        } catch (e) {}
        this.recording = false;
        
        // 标记等待回复
        this.waitingForResponse = true;
        
        // 清空当前文本
        this.currentTranscript = '';
        
        // 更新状态显示
        this.updateConversationStatus('thinking');
        
        // 发送消息
        await this.sendAndWaitResponse(text);
    },
    
    // 发送消息并等待回复
    async sendAndWaitResponse(text) {
        try {
            // 设置输入框内容
            const input = document.getElementById('chatInput');
            if (input) {
                input.value = text;
            }
            
            // 保存 this 引用
            const self = this;
            
            // 设置对话模式回调（每次都重新设置，使用箭头函数绑定 this）
            window._conversationOnComplete = function(responseText) {
                console.log('🤖 收到回复，准备播放 TTS');
                console.log('   - 对话模式激活:', self.conversationActive);
                console.log('   - 自动TTS:', self.autoTTS);
                console.log('   - 回复长度:', responseText ? responseText.length : 0);
                
                // 只有在对话模式激活时才处理
                if (!self.conversationActive) {
                    console.log('对话模式已关闭，跳过 TTS');
                    return;
                }
                
                // 更新状态
                self.updateConversationStatus('speaking');
                
                // 播放 TTS（使用异步处理）
                if (self.autoTTS && responseText && typeof ChatTTS !== 'undefined') {
                    // 异步播放 TTS
                    self.playTTSAndContinue(responseText).catch(err => {
                        console.error('TTS 播放错误:', err);
                        self.continueListening();
                    });
                } else {
                    // 没有 TTS，直接继续监听
                    console.log('跳过TTS，直接继续监听');
                    self.continueListening();
                }
            };
            
            console.log('🎤 对话模式: 回调已设置，准备发送消息');
            
            // 调用 ChatMessage 发送消息
            if (typeof ChatMessage !== 'undefined' && typeof ChatMessage.sendMessage === 'function') {
                await ChatMessage.sendMessage();
            } else {
                // 降级：直接模拟点击发送
                const sendBtn = document.getElementById('sendBtn');
                if (sendBtn) {
                    sendBtn.click();
                }
            }
            
        } catch (e) {
            console.error('发送消息错误:', e);
            layer.msg('发送失败: ' + e.message, { icon: 2 });
            this.continueListening();
        }
    },
    
    // 播放 TTS 并继续监听
    async playTTSAndContinue(text) {
        try {
            console.log('🔊 playTTSAndContinue: 收到原始回复, 长度:', text ? text.length : 0);
            console.log('🔊 playTTSAndContinue: 原始回复前100字符:', text ? text.substring(0, 100) : 'null');
            
            // 提取纯文本（移除 Markdown 等）
            const plainText = this.extractPlainText(text);
            
            console.log('🔊 playTTSAndContinue: 提取后纯文本长度:', plainText ? plainText.length : 0);
            console.log('🔊 playTTSAndContinue: 纯文本前100字符:', plainText ? plainText.substring(0, 100) : 'null');
            
            if (!plainText) {
                console.log('🔊 playTTSAndContinue: 纯文本为空，跳过TTS');
                this.continueListening();
                return;
            }
            
            console.log('🔊 对话模式: 开始播放TTS, 文本长度:', plainText.length);
            
            // 使用对话专用的TTS方法（支持回调）
            await ChatTTS.speakForConversation(plainText, {
                onEnd: () => {
                    console.log('🔊 TTS 播放完成，继续监听');
                    this.continueListening();
                },
                onError: (err) => {
                    console.warn('TTS 错误:', err);
                    this.continueListening();
                }
            });
            
        } catch (e) {
            console.error('TTS 播放错误:', e);
            this.continueListening();
        }
    },
    
    // 提取纯文本
    extractPlainText(text) {
        if (!text) return '';
        
        // 首先过滤工具调用信息（重要！）
        text = text.replace(/^>\s*🔧.*$/gm, '');  // > 🔧 执行工具: xxx
        text = text.replace(/^>\s*✅.*$/gm, '');  // > ✅ 工具执行成功
        text = text.replace(/^>\s*❌.*$/gm, '');  // > ❌ 工具执行失败
        
        // 移除 Markdown 代码块
        text = text.replace(/```[\s\S]*?```/g, '');
        // 移除行内代码
        text = text.replace(/`[^`]+`/g, '');
        // 移除链接
        text = text.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1');
        // 移除图片
        text = text.replace(/!\[[^\]]*\]\([^)]+\)/g, '');
        // 移除 HTML 标签
        text = text.replace(/<[^>]+>/g, '');
        // 移除 Markdown 格式符号（粗体、斜体等）
        text = text.replace(/\*\*([^*]+)\*\*/g, '$1');
        text = text.replace(/\*([^*]+)\*/g, '$1');
        text = text.replace(/_([^_]+)_/g, '$1');
        // 移除标题符号
        text = text.replace(/^#+\s+/gm, '');
        // 移除引用符号
        text = text.replace(/^>\s*/gm, '');
        // 移除列表符号
        text = text.replace(/^[\s]*[-*+]\s+/gm, '');
        text = text.replace(/^[\s]*\d+\.\s+/gm, '');
        // 移除分隔线
        text = text.replace(/^[-*_]{3,}$/gm, '');
        // 压缩空白和换行
        text = text.replace(/\n{2,}/g, '\n');
        text = text.replace(/\s+/g, ' ').trim();
        
        // 限制长度（TTS 太长会有问题）
        if (text.length > 500) {
            text = text.substring(0, 500) + '...';
        }
        
        return text;
    },
    
    // 继续监听
    continueListening() {
        this.waitingForResponse = false;
        this.updateConversationStatus('listening');
        
        if (this.conversationActive) {
            setTimeout(() => {
                this.restartListening();
            }, 500);
        }
    },
    
    // 更新对话模式 UI
    updateConversationUI(active) {
        const btn = document.getElementById('conversationBtn');
        if (btn) {
            if (active) {
                btn.classList.add('active', 'conversation-active');
                btn.title = '停止对话';
            } else {
                btn.classList.remove('active', 'conversation-active', 'listening', 'thinking', 'speaking');
                btn.title = '开始对话';
                // 恢复原始图标
                btn.innerHTML = `
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M8 12h8"/>
                        <path d="M12 8v8"/>
                    </svg>
                `;
            }
        }
        
        // 同时更新 ASR 按钮状态
        const asrBtn = document.getElementById('asrBtn');
        if (asrBtn) {
            if (active) {
                asrBtn.style.display = 'none';
            } else {
                asrBtn.style.display = '';
            }
        }
    },
    
    // 更新对话状态显示
    updateConversationStatus(status) {
        const btn = document.getElementById('conversationBtn');
        if (!btn) return;
        
        switch (status) {
            case 'listening':
                btn.innerHTML = `
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                        <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                        <line x1="12" y1="19" x2="12" y2="23"/>
                        <line x1="8" y1="23" x2="16" y2="23"/>
                    </svg>
                `;
                btn.classList.remove('thinking', 'speaking');
                btn.classList.add('listening');
                break;
            case 'thinking':
                btn.innerHTML = `
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 6v6l4 2"/>
                    </svg>
                `;
                btn.classList.remove('listening', 'speaking');
                btn.classList.add('thinking');
                break;
            case 'speaking':
                btn.innerHTML = `
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                        <path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
                        <path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>
                    </svg>
                `;
                btn.classList.remove('listening', 'thinking');
                btn.classList.add('speaking');
                break;
        }
    },
    
    // 显示对话模式设置
    showConversationSettings() {
        const content = `
            <div style="padding: 20px;">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">⏱️ 静默超时（毫秒）</label>
                    <input type="range" id="silenceTimeoutRange" min="500" max="3000" step="100" value="${this.silenceTimeout}" 
                           style="width: 100%;" oninput="document.getElementById('silenceTimeoutValue').textContent = this.value + 'ms'">
                    <div style="display: flex; justify-content: space-between; font-size: 12px; color: var(--text-secondary);">
                        <span>快速 (0.5s)</span>
                        <span id="silenceTimeoutValue">${this.silenceTimeout}ms</span>
                        <span>慢速 (3s)</span>
                    </div>
                    <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">用户停止说话多久后自动发送</div>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="autoTTSCheck" ${this.autoTTS ? 'checked' : ''} style="width: 18px; height: 18px;">
                        <span style="font-weight: 500;">🔊 自动播放 AI 回复</span>
                    </label>
                </div>
                
                <div style="background: var(--bg-tertiary); border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                    <div style="font-weight: 500; margin-bottom: 8px;">💡 使用说明</div>
                    <ul style="font-size: 13px; color: var(--text-secondary); padding-left: 20px; margin: 0;">
                        <li>点击对话按钮开始语音对话</li>
                        <li>说完一句话后稍作停顿</li>
                        <li>系统会自动发送并获取回复</li>
                        <li>AI 回复会自动朗读</li>
                        <li>再次点击按钮结束对话</li>
                    </ul>
                </div>
                
                <button onclick="ChatASR.startConversation(); layer.closeAll();" 
                        style="width: 100%; padding: 12px; background: var(--accent-green); border: none; border-radius: 6px; color: white; cursor: pointer; font-size: 15px;">
                    🎙️ 开始对话
                </button>
            </div>
        `;
        
        layui.layer.open({
            type: 1,
            title: '🎙️ 对话模式设置',
            area: ['380px', 'auto'],
            shadeClose: true,
            content: content,
            end: () => {
                const timeout = document.getElementById('silenceTimeoutRange')?.value;
                const autoTTS = document.getElementById('autoTTSCheck')?.checked;
                
                if (timeout) {
                    this.silenceTimeout = parseInt(timeout);
                    localStorage.setItem('asrSilenceTimeout', timeout);
                }
                if (autoTTS !== undefined) {
                    this.autoTTS = autoTTS;
                    localStorage.setItem('asrAutoTTS', autoTTS);
                }
            }
        });
    }
};

// 初始化
document.addEventListener('DOMContentLoaded', () => ChatASR.init());

// 导出
window.ChatASR = ChatASR;
