<?php
/**
 * 应用配置
 * 
 * 优先级：系统环境变量 > .env 文件 > 默认值
 */

return [
    // ===================================
    // 服务器配置
    // ===================================
    'server' => [
        'http_host' => '0.0.0.0',
        'http_port' => 8088,
        'websocket_host' => '0.0.0.0',
        'websocket_port' => 8081,
        'workers' => 1,
    ],
    
    // ===================================
    // AI API 配置
    // ===================================
    'ai' => [
        // Google Gemini
        'gemini' => [
            'api_key' => getenv('GEMINI_API_KEY') ?: '',
            'model' => getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'timeout' => 120,
        ],
        
        // OpenAI (预留)
        'openai' => [
            'api_key' => getenv('OPENAI_API_KEY') ?: '',
            'model' => getenv('OPENAI_MODEL') ?: 'gpt-4o-mini',
            'base_url' => 'https://api.openai.com/v1',
            'timeout' => 120,
        ],
        
        // 默认使用的 provider
        'default_provider' => getenv('AI_PROVIDER') ?: 'gemini',
    ],
    
    // ===================================
    // 书籍配置
    // ===================================
    'books' => [
        // 默认书籍目录
        'directory' => getenv('BOOKS_DIR') ?: __DIR__ . '/../books',
        
        // 默认书籍
        'default' => [
            'path' => getenv('BOOK_PATH') ?: __DIR__ . '/../books/西游记.epub',
            'cache' => getenv('BOOK_CACHE') ?: __DIR__ . '/../books/西游记_index.json',
        ],
    ],
    
    // ===================================
    // RAG 配置
    // ===================================
    'rag' => [
        'top_k' => 8,                    // 检索结果数量
        'similarity_threshold' => 0.6,   // 相似度阈值
        'chunk_size' => 500,             // 分块大小
        'chunk_overlap' => 50,           // 分块重叠
        'embedding_model' => 'text-embedding-004',
    ],
    
    // ===================================
    // 日志配置
    // ===================================
    'logging' => [
        'level' => getenv('LOG_LEVEL') ?: 'info',  // debug, info, warning, error
        'file' => getenv('LOG_FILE') ?: __DIR__ . '/../logs/app.log',
    ],
    
    // ===================================
    // 调试配置
    // ===================================
    'debug' => [
        'enabled' => (bool)(getenv('DEBUG') ?: false),
        'show_prompts' => false,   // 是否在日志中显示完整提示词
        'show_embeddings' => false, // 是否显示向量信息
    ],
];
