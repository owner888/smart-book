# Smart Book 📚

基于 RAG (检索增强生成) 的 AI 书籍助手，支持书籍问答、续写小说等功能。

## 功能特点

- 📖 **书籍问答** - 基于 RAG 混合检索（关键词 + 向量）
- ✍️ **续写小说** - 模仿原著风格创作新章节
- 💬 **通用聊天** - Gemini AI 对话
- 🌐 **Web 界面** - Layui 暗黑主题聊天界面
- ⚡ **实时流式** - WebSocket 流式输出

## 项目结构

```
smart-book/
├── calibre_ai_prompts.php    # AI API 客户端 + 提示词
├── calibre_rag.php           # RAG 实现（混合检索）
├── workerman_ai_server.php   # Workerman HTTP/WebSocket 服务
├── chat.html                 # Layui 聊天界面
├── continue_story.php        # 续写章节脚本
├── test_ai.php               # AI 测试
├── test_epub.php             # EPUB 测试
├── test_rag.php              # RAG 测试
├── test_rag2.php             # RAG 测试2
└── debug_rag.php             # 调试脚本
```

## 安装

```bash
# 安装依赖
composer install

# 或单独安装 Workerman
composer require workerman/workerman
```

## 配置

在 `~/.zprofile` 中设置 Gemini API Key：

```bash
export GEMINI_API_KEY="your-api-key"
```

## 使用方法

### 1. 启动服务

```bash
# 前台运行
php workerman_ai_server.php start

# 守护进程模式
php workerman_ai_server.php start -d

# 停止服务
php workerman_ai_server.php stop

# 重启服务
php workerman_ai_server.php restart
```

### 2. 打开 Web 界面

```bash
open chat.html
```

### 3. API 接口

| 端点 | 方法 | 说明 |
|------|------|------|
| `/api/health` | GET | 健康检查 |
| `/api/ask` | POST | 书籍问答 (RAG) |
| `/api/chat` | POST | 通用聊天 |
| `/api/continue` | POST | 续写章节 |

### 4. 示例请求

```bash
# 健康检查
curl http://localhost:8088/api/health

# 书籍问答
curl -X POST http://localhost:8088/api/ask \
  -H "Content-Type: application/json" \
  -d '{"question": "孙悟空大闹天宫的经过"}'

# 续写章节
curl -X POST http://localhost:8088/api/continue \
  -H "Content-Type: application/json" \
  -d '{"prompt": "唐僧师徒遇到科技妖怪"}'

# 通用聊天
curl -X POST http://localhost:8088/api/chat \
  -H "Content-Type: application/json" \
  -d '{"messages": [{"role": "user", "content": "你好"}]}'
```

### 5. 命令行测试

```bash
# 测试 RAG 检索
php test_rag2.php "孙悟空的武器是什么"

# 续写小说章节
php continue_story.php
```

## 服务地址

| 服务 | 地址 |
|------|------|
| HTTP API | http://localhost:8088 |
| WebSocket | ws://localhost:8081 |

## 技术栈

- **后端**: PHP 8.0+, Workerman
- **AI**: Google Gemini 2.5 Flash
- **检索**: RAG (关键词 + 向量混合检索)
- **前端**: Layui, Marked.js
- **向量**: Gemini text-embedding-004

## License

MIT
