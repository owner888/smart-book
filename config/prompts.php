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
        'description' => '我是通用聊天助手，可以和你讨论任何话题。',
    ],
    
    // ===================================
    // 书籍问答助手
    // ===================================
    'book' => [
        'description' => '我是书籍问答助手，可以帮你分析{title}的内容。你可以问我关于书中人物、情节、主题等问题。',
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
    // RAG 问答模式 (基于书籍内容检索的问答)
    // ===================================
    'rag' => [
        // 书籍信息模板 (参考 library 模式)
        'book_intro' => 'I am discussing the book: {title}',
        'author_template' => ' by {authors}',
        'series_template' => '. It is part of the series: {series}',
        'tags_template' => '. Tagged with: {tags}',
        
        // System prompt 模板
        'system' => 'You are a knowledgeable book analysis assistant. {book_info}

I have retrieved the following relevant passages from this book to help answer questions:

{context}

Instructions:
1. Answer questions based PRIMARILY on the retrieved passages above
2. If the passages contain relevant information, cite them in your answer
3. If the passages don\'t contain enough information, you may supplement with your general knowledge, but clearly indicate this
4. Use markdown formatting for better readability
5. Be accurate and avoid making up information not in the text
6. Respond in the user\'s language (Chinese if asked in Chinese)',

        // 检索片段模板
        'chunk_template' => "【Passage {index}】\n{text}\n",
        'chunk_separator' => "\n",
        
        // 无检索结果时的 fallback
        'no_context_system' => 'You are a knowledgeable book analysis assistant. {book_info}

Note: No relevant passages were found in the book for this query. Please:
1. Try to answer based on your general knowledge of the book (if known)
2. Clearly indicate that this is not based on the book\'s actual text
3. Suggest the user try rephrasing their question if needed',
        
        // 预定义操作 (参考 library 和 viewer 模式)
        'actions' => [
            'summarize' => [
                'name' => 'summarize',
                'human_name' => '总结内容',
                'prompt' => 'Based on the retrieved passages, provide a concise summary of this part of the book.',
            ],
            'explain' => [
                'name' => 'explain',
                'human_name' => '解释说明',
                'prompt' => 'Explain the content of the retrieved passages in simple, easy to understand language.',
            ],
            'characters' => [
                'name' => 'characters',
                'human_name' => '人物分析',
                'prompt' => 'Analyze the characters mentioned in the retrieved passages. Describe their traits, motivations, and roles.',
            ],
            'themes' => [
                'name' => 'themes',
                'human_name' => '主题分析',
                'prompt' => 'Identify and analyze the themes present in the retrieved passages.',
            ],
            'key_points' => [
                'name' => 'key_points',
                'human_name' => '关键要点',
                'prompt' => 'Extract the key points from the retrieved passages as a bulleted list.',
            ],
            'translate' => [
                'name' => 'translate',
                'human_name' => '翻译',
                'prompt' => 'Translate the retrieved passages into {language}.',
            ],
            'compare' => [
                'name' => 'compare',
                'human_name' => '对比分析',
                'prompt' => 'Compare and contrast the different elements or viewpoints in the retrieved passages.',
            ],
            'context' => [
                'name' => 'context',
                'human_name' => '背景知识',
                'prompt' => 'Provide historical, cultural, or literary context for the content in the retrieved passages.',
            ],
        ],
        
        // Markdown 格式指令
        'markdown_instruction' => ' When you answer the questions use markdown formatting for the answers wherever possible.',
    ],
    
    // ===================================
    // 续写模式 (增强版：使用 Context Cache + Few-shot)
    // ===================================
    'continue' => [
        // 系统提示词（动态生成，仅作为欢迎页展示）
        'system' => '我是增强版续写助手，使用 **Context Cache** 技术缓存整本书籍内容。

✨ **核心能力：**
1. 已完整阅读原书（数十万 tokens），深入理解世界观、人物、情节
2. 精准模仿原作文风：用词习惯、句式结构、叙事节奏
3. 情节自然衔接，角色性格一致
4. 不使用现代网络用语，保持原作气质

📖 **使用前请确保：**
- 已为书籍创建 Context Cache（首次使用会自动提示）

💡 **续写建议：**
- 输入具体的续写要求，如「续写第XX回，讲述...的故事」
- 可指定字数、情节走向、重点人物',
        'default_prompt' => '请为{title}续写一个新章节。约1000字。',
        // 描述模板
        'description' => '我是增强版续写助手（Context Cache），已完整阅读{title}全书内容。告诉我你想要的情节，我会以原作风格续写。',
        // RAG 参考资料说明（用于续写时引用原文风格）
        // 注意：续写功能建议关闭 RAG，因为检索到的原书情节会干扰原创
        'rag_instruction' => '

【原著风格参考 - 仅参考语言风格，禁止使用情节】
以下文字来自原著，仅用于帮助你理解写作的语言风格和修辞手法。
⚠️ 严禁参考、借用、改编或提及下列内容中的任何情节、人物对话或故事发展！

---风格示例开始---
{context}
---风格示例结束---

【创作要求】
1. ⚠️ 绝对禁止：不得使用上述示例中的任何情节、对话、场景或角色冲突
2. ⚠️ 绝对禁止：不得出现白骨精、金角大王、银角大王等原著中已有的妖怪
3. ✅ 必须原创：创造一个全新的妖怪角色，赋予其独特的名字、外貌、法术和背景故事
4. ✅ 必须原创：设计全新的故事场景和情节冲突
5. ✅ 风格一致：仅模仿原著的文言白话混合语言风格和章回体格式',
    ],
    
    // ===================================
    // 语言指令
    // ===================================
    'language' => [
        'instruction' => 'If you can speak in {language}, then respond in {language}.',
        'default' => 'Chinese',
    ],
    
    // ===================================
    // 简化版 RAG 系统提示词（用于非流式接口）
    // ===================================
    'rag_simple' => [
        'system' => '你是一个书籍分析助手。根据以下内容回答问题，使用中文：

{context}',
    ],
    
    // ===================================
    // 默认助手
    // ===================================
    'default' => [
        'system' => '你是一个通用 AI 助手，请友善地帮助用户。',
        'description' => '我是默认助手，有什么可以帮你的吗？',
    ],
    
    // ===================================
    // 对话摘要
    // ===================================
    'summarize' => [
        'prompt' => '请用中文简洁地总结以上对话的要点，包括：1) 用户讨论的主要话题 2) AI 给出的关键信息和结论 3) 任何重要的背景上下文。总结应该简短精炼（100-200字），便于后续对话参考。',
        'previous_summary_label' => '【之前的摘要】',
        'new_conversation_label' => '【新对话】',
        'history_label' => '【对话历史摘要】',
    ],
    
    // ===================================
    // 知识来源描述
    // ===================================
    'source_texts' => [
        'google' => 'AI 预训练知识 + Google Search',
        'mcp' => 'AI 预训练知识 + MCP 工具',
        'off' => 'AI 预训练知识（搜索已关闭）',
    ],
    
    // ===================================
    // 角色名称显示
    // ===================================
    'role_names' => [
        'user' => '用户',
        'assistant' => 'AI',
    ],
    
    // ===================================
    // 默认值
    // ===================================
    'defaults' => [
        'unknown_book' => '未知书籍',
        'unknown_author' => '未知作者',
    ],
    
    // ===================================
    // 片段标签模板
    // ===================================
    'chunk_label' => '【片段 {index}】',
    
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
