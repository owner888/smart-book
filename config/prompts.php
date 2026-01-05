<?php
/**
 * AI 提示词配置
 * 
 * 从 calibre Python 源码提取 (src/calibre/gui2/dialogs/llm_book.py)
 */

return [
    // 通用聊天助手
    'chat' => [
        'system' => '你是一个友善、博学的 AI 助手。你具备以下特点：
1. 知识广博，能回答各种领域的问题
2. 善于解释复杂概念，使用通俗易懂的语言
3. 尊重用户，提供客观、准确的信息
4. 在不确定时会诚实地表示，不会编造信息
5. 支持多轮对话，能够保持上下文连贯性

请用中文回答用户的问题。',
    ],
    
    // ===================================
    // 书库讨论模式 (Library Book Discussion)
    // ===================================
    'library' => [
        // 书籍格式模板
        'book_intro' => 'I wish to discuss the following book. ',
        'books_intro' => 'I wish to discuss the following books. ',
        'book_template' => 'The {which}book is: {title} by {authors}.',
        'series_template' => ' It is in the series: {series}.',
        'tags_template' => ' It is tagged with the following tags: {tags}.',
        'separator' => "\n---------------\n\n",
        
        // System prompt 后缀
        'markdown_instruction' => ' When you answer the questions use markdown formatting for the answers wherever possible.',
        'unknown_single' => ' If the specified book is unknown to you instead of answering the following questions just say the book is unknown.',
        'unknown_multiple' => ' If any of the specified books are unknown to you, instead of answering the following questions, just say the books are unknown.',
        
        // 预定义操作
        'actions' => [
            'summarize' => [
                'name' => 'summarize',
                'human_name' => 'Summarize',
                'prompt' => 'Provide a concise summary of the previously described {books_word}.',
            ],
            'chapters' => [
                'name' => 'chapters',
                'human_name' => 'Chapters',
                'prompt' => 'Provide a chapter by chapter summary of the previously described {books_word}.',
            ],
            'read_next' => [
                'name' => 'read_next',
                'human_name' => 'Read next',
                'prompt' => 'Suggest some good books to read after the previously described {books_word}.',
            ],
            'universe' => [
                'name' => 'universe',
                'human_name' => 'Universe',
                'prompt' => 'Describe the fictional universe the previously described {books_word} {is_are} set in. Outline major plots, themes and characters in the universe.',
            ],
            'series' => [
                'name' => 'series',
                'human_name' => 'Series',
                'prompt' => 'Give the series the previously described {books_word} {is_are} in. List all the books in the series, in both published and internal chronological order. Also describe any prominent spin-off series.',
            ],
        ],
    ],
    
    // ===================================
    // 阅读器模式 (E-book Viewer)
    // ===================================
    'viewer' => [
        // System prompt 模板
        'reading_template' => 'I am currently reading the book: {title}',
        'author_template' => ' by {authors}',
        'has_selection' => '. I have some questions about content from this book.',
        'no_selection' => '. I have some questions about this book.',
        
        // 预定义操作
        'actions' => [
            'explain' => [
                'name' => 'explain',
                'human_name' => 'Explain',
                'prompt' => 'Explain the following text in simple, easy to understand language. {selected}',
                'word_prompt' => 'Explain the meaning, etymology and common usages of the following word in simple, easy to understand language. {selected}',
            ],
            'define' => [
                'name' => 'define',
                'human_name' => 'Define',
                'prompt' => 'Identify and define any technical or complex terms in the following text. {selected}',
                'word_prompt' => 'Explain the meaning and common usages of the following word. {selected}',
            ],
            'summarize' => [
                'name' => 'summarize',
                'human_name' => 'Summarize',
                'prompt' => 'Provide a concise summary of the following text. {selected}',
            ],
            'points' => [
                'name' => 'points',
                'human_name' => 'Key points',
                'prompt' => 'Extract the key points from the following text as a bulleted list. {selected}',
            ],
            'grammar' => [
                'name' => 'grammar',
                'human_name' => 'Fix grammar',
                'prompt' => 'Correct any grammatical errors in the following text and provide the corrected version. {selected}',
            ],
            'translate' => [
                'name' => 'translate',
                'human_name' => 'Translate',
                'prompt' => 'Translate the following text into the language {language}. {selected}',
                'word_prompt' => 'Translate the following word into the language {language}. {selected}',
            ],
        ],
    ],
    
    // ===================================
    // RAG 问答模式
    // ===================================
    'rag' => [
        'system' => '你是一个书籍分析助手。根据以下从书中检索到的内容回答问题，使用中文：

{context}',
        'chunk_template' => "【片段 {index}】\n{text}\n\n",
    ],
    
    // ===================================
    // 续写模式
    // ===================================
    'continue' => [
        'system' => '你是一位精通古典文学的作家，擅长模仿《西游记》的章回体小说风格写作。

请严格模仿《西游记》的写作风格特点：
1. 章回体格式：标题用对仗的两句话
2. 开头常用诗词引入
3. 结尾常用"毕竟不知XXX，且听下回分解"
4. 文言白话混合的语言风格
5. 人物对话生动传神',
        'default_prompt' => '请为《西游记》续写一个新章节。设定：唐僧师徒四人遇到一个新的妖怪。写一个完整的章回，约1000字。',
    ],
    
    // ===================================
    // 语言指令
    // ===================================
    'language' => [
        'instruction' => 'If you can speak in {language}, then respond in {language}.',
        'default' => 'Chinese',
    ],
    
    // ===================================
    // AI 不认识书籍的检测关键词
    // 与 Python 源码 (src/calibre/gui2/dialogs/llm_book.py) 保持一致
    // Python: "just say the book is unknown" / "just say the books are unknown"
    // ===================================
    'unknown_patterns' => [
        'the book is unknown',
        'the books are unknown',
    ],
];
