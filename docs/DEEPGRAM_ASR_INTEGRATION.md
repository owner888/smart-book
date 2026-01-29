# Deepgram ASR 集成文档

本文档介绍如何在 Smart Book 项目中使用 Deepgram 语音识别服务。

## 目录

- [简介](#简介)
- [为什么选择 Deepgram](#为什么选择-deepgram)
- [快速开始](#快速开始)
- [配置说明](#配置说明)
- [API 使用](#api-使用)
- [代码示例](#代码示例)
- [模型选择](#模型选择)
- [费用说明](#费用说明)
- [故障排除](#故障排除)

## 简介

Deepgram 是一个高性能的语音识别（ASR）服务，提供：
- 🚀 低延迟、高精度的语音识别
- 🌍 支持 30+ 种语言
- 🎯 多种专业模型可选
- 💰 竞争力的定价
- 📊 详细的识别结果（包括时间戳、置信度等）

## 为什么选择 Deepgram

### 与 Google Speech-to-Text 对比

| 特性 | Deepgram | Google ASR |
|------|----------|------------|
| **准确率** | 非常高（Nova-2 模型） | 高 |
| **延迟** | 低（~300ms） | 中等 |
| **语言支持** | 30+ 种 | 125+ 种 |
| **定价** | $0.0043/分钟 | $0.024/分钟 |
| **实时识别** | ✅ 原生支持 | ✅ 支持 |
| **单词时间戳** | ✅ 包含 | ✅ 包含 |
| **说话人识别** | ✅ 内置 | ❌ 需要额外配置 |
| **智能格式化** | ✅ 内置 | ⚠️ 部分支持 |

**推荐场景：**
- ✅ 中文、英文、日文等主流语言识别
- ✅ 需要高精度和低延迟
- ✅ 预算有限的项目
- ⚠️ 如需小语种支持，建议使用 Google ASR

## 快速开始

### 1. 获取 API Key

1. 访问 [Deepgram Console](https://console.deepgram.com)
2. 注册/登录账号
3. 创建新项目
4. 生成 API Key
5. 复制 API Key（格式：`Token_xxxxx...`）

### 2. 配置环境变量

编辑 `.env` 文件，添加以下配置：

```bash
# Deepgram API Key
DEEPGRAM_API_KEY=your_deepgram_api_key_here

# ASR 提供商配置
ASR_PROVIDER=deepgram    # 可选：google | deepgram
ASR_MODEL=nova-2         # 可选：nova-2 | nova | enhanced | base | whisper
```

### 3. 重启服务器

```bash
php server.php restart
```

### 4. 测试集成

```bash
# 运行测试脚本
php tests/test_deepgram_asr.php
```

## 配置说明

### 环境变量

| 变量名 | 说明 | 默认值 | 可选值 |
|--------|------|--------|--------|
| `DEEPGRAM_API_KEY` | Deepgram API 密钥 | - | 必填 |
| `ASR_PROVIDER` | ASR 服务提供商 | `google` | `google`, `deepgram` |
| `ASR_MODEL` | Deepgram 模型 | `nova-2` | `nova-2`, `nova`, `enhanced`, `base`, `whisper` |

### 支持的语言

```php
// 主要语言
'zh-CN' => '中文（简体）',
'zh-TW' => '中文（繁体）',
'en-US' => 'English (US)',
'ja'    => '日本語',
'ko'    => '한국어',
'es'    => 'Español',
'fr'    => 'Français',
'de'    => 'Deutsch',
// ... 更多语言
```

查看完整语言列表：
```bash
curl http://localhost:8081/api/asr/languages
```

### 支持的音频格式

- WAV (LINEAR16)
- MP3
- FLAC
- WebM Opus
- OGG Opus
- M4A
- AAC

## API 使用

### 1. 获取 ASR 配置

**请求：**
```bash
GET /api/asr/config
```

**响应：**
```json
{
  "success": true,
  "data": {
    "provider": "deepgram",
    "default_language": "zh-CN",
    "default_model": "nova-2",
    "languages": {
      "zh-CN": "中文（简体）",
      "en-US": "English (US)",
      ...
    },
    "models": {
      "nova-2": "Nova-2 (最新、最准确)",
      "nova": "Nova (平衡性能)",
      ...
    }
  }
}
```

### 2. 语音识别

**请求：**
```bash
POST /api/asr/recognize
Content-Type: application/json

{
  "audio": "base64_encoded_audio_data",
  "encoding": "WEBM_OPUS",
  "sample_rate": 48000,
  "language": "zh-CN",
  "model": "nova-2"
}
```

**响应：**
```json
{
  "success": true,
  "data": {
    "transcript": "你好，这是一段测试音频。",
    "confidence": 98.5,
    "language": "zh-CN",
    "duration": 3.2,
    "cost": 0.00023,
    "costFormatted": "<$0.01",
    "provider": "deepgram",
    "words": [
      {
        "word": "你好",
        "start": 0.5,
        "end": 0.9,
        "confidence": 0.99
      },
      ...
    ],
    "request_id": "550e8400-e29b-41d4-a716-446655440000"
  }
}
```

### 3. 获取支持的语言

**请求：**
```bash
GET /api/asr/languages
```

**响应：**
```json
{
  "success": true,
  "data": {
    "languages": {...},
    "default": "zh-CN",
    "provider": "deepgram",
    "models": {...},
    "defaultModel": "nova-2"
  }
}
```

## 代码示例

### PHP 客户端使用

```php
use SmartBook\AI\DeepgramASRClient;

// 初始化客户端
$client = new DeepgramASRClient();

// 语音识别
$result = $client->recognize(
    audioContent: $base64Audio,
    encoding: 'WEBM_OPUS',
    sampleRateHertz: 48000,
    languageCode: 'zh-CN',
    model: 'nova-2'
);

echo "识别结果: {$result['transcript']}\n";
echo "置信度: {$result['confidence']}%\n";
echo "费用: {$result['costFormatted']}\n";
```

### JavaScript 客户端使用

```javascript
// 录制音频
const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
const mediaRecorder = new MediaRecorder(stream, {
  mimeType: 'audio/webm;codecs=opus'
});

// 收集音频数据
const audioChunks = [];
mediaRecorder.ondataavailable = (e) => audioChunks.push(e.data);

// 开始录制
mediaRecorder.start();

// 停止录制并识别
mediaRecorder.onstop = async () => {
  const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
  const reader = new FileReader();
  
  reader.onloadend = async () => {
    const base64Audio = reader.result.split(',')[1];
    
    // 调用识别 API
    const response = await fetch('/api/asr/recognize', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        audio: base64Audio,
        encoding: 'WEBM_OPUS',
        sample_rate: 48000,
        language: 'zh-CN',
        model: 'nova-2'
      })
    });
    
    const result = await response.json();
    console.log('识别结果:', result.data.transcript);
  };
  
  reader.readAsDataURL(audioBlob);
};

// 3秒后停止录制
setTimeout(() => mediaRecorder.stop(), 3000);
```

### cURL 示例

```bash
# 1. 准备音频文件（转换为 base64）
AUDIO_BASE64=$(base64 -i test.wav)

# 2. 调用识别 API
curl -X POST http://localhost:8081/api/asr/recognize \
  -H "Content-Type: application/json" \
  -d "{
    \"audio\": \"$AUDIO_BASE64\",
    \"encoding\": \"LINEAR16\",
    \"sample_rate\": 16000,
    \"language\": \"zh-CN\",
    \"model\": \"nova-2\"
  }"
```

## 模型选择

### Nova-2（推荐）
- **优点：** 最高准确率，最新技术
- **适用：** 生产环境、高要求场景
- **费用：** $0.0043/分钟
- **语言：** 支持所有主流语言

### Nova
- **优点：** 性能与成本平衡
- **适用：** 一般场景
- **费用：** $0.0036/分钟

### Enhanced
- **优点：** 增强的电话音质识别
- **适用：** 电话录音、音质较差的场景
- **费用：** $0.0119/分钟

### Base
- **优点：** 基础模型，成本最低
- **适用：** 测试、开发环境
- **费用：** $0.0125/分钟

### Whisper
- **优点：** OpenAI Whisper 模型
- **适用：** 需要与 Whisper 兼容的场景
- **费用：** $0.0048/分钟

**选择建议：**
1. 生产环境 → `nova-2`
2. 成本优先 → `nova`
3. 音质较差 → `enhanced`
4. 测试开发 → `base`

## 费用说明

### 计费方式
按音频时长计费（分钟）

### 价格对比

| 模型 | 价格/分钟 | 100分钟 | 1000分钟 |
|------|-----------|---------|----------|
| Nova-2 | $0.0043 | $0.43 | $4.30 |
| Nova | $0.0036 | $0.36 | $3.60 |
| Enhanced | $0.0119 | $1.19 | $11.90 |
| Base | $0.0125 | $1.25 | $12.50 |
| Whisper | $0.0048 | $0.48 | $4.80 |

### 与 Google 对比

| 服务 | 价格/分钟 | 1000分钟成本 | 节省 |
|------|-----------|-------------|------|
| Deepgram Nova-2 | $0.0043 | $4.30 | - |
| Google ASR | $0.024 | $24.00 | **82%** ↓ |

### 免费额度
- 新用户：$200 免费额度
- 约等于：46,500 分钟（Nova-2）
- 有效期：3个月

## 故障排除

### 1. API Key 无效

**错误：**
```
Deepgram API Key 未配置
```

**解决：**
1. 检查 `.env` 文件中是否配置了 `DEEPGRAM_API_KEY`
2. 确保 API Key 格式正确（通常以 `Token_` 开头）
3. 验证 API Key 是否有效（访问 Deepgram Console）

### 2. 音频格式不支持

**错误：**
```
Deepgram API 错误 (400): Unsupported audio format
```

**解决：**
1. 确认 `encoding` 参数正确
2. 使用支持的格式：WAV, MP3, FLAC, WebM, OGG
3. 检查音频文件是否损坏

### 3. 识别结果为空

**可能原因：**
- 音频质量太差
- 音频中无语音内容
- 语言设置错误

**解决：**
1. 检查音频质量
2. 尝试不同的模型（如 `enhanced`）
3. 设置正确的语言代码

### 4. 费用超出预期

**解决：**
1. 使用更便宜的模型（如 `nova` 代替 `nova-2`）
2. 压缩音频以减少时长
3. 实现音频预处理（静音检测）
4. 监控使用量（在 Deepgram Console 查看）

### 5. 请求超时

**解决：**
1. 增加超时时间（在代码中设置）
2. 分段处理长音频
3. 检查网络连接
4. 考虑使用实时流式识别

## 最佳实践

### 1. 音频预处理
```php
// 示例：检测静音并分段
function preprocessAudio($audioData) {
    // 移除静音部分
    // 分段长音频
    // 标准化音量
    return $processedAudio;
}
```

### 2. 错误处理
```php
try {
    $result = $client->recognize($audio, ...);
} catch (Exception $e) {
    // 记录错误
    Logger::error('ASR 失败', ['error' => $e->getMessage()]);
    
    // 降级处理
    if ($e->getMessage() === 'Deepgram API Key 未配置') {
        // 切换到 Google ASR
        $client = new GoogleASRClient();
        $result = $client->recognize($audio, ...);
    }
}
```

### 3. 缓存结果
```php
$cacheKey = 'asr:' . md5($audioData);
$result = cache()->remember($cacheKey, 3600, function() use ($client, $audio) {
    return $client->recognize($audio, ...);
});
```

### 4. 成本优化
- 使用 `nova` 模型而非 `nova-2`（降低 16% 成本）
- 实现音频压缩
- 缓存重复识别的结果
- 监控每日使用量

## 相关链接

- [Deepgram 官网](https://deepgram.com)
- [Deepgram 文档](https://developers.deepgram.com)
- [API 参考](https://developers.deepgram.com/reference)
- [定价说明](https://deepgram.com/pricing)
- [获取 API Key](https://console.deepgram.com)
- [GitHub - Smart Book](https://github.com/owner888/smart-book)

## 更新日志

### v1.0.0 (2026-01-29)
- ✨ 初始集成 Deepgram ASR
- ✅ 支持多种语言和模型
- 📊 提供详细的识别结果
- 🧪 添加测试脚本
- 📝 完整的文档

---

如有问题或建议，欢迎提交 Issue 或 Pull Request！
