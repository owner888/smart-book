<?php
/**
 * Calibre AI 提示词 - 从 calibre 源码提取
 * 源文件: src/calibre/gui2/dialogs/llm_book.py 和 src/calibre/gui2/viewer/llm.py
 */

class CalibreAIPrompts
{
    // ===================================
    // 书库讨论模式 (Library Book Discussion)
    // ===================================
    
    public static function formatBookForQuery(array $book, bool $isFirst = true, int $numBooks = 1): string
    {
        $which = $numBooks < 2 ? '' : ($isFirst ? 'first ' : 'next ');
        $ans = "The {$which}book is: {$book['title']} by {$book['authors']}.";
        
        if (!empty($book['series'])) $ans .= " It is in the series: {$book['series']}.";
        if (!empty($book['tags'])) {
            $tags = is_array($book['tags']) ? implode(', ', $book['tags']) : $book['tags'];
            $ans .= " It is tagged with the following tags: {$tags}.";
        }
        
        $metadataFields = ['publisher', 'pubdate', 'rating', 'language'];
        $additionalMeta = [];
        foreach ($metadataFields as $field) {
            if (!empty($book[$field])) $additionalMeta[] = ucfirst($field) . ": " . $book[$field];
        }
        if (!empty($additionalMeta)) $ans .= " It has the following additional metadata.\n" . implode("\n", $additionalMeta);
        if (!empty($book['comments'])) $ans .= "\nSome notes about this book:\n" . $book['comments'];
        
        return $ans;
    }
    
    public static function formatBooksForQuery(array $books): string
    {
        $count = count($books);
        $ans = $count > 1 ? "I wish to discuss the following books. " : "I wish to discuss the following book. ";
        
        foreach ($books as $i => $book) {
            $ans .= self::formatBookForQuery($book, $i === 0, $count);
            $ans .= "\n---------------\n\n";
        }
        return $ans;
    }
    
    public static function getLibrarySystemPrompt(array $books, string $language = ''): string
    {
        $contextHeader = self::formatBooksForQuery($books);
        $contextHeader .= ' When you answer the questions use markdown formatting for the answers wherever possible.';
        
        $contextHeader .= count($books) > 1
            ? ' If any of the specified books are unknown to you, instead of answering the following questions, just say the books are unknown.'
            : ' If the specified book is unknown to you instead of answering the following questions just say the book is unknown.';
        
        if (!empty($language)) $contextHeader .= " If you can speak in {$language}, then respond in {$language}.";
        
        return $contextHeader;
    }
    
    public static function getLibraryDefaultActions(): array
    {
        return [
            'summarize' => ['name' => 'summarize', 'human_name' => 'Summarize', 'prompt_template' => 'Provide a concise summary of the previously described {books_word}.'],
            'chapters' => ['name' => 'chapters', 'human_name' => 'Chapters', 'prompt_template' => 'Provide a chapter by chapter summary of the previously described {books_word}.'],
            'read_next' => ['name' => 'read_next', 'human_name' => 'Read next', 'prompt_template' => 'Suggest some good books to read after the previously described {books_word}.'],
            'universe' => ['name' => 'universe', 'human_name' => 'Universe', 'prompt_template' => 'Describe the fictional universe the previously described {books_word} {is_are} set in. Outline major plots, themes and characters in the universe.'],
            'series' => ['name' => 'series', 'human_name' => 'Series', 'prompt_template' => 'Give the series the previously described {books_word} {is_are} in. List all the books in the series, in both published and internal chronological order. Also describe any prominent spin-off series.'],
        ];
    }
    
    public static function getLibraryActionPrompt(string $actionName, int $bookCount): string
    {
        $actions = self::getLibraryDefaultActions();
        if (!isset($actions[$actionName])) return '';
        
        return str_replace(
            ['{books_word}', '{is_are}'],
            [$bookCount < 2 ? 'book' : 'books', $bookCount < 2 ? 'is' : 'are'],
            $actions[$actionName]['prompt_template']
        );
    }
    
    // ===================================
    // 阅读器模式 (E-book Viewer)
    // ===================================
    
    public static function getViewerSystemPrompt(string $bookTitle, string $bookAuthors = '', bool $hasSelectedText = false, string $language = ''): string
    {
        if (empty($bookTitle)) return '';
        
        $contextHeader = "I am currently reading the book: {$bookTitle}";
        if (!empty($bookAuthors)) $contextHeader .= " by {$bookAuthors}";
        $contextHeader .= $hasSelectedText ? '. I have some questions about content from this book.' : '. I have some questions about this book.';
        $contextHeader .= ' When you answer the questions use markdown formatting for the answers wherever possible.';
        if (!empty($language)) $contextHeader .= " If you can speak in {$language}, then respond in {$language}.";
        
        return $contextHeader;
    }
    
    public static function getViewerDefaultActions(): array
    {
        return [
            'explain' => ['name' => 'explain', 'human_name' => 'Explain', 'prompt_template' => 'Explain the following text in simple, easy to understand language. {selected}', 'word_prompt' => 'Explain the meaning, etymology and common usages of the following word in simple, easy to understand language. {selected}'],
            'define' => ['name' => 'define', 'human_name' => 'Define', 'prompt_template' => 'Identify and define any technical or complex terms in the following text. {selected}', 'word_prompt' => 'Explain the meaning and common usages of the following word. {selected}'],
            'summarize' => ['name' => 'summarize', 'human_name' => 'Summarize', 'prompt_template' => 'Provide a concise summary of the following text. {selected}'],
            'points' => ['name' => 'points', 'human_name' => 'Key points', 'prompt_template' => 'Extract the key points from the following text as a bulleted list. {selected}'],
            'grammar' => ['name' => 'grammar', 'human_name' => 'Fix grammar', 'prompt_template' => 'Correct any grammatical errors in the following text and provide the corrected version. {selected}'],
            'translate' => ['name' => 'translate', 'human_name' => 'Translate', 'prompt_template' => 'Translate the following text into the language {language}. {selected}', 'word_prompt' => 'Translate the following word into the language {language}. {selected}'],
        ];
    }
    
    public static function isSingleWord(string $text): bool
    {
        return strlen($text) <= 20 && strpos($text, ' ') === false;
    }
    
    public static function getViewerActionPrompt(string $actionName, string $selectedText, string $language = 'English'): string
    {
        $actions = self::getViewerDefaultActions();
        if (!isset($actions[$actionName])) return '';
        
        $action = $actions[$actionName];
        $isSingleWord = self::isSingleWord($selectedText);
        $template = ($isSingleWord && isset($action['word_prompt'])) ? $action['word_prompt'] : $action['prompt_template'];
        
        $what = $isSingleWord ? 'Word to analyze: ' : 'Text to analyze: ';
        $formattedSelected = !empty($selectedText) ? "\n\n------\n\n" . $what . $selectedText : '';
        
        return str_replace(['{selected}', '{language}'], [$formattedSelected, $language], $template);
    }
    
    public static function formatConversationAsNote(array $messages, string $assistantName = 'Assistant', string $title = 'AI Assistant Note'): string
    {
        if (empty($messages)) return '';
        
        $mainResponse = '';
        foreach (array_reverse($messages) as $message) {
            if ($message['type'] === 'assistant') { $mainResponse = trim($message['content']); break; }
        }
        if (empty($mainResponse)) return '';
        
        $timestamp = date('Y-m-d H:i');
        $sep = '―――';
        $header = "{$sep} {$title} ({$timestamp}) {$sep}";
        
        if (count($messages) === 1) return "{$header}\n\n{$mainResponse}";
        
        $recordLines = [];
        foreach ($messages as $message) {
            $role = match($message['type']) { 'user' => 'You', 'assistant' => $assistantName, default => null };
            if ($role) $recordLines[] = "{$role}: " . trim($message['content']);
        }
        
        return "{$header}\n\n{$mainResponse}\n\n{$sep} Conversation record {$sep}\n\n" . implode("\n\n", $recordLines);
    }
    
    public static function getLanguageInstruction(string $language): string
    {
        return empty($language) ? '' : "If you can speak in {$language}, then respond in {$language}.";
    }
}
