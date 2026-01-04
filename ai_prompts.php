<?php
/**
 * Calibre AI Prompts - PHP Version
 * 
 * 从 calibre 源码提取的所有 AI 提示词
 * 源文件: src/calibre/gui2/dialogs/llm_book.py 和 src/calibre/gui2/viewer/llm.py
 */

// ===================================
// OpenAI API 客户端
// ===================================

/**
 * 安全关闭 curl handle（兼容 PHP 7.x 和 8.0+）
 * @param resource|\CurlHandle $ch curl handle
 */
function safe_curl_close(mixed $ch): void
{
    if (PHP_VERSION_ID < 80000) {
        // PHP 7.x: curl_init 返回 resource
        if (is_resource($ch)) {
            curl_close($ch);
        }
    }
    // PHP 8.0+: curl_init 返回 CurlHandle 对象，会自动销毁
}

class OpenAIClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private int $timeout;
    
    // 支持的模型
    const MODEL_GPT4O = 'gpt-4o';
    const MODEL_GPT4O_MINI = 'gpt-4o-mini';
    const MODEL_GPT4_TURBO = 'gpt-4-turbo';
    const MODEL_GPT35_TURBO = 'gpt-3.5-turbo';
    
    public function __construct(
        string $apiKey,
        string $model = self::MODEL_GPT4O_MINI,
        string $baseUrl = 'https://api.openai.com/v1',
        int $timeout = 120
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }
    
    /**
     * 发送聊天请求（非流式）
     */
    public function chat(array $messages, array $options = []): array
    {
        $data = array_merge([
            'model' => $options['model'] ?? $this->model,
            'messages' => $this->formatMessages($messages),
            'stream' => false,
        ], $options);
        
        // 移除非 API 参数
        unset($data['model_override']);
        
        return $this->request('POST', '/chat/completions', $data);
    }
    
    /**
     * 发送聊天请求（流式）
     * 
     * @param array $messages 消息数组
     * @param callable $onChunk 每个块的回调函数 function(string $content, array $chunk)
     * @param array $options 选项
     */
    public function chatStream(array $messages, callable $onChunk, array $options = []): array
    {
        $data = array_merge([
            'model' => $options['model'] ?? $this->model,
            'messages' => $this->formatMessages($messages),
            'stream' => true,
        ], $options);
        
        unset($data['model_override']);
        
        return $this->requestStream('POST', '/chat/completions', $data, $onChunk);
    }
    
    /**
     * 格式化消息为 OpenAI 格式
     */
    private function formatMessages(array $messages): array
    {
        $formatted = [];
        foreach ($messages as $msg) {
            if (is_string($msg)) {
                $formatted[] = ['role' => 'user', 'content' => $msg];
            } elseif (isset($msg['role']) && isset($msg['content'])) {
                $formatted[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content'],
                ];
            } elseif (isset($msg['type']) && isset($msg['content'])) {
                // 兼容 calibre 格式
                $role = match($msg['type']) {
                    'system' => 'system',
                    'assistant' => 'assistant',
                    'user' => 'user',
                    default => 'user',
                };
                $formatted[] = ['role' => $role, 'content' => $msg['content']];
            }
        }
        return $formatted;
    }
    
    /**
     * 发送 HTTP 请求
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ],
            ]);
            
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            if ($error) {
                return ['error' => "cURL Error: {$error}"];
            }
            
            $result = json_decode($response, true);
            
            if ($httpCode >= 400) {
                $errorMsg = $result['error']['message'] ?? 'Unknown error';
                return ['error' => "OpenAI API Error ({$httpCode}): {$errorMsg}"];
            }
            
            return $result;
        } finally {
            safe_curl_close($ch);
        }
    }
    
    /**
     * 发送流式 HTTP 请求
     */
    private function requestStream(string $method, string $endpoint, array $data, callable $onChunk): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $fullContent = '';
        $metadata = null;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: text/event-stream',
            ],
            CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$fullContent, &$metadata, $onChunk) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || !str_starts_with($line, 'data: ')) {
                        continue;
                    }
                    
                    $jsonStr = substr($line, 6);
                    if ($jsonStr === '[DONE]') {
                        continue;
                    }
                    
                    $chunk = json_decode($jsonStr, true);
                    if (!$chunk) {
                        continue;
                    }
                    
                    $content = $chunk['choices'][0]['delta']['content'] ?? '';
                    if ($content) {
                        $fullContent .= $content;
                        $onChunk($content, $chunk);
                    }
                    
                    // 保存最后的元数据
                    if (isset($chunk['usage'])) {
                        $metadata = $chunk;
                    }
                }
                return strlen($data);
            },
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        try {
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            if ($error) {
                return ['error' => "cURL Error: {$error}", 'content' => $fullContent];
            }
            
            if ($httpCode >= 400) {
                return ['error' => "OpenAI API Error: HTTP {$httpCode}", 'content' => $fullContent];
            }
            
            return [
                'content' => $fullContent,
                'metadata' => $metadata,
            ];
        } finally {
            safe_curl_close($ch);
        }
    }
    
    /**
     * 获取可用模型列表
     */
    public function listModels(): array
    {
        return $this->request('GET', '/models');
    }
}

// ===================================
// Google Gemini API 客户端
// ===================================

class GeminiClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private int $timeout;
    private bool $async;
    
    // 支持的模型
    const MODEL_GEMINI_25_PRO = 'gemini-2.5-pro';
    const MODEL_GEMINI_25_FLASH = 'gemini-2.5-flash';
    const MODEL_GEMINI_25_FLASH_LITE = 'gemini-2.5-flash-lite';
    
    public function __construct(
        string $apiKey,
        string $model = self::MODEL_GEMINI_25_FLASH,
        string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        int $timeout = 120,
        bool $async = false
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->async = $async;
    }
    
    /**
     * 发送聊天请求（非流式）
     */
    public function chat(array $messages, array $options = []): array
    {
        $model = $options['model'] ?? $this->model;
        $data = $this->buildRequestData($messages, $options);
        
        return $this->request('POST', "/models/{$model}:generateContent", $data);
    }
    
    /**
     * 发送聊天请求（流式）
     */
    public function chatStream(array $messages, callable $onChunk, array $options = []): array
    {
        $model = $options['model'] ?? $this->model;
        $data = $this->buildRequestData($messages, $options);
        
        return $this->requestStream('POST', "/models/{$model}:streamGenerateContent?alt=sse", $data, $onChunk);
    }
    
    /**
     * 构建请求数据
     */
    private function buildRequestData(array $messages, array $options): array
    {
        $contents = [];
        $systemInstruction = null;
        
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? $msg['type'] ?? 'user';
            $content = $msg['content'] ?? $msg['query'] ?? '';
            
            if ($role === 'system') {
                $systemInstruction = ['parts' => [['text' => $content]]];
            } else {
                $geminiRole = $role === 'assistant' ? 'model' : 'user';
                $contents[] = [
                    'role' => $geminiRole,
                    'parts' => [['text' => $content]],
                ];
            }
        }
        
        $data = [
            'contents' => $contents,
            'generationConfig' => [
                'thinkingConfig' => [
                    'includeThoughts' => $options['includeThoughts'] ?? true,
                ],
            ],
        ];
        
        if ($systemInstruction) {
            $data['system_instruction'] = $systemInstruction;
        }
        
        // 启用 Google 搜索
        if ($options['enableSearch'] ?? true) {
            $data['tools'] = [['google_search' => new \stdClass()]];
        }
        
        return $data;
    }
    
    /**
     * 发送 HTTP 请求
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-goog-api-key: ' . $this->apiKey,
                ],
            ]);
            
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            if ($error) {
                return ['error' => "cURL Error: {$error}"];
            }
            
            $result = json_decode($response, true);
            
            if ($httpCode >= 400) {
                $errorMsg = $result['error']['message'] ?? 'Unknown error';
                return ['error' => "Gemini API Error ({$httpCode}): {$errorMsg}"];
            }
            
            return $result;
        } finally {
            safe_curl_close($ch);
        }
    }
    
    /**
     * 发送流式 HTTP 请求
     */
    private function requestStream(string $method, string $endpoint, array $data, callable $onChunk): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $fullContent = '';
        $fullReasoning = '';
        $metadata = null;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-goog-api-key: ' . $this->apiKey,
            ],
            CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$fullContent, &$fullReasoning, &$metadata, $onChunk) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || !str_starts_with($line, 'data: ')) {
                        continue;
                    }
                    
                    $jsonStr = substr($line, 6);
                    $chunk = json_decode($jsonStr, true);
                    if (!$chunk || !isset($chunk['candidates'])) {
                        continue;
                    }
                    
                    foreach ($chunk['candidates'] as $candidate) {
                        $parts = $candidate['content']['parts'] ?? [];
                        foreach ($parts as $part) {
                            $text = $part['text'] ?? '';
                            $isThought = $part['thought'] ?? false;
                            
                            if ($text) {
                                if ($isThought) {
                                    $fullReasoning .= $text;
                                } else {
                                    $fullContent .= $text;
                                }
                                $onChunk($text, $chunk, $isThought);
                            }
                        }
                    }
                    
                    // 保存使用元数据
                    if (isset($chunk['usageMetadata'])) {
                        $metadata = $chunk;
                    }
                }
                return strlen($data);
            },
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        try {
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            if ($error) {
                return ['error' => "cURL Error: {$error}", 'content' => $fullContent];
            }
            
            if ($httpCode >= 400) {
                $errorMsg = $httpCode === 429 
                    ? "Gemini API 限流（HTTP 429），请稍后重试" 
                    : "Gemini API Error: HTTP {$httpCode}";
                return ['error' => $errorMsg, 'content' => $fullContent];
            }
            
            return [
                'content' => $fullContent,
                'reasoning' => $fullReasoning,
                'metadata' => $metadata,
            ];
        } finally {
            safe_curl_close($ch);
        }
    }
}

// ===================================
// Calibre AI 服务封装
// ===================================

class CalibreAIService
{
    private OpenAIClient|GeminiClient $client;
    private string $provider;
    
    public function __construct(string $provider, string $apiKey, string $model = '')
    {
        $this->provider = $provider;
        
        switch ($provider) {
            case 'openai':
                $this->client = new OpenAIClient($apiKey, $model ?: OpenAIClient::MODEL_GPT4O_MINI);
                break;
            case 'gemini':
                $this->client = new GeminiClient($apiKey, $model ?: GeminiClient::MODEL_GEMINI_25_FLASH);
                break;
            default:
                // 不支持的 provider，使用 Gemini 作为默认
                echo "⚠️ Unsupported provider: {$provider}, using Gemini\n";
                $this->client = new GeminiClient($apiKey, GeminiClient::MODEL_GEMINI_25_FLASH);
                $this->provider = 'gemini';
        }
    }
    
    /**
     * 讨论书籍（书库模式）
     */
    public function discussBooks(array $books, string $actionOrQuestion, string $language = ''): string
    {
        $systemPrompt = CalibreAIPrompts::getLibrarySystemPrompt($books, $language);
        
        // 检查是否是预定义操作
        $actions = CalibreAIPrompts::getLibraryDefaultActions();
        if (isset($actions[$actionOrQuestion])) {
            $userPrompt = CalibreAIPrompts::getLibraryActionPrompt($actionOrQuestion, count($books));
        } else {
            $userPrompt = $actionOrQuestion;
        }
        
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];
        
        return $this->chat($messages);
    }
    
    /**
     * 分析选中文本（阅读器模式）
     */
    public function analyzeText(
        string $selectedText,
        string $actionOrQuestion,
        string $bookTitle = '',
        string $bookAuthors = '',
        string $language = ''
    ): string {
        $messages = [];
        
        if ($bookTitle) {
            $systemPrompt = CalibreAIPrompts::getViewerSystemPrompt(
                $bookTitle,
                $bookAuthors,
                !empty($selectedText),
                $language
            );
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        
        // 检查是否是预定义操作
        $actions = CalibreAIPrompts::getViewerDefaultActions();
        if (isset($actions[$actionOrQuestion])) {
            $userPrompt = CalibreAIPrompts::getViewerActionPrompt($actionOrQuestion, $selectedText, $language ?: 'English');
        } else {
            // 自定义问题，附加选中文本
            $userPrompt = $actionOrQuestion;
            if ($selectedText) {
                $userPrompt .= "\n\n------\n\nText: " . $selectedText;
            }
        }
        
        $messages[] = ['role' => 'user', 'content' => $userPrompt];
        
        return $this->chat($messages);
    }
    
    /**
     * 流式讨论书籍
     */
    public function discussBooksStream(array $books, string $actionOrQuestion, callable $onChunk, string $language = ''): string
    {
        $systemPrompt = CalibreAIPrompts::getLibrarySystemPrompt($books, $language);
        
        $actions = CalibreAIPrompts::getLibraryDefaultActions();
        if (isset($actions[$actionOrQuestion])) {
            $userPrompt = CalibreAIPrompts::getLibraryActionPrompt($actionOrQuestion, count($books));
        } else {
            $userPrompt = $actionOrQuestion;
        }
        
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];
        
        return $this->chatStream($messages, $onChunk);
    }
    
    /**
     * 通用聊天
     */
    public function chat(array $messages): string
    {
        $response = $this->client->chat($messages);
        
        if ($this->provider === 'openai') {
            return $response['choices'][0]['message']['content'] ?? '';
        } else {
            // Gemini
            $content = '';
            foreach ($response['candidates'] ?? [] as $candidate) {
                foreach ($candidate['content']['parts'] ?? [] as $part) {
                    if (!($part['thought'] ?? false)) {
                        $content .= $part['text'] ?? '';
                    }
                }
            }
            return $content;
        }
    }
    
    /**
     * 流式聊天
     */
    public function chatStream(array $messages, callable $onChunk): string
    {
        $result = $this->client->chatStream($messages, $onChunk);
        return $result['content'];
    }
}


class CalibreAIPrompts
{
    // ===================================
    // 书库讨论模式 (Library Book Discussion)
    // ===================================
    
    /**
     * 格式化单本书的信息
     */
    public static function formatBookForQuery(array $book, bool $isFirst = true, int $numBooks = 1): string
    {
        $which = $numBooks < 2 ? '' : ($isFirst ? 'first ' : 'next ');
        $ans = "The {$which}book is: {$book['title']} by {$book['authors']}.";
        
        if (!empty($book['series'])) {
            $ans .= " It is in the series: {$book['series']}.";
        }
        
        if (!empty($book['tags'])) {
            $tags = is_array($book['tags']) ? implode(', ', $book['tags']) : $book['tags'];
            $ans .= " It is tagged with the following tags: {$tags}.";
        }
        
        // 其他元数据字段
        $metadataFields = ['publisher', 'pubdate', 'rating', 'language'];
        $additionalMeta = [];
        foreach ($metadataFields as $field) {
            if (!empty($book[$field])) {
                $additionalMeta[] = ucfirst($field) . ": " . $book[$field];
            }
        }
        
        if (!empty($additionalMeta)) {
            $ans .= " It has the following additional metadata.\n" . implode("\n", $additionalMeta);
        }
        
        if (!empty($book['comments'])) {
            $ans .= "\nSome notes about this book:\n" . $book['comments'];
        }
        
        return $ans;
    }
    
    /**
     * 格式化多本书籍查询
     */
    public static function formatBooksForQuery(array $books): string
    {
        $count = count($books);
        $ans = $count > 1 
            ? "I wish to discuss the following books. "
            : "I wish to discuss the following book. ";
        
        foreach ($books as $i => $book) {
            $ans .= self::formatBookForQuery($book, $i === 0, $count);
            $ans .= "\n---------------\n\n";
        }
        
        return $ans;
    }
    
    /**
     * 生成书库讨论的系统提示词
     */
    public static function getLibrarySystemPrompt(array $books, string $language = ''): string
    {
        $contextHeader = self::formatBooksForQuery($books);
        $contextHeader .= ' When you answer the questions use markdown formatting for the answers wherever possible.';
        
        if (count($books) > 1) {
            $contextHeader .= ' If any of the specified books are unknown to you, instead of answering the following'
                . ' questions, just say the books are unknown.';
        } else {
            $contextHeader .= ' If the specified book is unknown to you instead of answering the following questions'
                . ' just say the book is unknown.';
        }
        
        if (!empty($language)) {
            $contextHeader .= " If you can speak in {$language}, then respond in {$language}.";
        }
        
        return $contextHeader;
    }
    
    /**
     * 书库模式 - 默认快捷操作
     */
    public static function getLibraryDefaultActions(): array
    {
        return [
            'summarize' => [
                'name' => 'summarize',
                'human_name' => 'Summarize',
                'prompt_template' => 'Provide a concise summary of the previously described {books_word}.',
            ],
            'chapters' => [
                'name' => 'chapters',
                'human_name' => 'Chapters',
                'prompt_template' => 'Provide a chapter by chapter summary of the previously described {books_word}.',
            ],
            'read_next' => [
                'name' => 'read_next',
                'human_name' => 'Read next',
                'prompt_template' => 'Suggest some good books to read after the previously described {books_word}.',
            ],
            'universe' => [
                'name' => 'universe',
                'human_name' => 'Universe',
                'prompt_template' => 'Describe the fictional universe the previously described {books_word} {is_are} set in.'
                    . ' Outline major plots, themes and characters in the universe.',
            ],
            'series' => [
                'name' => 'series',
                'human_name' => 'Series',
                'prompt_template' => 'Give the series the previously described {books_word} {is_are} in.'
                    . ' List all the books in the series, in both published and internal chronological order.'
                    . ' Also describe any prominent spin-off series.',
            ],
        ];
    }
    
    /**
     * 生成书库操作的提示文本
     */
    public static function getLibraryActionPrompt(string $actionName, int $bookCount): string
    {
        $actions = self::getLibraryDefaultActions();
        
        if (!isset($actions[$actionName])) {
            return '';
        }
        
        $template = $actions[$actionName]['prompt_template'];
        $booksWord = $bookCount < 2 ? 'book' : 'books';
        $isAre = $bookCount < 2 ? 'is' : 'are';
        
        return str_replace(
            ['{books_word}', '{is_are}'],
            [$booksWord, $isAre],
            $template
        );
    }
    
    // ===================================
    // 阅读器模式 (E-book Viewer)
    // ===================================
    
    /**
     * 生成阅读器的系统提示词
     */
    public static function getViewerSystemPrompt(string $bookTitle, string $bookAuthors = '', bool $hasSelectedText = false, string $language = ''): string
    {
        if (empty($bookTitle)) {
            return '';
        }
        
        $contextHeader = "I am currently reading the book: {$bookTitle}";
        
        if (!empty($bookAuthors)) {
            $contextHeader .= " by {$bookAuthors}";
        }
        
        if ($hasSelectedText) {
            $contextHeader .= '. I have some questions about content from this book.';
        } else {
            $contextHeader .= '. I have some questions about this book.';
        }
        
        $contextHeader .= ' When you answer the questions use markdown formatting for the answers wherever possible.';
        
        if (!empty($language)) {
            $contextHeader .= " If you can speak in {$language}, then respond in {$language}.";
        }
        
        return $contextHeader;
    }
    
    /**
     * 阅读器模式 - 默认快捷操作
     */
    public static function getViewerDefaultActions(): array
    {
        return [
            'explain' => [
                'name' => 'explain',
                'human_name' => 'Explain',
                'prompt_template' => 'Explain the following text in simple, easy to understand language. {selected}',
                'word_prompt' => 'Explain the meaning, etymology and common usages of the following word in simple, easy to understand language. {selected}',
            ],
            'define' => [
                'name' => 'define',
                'human_name' => 'Define',
                'prompt_template' => 'Identify and define any technical or complex terms in the following text. {selected}',
                'word_prompt' => 'Explain the meaning and common usages of the following word. {selected}',
            ],
            'summarize' => [
                'name' => 'summarize',
                'human_name' => 'Summarize',
                'prompt_template' => 'Provide a concise summary of the following text. {selected}',
            ],
            'points' => [
                'name' => 'points',
                'human_name' => 'Key points',
                'prompt_template' => 'Extract the key points from the following text as a bulleted list. {selected}',
            ],
            'grammar' => [
                'name' => 'grammar',
                'human_name' => 'Fix grammar',
                'prompt_template' => 'Correct any grammatical errors in the following text and provide the corrected version. {selected}',
            ],
            'translate' => [
                'name' => 'translate',
                'human_name' => 'Translate',
                'prompt_template' => 'Translate the following text into the language {language}. {selected}',
                'word_prompt' => 'Translate the following word into the language {language}. {selected}',
            ],
        ];
    }
    
    /**
     * 判断是否为单词模式
     */
    public static function isSingleWord(string $text): bool
    {
        return strlen($text) <= 20 && strpos($text, ' ') === false;
    }
    
    /**
     * 生成阅读器操作的提示文本
     */
    public static function getViewerActionPrompt(string $actionName, string $selectedText, string $language = 'English'): string
    {
        $actions = self::getViewerDefaultActions();
        
        if (!isset($actions[$actionName])) {
            return '';
        }
        
        $action = $actions[$actionName];
        $isSingleWord = self::isSingleWord($selectedText);
        
        // 单词模式使用专用提示（如果存在）
        $template = ($isSingleWord && isset($action['word_prompt'])) 
            ? $action['word_prompt'] 
            : $action['prompt_template'];
        
        // 格式化选中文本
        $what = $isSingleWord ? 'Word to analyze: ' : 'Text to analyze: ';
        $formattedSelected = !empty($selectedText) 
            ? "\n\n------\n\n" . $what . $selectedText 
            : '';
        
        return str_replace(
            ['{selected}', '{language}'],
            [$formattedSelected, $language],
            $template
        );
    }
    
    // ===================================
    // 通用工具方法
    // ===================================
    
    /**
     * 格式化对话记录为笔记
     */
    public static function formatConversationAsNote(array $messages, string $assistantName = 'Assistant', string $title = 'AI Assistant Note'): string
    {
        if (empty($messages)) {
            return '';
        }
        
        // 找到最后一个助手回复
        $mainResponse = '';
        foreach (array_reverse($messages) as $message) {
            if ($message['type'] === 'assistant') {
                $mainResponse = trim($message['content']);
                break;
            }
        }
        
        if (empty($mainResponse)) {
            return '';
        }
        
        $timestamp = date('Y-m-d H:i');
        $sep = '―――';
        $header = "{$sep} {$title} ({$timestamp}) {$sep}";
        
        if (count($messages) === 1) {
            return "{$header}\n\n{$mainResponse}";
        }
        
        // 构建对话记录
        $recordLines = [];
        foreach ($messages as $message) {
            $role = '';
            switch ($message['type']) {
                case 'user':
                    $role = 'You';
                    break;
                case 'assistant':
                    $role = $assistantName;
                    break;
                default:
                    continue 2;
            }
            
            $content = trim($message['content']);
            $recordLines[] = "{$role}: {$content}";
        }
        
        $recordBody = implode("\n\n", $recordLines);
        $recordHeader = "{$sep} Conversation record {$sep}";
        
        return "{$header}\n\n{$mainResponse}\n\n{$recordHeader}\n\n{$recordBody}";
    }
    
    /**
     * 获取语言指令
     */
    public static function getLanguageInstruction(string $language): string
    {
        if (empty($language)) {
            return '';
        }
        return "If you can speak in {$language}, then respond in {$language}.";
    }
}

// ===================================
// curl_multi 异步管理器
// ===================================

class AsyncCurlManager
{
    private static $multiHandle = null;
    private static array $handles = [];
    private static ?int $timerId = null;
    
    /**
     * 初始化（在 Worker 启动时调用）
     */
    public static function init(): void
    {
        if (self::$multiHandle !== null) {
            return;
        }
        
        self::$multiHandle = curl_multi_init();
        
        // 使用 Workerman 定时器轮询（每 10ms）
        self::$timerId = \Workerman\Timer::add(0.01, function() {
            self::poll();
        });
        
        echo "✅ AsyncCurlManager 已初始化\n";
    }
    
    /**
     * 发起异步请求
     * 
     * @param string $url 请求 URL
     * @param array $options curl 选项
     * @param callable $onData 数据回调 function(string $data)
     * @param callable $onComplete 完成回调 function(bool $success, string $error)
     * @return string 请求ID
     */
    public static function request(
        string $url,
        array $options,
        callable $onData,
        callable $onComplete
    ): string {
        $ch = curl_init();
        $requestId = uniqid('curl_', true);
        
        // 默认选项
        $defaultOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use ($onData) {
                $onData($data);
                return strlen($data);
            },
        ];
        
        // 合并选项
        curl_setopt_array($ch, $defaultOptions + $options);
        
        // 添加到 multi handle
        curl_multi_add_handle(self::$multiHandle, $ch);
        
        self::$handles[$requestId] = [
            'ch' => $ch,
            'onComplete' => $onComplete,
        ];
        
        // 立即触发一次 exec
        curl_multi_exec(self::$multiHandle, $running);
        
        return $requestId;
    }
    
    /**
     * 轮询检查请求状态
     */
    private static function poll(): void
    {
        if (self::$multiHandle === null || empty(self::$handles)) {
            return;
        }
        
        // 非阻塞执行
        curl_multi_exec(self::$multiHandle, $running);
        
        // 使用 select 等待活动（超时 0 表示非阻塞）
        if ($running > 0) {
            curl_multi_select(self::$multiHandle, 0);
        }
        
        // 检查已完成的请求
        while ($info = curl_multi_info_read(self::$multiHandle)) {
            $ch = $info['handle'];
            
            // 查找对应的请求
            foreach (self::$handles as $requestId => $handle) {
                if ($handle['ch'] === $ch) {
                    $success = ($info['result'] === CURLE_OK);
                    $error = $success ? '' : curl_error($ch);
                    
                    // 调用完成回调
                    $handle['onComplete']($success, $error);
                    
                    // 清理
                    curl_multi_remove_handle(self::$multiHandle, $ch);
                    safe_curl_close($ch);
                    unset(self::$handles[$requestId]);
                    break;
                }
            }
        }
    }
    
    /**
     * 取消请求
     */
    public static function cancel(string $requestId): void
    {
        if (isset(self::$handles[$requestId])) {
            $ch = self::$handles[$requestId]['ch'];
            curl_multi_remove_handle(self::$multiHandle, $ch);
            safe_curl_close($ch);
            unset(self::$handles[$requestId]);
        }
    }
    
    /**
     * 获取活跃请求数
     */
    public static function getActiveCount(): int
    {
        return count(self::$handles);
    }
    
    /**
     * 关闭管理器
     */
    public static function close(): void
    {
        if (self::$timerId !== null) {
            \Workerman\Timer::del(self::$timerId);
            self::$timerId = null;
        }
        
        foreach (self::$handles as $handle) {
            curl_multi_remove_handle(self::$multiHandle, $handle['ch']);
            safe_curl_close($handle['ch']);
        }
        self::$handles = [];
        
        if (self::$multiHandle !== null) {
            curl_multi_close(self::$multiHandle);
            self::$multiHandle = null;
        }
    }
}

// ===================================
// 异步 Gemini 客户端（使用 curl_multi）
// ===================================

class AsyncGeminiClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    
    const MODEL_GEMINI_25_PRO = 'gemini-2.5-pro';
    const MODEL_GEMINI_25_FLASH = 'gemini-2.5-flash';
    const MODEL_GEMINI_25_FLASH_LITE = 'gemini-2.5-flash-lite';
    
    public function __construct(
        string $apiKey,
        string $model = self::MODEL_GEMINI_25_FLASH,
        string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta'
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    /**
     * 异步流式聊天（使用 curl_multi）
     * 
     * @param array $messages 消息数组
     * @param callable $onChunk 每个数据块的回调 function(string $text, bool $isThought)
     * @param callable $onComplete 完成回调 function(string $fullContent)
     * @param callable|null $onError 错误回调 function(string $error)
     * @param array $options 选项
     * @return string 请求ID（可用于取消）
     */
    public function chatStreamAsync(
        array $messages,
        callable $onChunk,
        callable $onComplete,
        ?callable $onError = null,
        array $options = []
    ): string {
        $model = $options['model'] ?? $this->model;
        $data = $this->buildRequestData($messages, $options);
        
        $url = "{$this->baseUrl}/models/{$model}:streamGenerateContent?alt=sse&key={$this->apiKey}";
        
        $fullContent = '';
        $buffer = '';
        
        // 数据回调：处理 SSE 流式数据
        $onData = function($rawData) use (&$fullContent, &$buffer, $onChunk) {
            $buffer .= $rawData;
            
            // 按行分割处理
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                
                $line = trim($line);
                if (empty($line) || !str_starts_with($line, 'data: ')) {
                    continue;
                }
                
                $jsonStr = substr($line, 6);
                $chunk = json_decode($jsonStr, true);
                if (!$chunk || !isset($chunk['candidates'])) {
                    continue;
                }
                
                foreach ($chunk['candidates'] as $candidate) {
                    $parts = $candidate['content']['parts'] ?? [];
                    foreach ($parts as $part) {
                        $text = $part['text'] ?? '';
                        $isThought = $part['thought'] ?? false;
                        
                        if ($text) {
                            if (!$isThought) {
                                $fullContent .= $text;
                            }
                            $onChunk($text, $isThought);
                        }
                    }
                }
            }
        };
        
        // 完成回调
        $onFinish = function($success, $error) use (&$fullContent, $onComplete, $onError) {
            if ($success) {
                $onComplete($fullContent);
            } else {
                if ($onError) {
                    $onError($error);
                }
            }
        };
        
        // 使用 AsyncCurlManager 发起请求
        return AsyncCurlManager::request(
            $url,
            [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                ],
            ],
            $onData,
            $onFinish
        );
    }
    
    /**
     * 取消请求
     */
    public function cancel(string $requestId): void
    {
        AsyncCurlManager::cancel($requestId);
    }
    
    /**
     * 构建请求数据
     */
    private function buildRequestData(array $messages, array $options): array
    {
        $contents = [];
        $systemInstruction = null;
        
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? $msg['type'] ?? 'user';
            $content = $msg['content'] ?? $msg['query'] ?? '';
            
            if ($role === 'system') {
                $systemInstruction = ['parts' => [['text' => $content]]];
            } else {
                $geminiRole = $role === 'assistant' ? 'model' : 'user';
                $contents[] = [
                    'role' => $geminiRole,
                    'parts' => [['text' => $content]],
                ];
            }
        }
        
        $data = [
            'contents' => $contents,
            'generationConfig' => [
                'thinkingConfig' => [
                    'includeThoughts' => $options['includeThoughts'] ?? true,
                ],
            ],
        ];
        
        if ($systemInstruction) {
            $data['system_instruction'] = $systemInstruction;
        }
        
        if ($options['enableSearch'] ?? false) {
            $data['tools'] = [['google_search' => new \stdClass()]];
        }
        
        return $data;
    }
}

// ===================================
// 使用示例
// ===================================

/*
// ============================================================
// 示例 1: 直接使用 OpenAI API 客户端
// ============================================================

$openai = new OpenAIClient(
    apiKey: 'sk-your-api-key-here',
    model: OpenAIClient::MODEL_GPT4O_MINI  // 或 MODEL_GPT4O, MODEL_GPT4_TURBO
);

// 非流式调用
$response = $openai->chat([
    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
    ['role' => 'user', 'content' => 'What is the capital of France?'],
]);
echo $response['choices'][0]['message']['content'];

// 流式调用
$openai->chatStream(
    [
        ['role' => 'user', 'content' => 'Tell me a story'],
    ],
    function($content, $chunk) {
        echo $content;  // 实时输出每个块
        flush();
    }
);


// ============================================================
// 示例 2: 直接使用 Google Gemini API 客户端
// ============================================================

$gemini = new GeminiClient(
    apiKey: 'your-gemini-api-key-here',
    model: GeminiClient::MODEL_GEMINI_25_FLASH  // 或 MODEL_GEMINI_25_PRO, MODEL_GEMINI_25_FLASH_LITE
);

// 非流式调用
$response = $gemini->chat([
    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
    ['role' => 'user', 'content' => 'Explain quantum computing'],
]);

// 流式调用（包含思考过程）
$gemini->chatStream(
    [['role' => 'user', 'content' => 'Solve this math problem...']],
    function($text, $chunk, $isThought) {
        if ($isThought) {
            echo "[思考] " . $text;
        } else {
            echo $text;
        }
        flush();
    }
);


// ============================================================
// 示例 3: 使用 CalibreAIService 高级封装（推荐）
// ============================================================

// 创建服务实例
$ai = new CalibreAIService(
    provider: 'openai',  // 或 'gemini'
    apiKey: 'your-api-key-here',
    model: ''  // 可选，留空使用默认模型
);

// --- 书库模式：讨论书籍 ---
$book = [
    'title' => 'The Trials of Empire',
    'authors' => 'Richard Swan',
    'series' => 'Empire of the Wolf',
    'tags' => ['Fantasy', 'Epic'],
    'comments' => 'A great fantasy novel about...',
];

// 使用预定义操作
$summary = $ai->discussBooks([$book], 'summarize', 'Chinese');
echo "=== 书籍摘要 ===\n{$summary}\n\n";

// 使用自定义问题
$analysis = $ai->discussBooks([$book], 'Analyze the themes of this book', 'Chinese');
echo "=== 主题分析 ===\n{$analysis}\n\n";

// 流式输出
echo "=== 流式输出 ===\n";
$ai->discussBooksStream([$book], 'chapters', function($content, $chunk) {
    echo $content;
    flush();
}, 'Chinese');
echo "\n\n";


// --- 阅读器模式：分析选中文本 ---
$selectedText = "The quantum entanglement phenomenon demonstrates...";

// 使用预定义操作
$explanation = $ai->analyzeText(
    selectedText: $selectedText,
    actionOrQuestion: 'explain',
    bookTitle: 'Quantum Physics for Beginners',
    bookAuthors: 'John Smith',
    language: 'Chinese'
);
echo "=== 解释 ===\n{$explanation}\n\n";

// 翻译
$translation = $ai->analyzeText(
    selectedText: 'paradigm shift',
    actionOrQuestion: 'translate',
    bookTitle: 'Philosophy of Science',
    bookAuthors: 'Jane Doe',
    language: 'Chinese'
);
echo "=== 翻译 ===\n{$translation}\n\n";


// ============================================================
// 示例 4: 仅生成提示词（不调用 API）
// ============================================================

$book = [
    'title' => 'The Trials of Empire',
    'authors' => 'Richard Swan',
    'series' => 'Empire of the Wolf',
    'tags' => ['Fantasy', 'Epic'],
];

// 生成系统提示词
$systemPrompt = CalibreAIPrompts::getLibrarySystemPrompt([$book], 'Chinese');
echo "=== 书库系统提示词 ===\n{$systemPrompt}\n\n";

// 生成操作提示
$actionPrompt = CalibreAIPrompts::getLibraryActionPrompt('summarize', 1);
echo "=== 操作提示 ===\n{$actionPrompt}\n\n";

// 阅读器系统提示词
$viewerSystemPrompt = CalibreAIPrompts::getViewerSystemPrompt(
    'The Trials of Empire',
    'Richard Swan',
    true,
    'Chinese'
);
echo "=== 阅读器系统提示词 ===\n{$viewerSystemPrompt}\n\n";

// 阅读器操作提示
$viewerActionPrompt = CalibreAIPrompts::getViewerActionPrompt(
    'explain',
    'paradigm shift',
    'Chinese'
);
echo "=== 阅读器操作提示 ===\n{$viewerActionPrompt}\n\n";

// 查看所有可用操作
echo "=== 书库模式可用操作 ===\n";
foreach (CalibreAIPrompts::getLibraryDefaultActions() as $name => $action) {
    echo "- {$name}: {$action['human_name']}\n";
}

echo "\n=== 阅读器模式可用操作 ===\n";
foreach (CalibreAIPrompts::getViewerDefaultActions() as $name => $action) {
    echo "- {$name}: {$action['human_name']}\n";
}
*/
