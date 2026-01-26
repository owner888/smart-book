<?php
/**
 * 向量存储 - 支持混合检索（关键词 + 向量）
 */

namespace SmartBook\RAG;

class VectorStore
{
    private array $chunks = [];
    private array $embeddings = [];
    private ?string $cacheFile = null;
    
    public function __construct(?string $cacheFile = null)
    {
        $this->cacheFile = $cacheFile;
        if ($cacheFile && file_exists($cacheFile)) $this->load();
    }
    
    public function add(array $chunk, array $embedding): void
    {
        $this->chunks[] = $chunk;
        $this->embeddings[] = $embedding;
    }
    
    public function addBatch(array $chunks, array $embeddings): void
    {
        foreach ($chunks as $i => $chunk) $this->add($chunk, $embeddings[$i] ?? []);
    }
    
    public function search(array $queryEmbedding, int $topK = DEFAULT_TOP_K): array
    {
        if (empty($this->embeddings)) return [];
        
        $scores = [];
        foreach ($this->embeddings as $i => $embedding) {
            $scores[$i] = $this->cosineSimilarity($queryEmbedding, $embedding);
        }
        arsort($scores);
        
        $results = [];
        $count = 0;
        foreach ($scores as $i => $score) {
            if ($count >= $topK) break;
            $results[] = ['chunk' => $this->chunks[$i], 'score' => $score, 'method' => 'vector'];
            $count++;
        }
        return $results;
    }
    
    public function hybridSearch(string $query, array $queryEmbedding, int $topK = DEFAULT_TOP_K, float $keywordWeight = 0.5): array
    {
        if (empty($this->chunks)) return [];
        
        $scores = [];
        $keywords = $this->extractKeywords($query);
        
        foreach ($this->chunks as $i => $chunk) {
            $scores[$i] = ['keyword' => $this->calculateKeywordScore($chunk['text'], $keywords), 'vector' => 0.0];
        }
        
        if (!empty($queryEmbedding) && !empty($this->embeddings)) {
            foreach ($this->embeddings as $i => $embedding) {
                $scores[$i]['vector'] = $this->cosineSimilarity($queryEmbedding, $embedding);
            }
        }
        
        $maxKeyword = max(array_column($scores, 'keyword')) ?: 1;
        $maxVector = max(array_column($scores, 'vector')) ?: 1;
        
        $finalScores = [];
        foreach ($scores as $i => $s) {
            $finalScores[$i] = $keywordWeight * ($s['keyword'] / $maxKeyword) + (1 - $keywordWeight) * ($s['vector'] / $maxVector);
        }
        arsort($finalScores);
        
        $results = [];
        $count = 0;
        foreach ($finalScores as $i => $score) {
            if ($count >= $topK) break;
            $results[] = ['chunk' => $this->chunks[$i], 'score' => $score, 'keyword_score' => $scores[$i]['keyword'], 'vector_score' => $scores[$i]['vector'], 'method' => 'hybrid'];
            $count++;
        }
        return $results;
    }
    
    private function extractKeywords(string $query): array
    {
        $words = preg_split('/[\s\p{P}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
        $keywords = [];
        foreach ($words as $word) {
            if (mb_strlen($word) >= 2) $keywords[] = $word;
            if (mb_strlen($word) > 2) {
                for ($i = 0; $i < mb_strlen($word) - 1; $i++) $keywords[] = mb_substr($word, $i, 2);
            }
        }
        return array_unique($keywords);
    }
    
    private function calculateKeywordScore(string $text, array $keywords): float
    {
        if (empty($keywords)) return 0.0;
        $score = 0.0;
        $textLower = mb_strtolower($text);
        foreach ($keywords as $keyword) {
            $count = mb_substr_count($textLower, mb_strtolower($keyword));
            if ($count > 0) $score += log(1 + $count) * mb_strlen($keyword);
        }
        return $score;
    }
    
    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) return 0.0;
        
        $dot = $normA = $normB = 0.0;
        for ($i = 0; $i < count($a); $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        $normA = sqrt($normA);
        $normB = sqrt($normB);
        
        return ($normA == 0 || $normB == 0) ? 0.0 : $dot / ($normA * $normB);
    }
    
    public function save(?string $file = null): void
    {
        $file = $file ?? $this->cacheFile;
        if (!$file) return;
        file_put_contents($file, json_encode(['chunks' => $this->chunks, 'embeddings' => $this->embeddings], JSON_UNESCAPED_UNICODE));
    }
    
    public function load(?string $file = null): void
    {
        $file = $file ?? $this->cacheFile;
        if (!$file || !file_exists($file)) return;
        $data = json_decode(file_get_contents($file), true);
        $this->chunks = $data['chunks'] ?? [];
        $this->embeddings = $data['embeddings'] ?? [];
    }
    
    public function count(): int { return count($this->chunks); }
    public function isEmpty(): bool { return empty($this->chunks); }
}
