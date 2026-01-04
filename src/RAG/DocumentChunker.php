<?php
/**
 * 文档分块器
 */

namespace SmartBook\RAG;

class DocumentChunker
{
    private int $chunkSize;
    private int $chunkOverlap;
    
    public function __construct(int $chunkSize = 500, int $chunkOverlap = 100)
    {
        $this->chunkSize = $chunkSize;
        $this->chunkOverlap = $chunkOverlap;
    }
    
    public function chunk(string $text): array
    {
        $chunks = [];
        $text = $this->cleanText($text);
        $paragraphs = preg_split('/\n{2,}/', $text);
        
        $currentChunk = '';
        $chunkIndex = 0;
        
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (empty($para)) continue;
            
            if (mb_strlen($para) > $this->chunkSize) {
                if (!empty($currentChunk)) {
                    $chunks[] = $this->createChunk($currentChunk, $chunkIndex++);
                    $currentChunk = '';
                }
                
                $sentences = $this->splitIntoSentences($para);
                $sentenceChunk = '';
                
                foreach ($sentences as $sentence) {
                    if (mb_strlen($sentenceChunk . $sentence) > $this->chunkSize && !empty($sentenceChunk)) {
                        $chunks[] = $this->createChunk($sentenceChunk, $chunkIndex++);
                        $sentenceChunk = mb_substr($sentenceChunk, -$this->chunkOverlap) . $sentence;
                    } else {
                        $sentenceChunk .= $sentence;
                    }
                }
                if (!empty($sentenceChunk)) $currentChunk = $sentenceChunk;
            } else {
                if (mb_strlen($currentChunk . "\n\n" . $para) > $this->chunkSize && !empty($currentChunk)) {
                    $chunks[] = $this->createChunk($currentChunk, $chunkIndex++);
                    $currentChunk = mb_substr($currentChunk, -$this->chunkOverlap) . "\n\n" . $para;
                } else {
                    $currentChunk .= (empty($currentChunk) ? '' : "\n\n") . $para;
                }
            }
        }
        
        if (!empty($currentChunk)) $chunks[] = $this->createChunk($currentChunk, $chunkIndex);
        
        return $chunks;
    }
    
    private function createChunk(string $text, int $index): array
    {
        return ['id' => $index, 'text' => trim($text), 'length' => mb_strlen($text)];
    }
    
    private function cleanText(string $text): string
    {
        return trim(preg_replace('/\n{3,}/', "\n\n", preg_replace('/[ \t]+/', ' ', $text)));
    }
    
    private function splitIntoSentences(string $text): array
    {
        return preg_split('/(?<=[。！？.!?])\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    }
}
