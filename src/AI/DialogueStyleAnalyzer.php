<?php
/**
 * 对话风格分析系统
 * 
 * 分析和存储书籍中各角色的对话风格
 * 确保续写时人物对话的一致性
 */

namespace SmartBook\AI;

use SmartBook\Cache\CacheService;
use SmartBook\RAG\EmbeddingClient;

class DialogueStyleAnalyzer
{
    private EmbeddingClient $embedder;
    private string $apiKey;
    
    // 对话风格特征
    public const STYLE_FEATURES = [
        'formality' => '正式程度',      // 正式/随意
        'vocabulary' => '用词特点',     // 文雅/粗俗/专业
        'sentence' => '句式特点',       // 长句/短句/感叹
        'emotion' => '情感表达',        // 内敛/外放
        'rhythm' => '语言节奏',         // 快速/舒缓
        'dialect' => '方言特色',        // 地域特色用语
    ];
    
    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->embedder = new EmbeddingClient($apiKey);
    }
    
    /**
     * 获取存储路径
     */
    private function getStoragePath(string $bookFile): string
    {
        return BOOKS_DIR . '/' . pathinfo($bookFile, PATHINFO_FILENAME) . '.dialogue.json';
    }
    
    /**
     * 保存对话风格数据
     */
    public function saveDialogueStyles(string $bookFile, array $styles): bool
    {
        $path = $this->getStoragePath($bookFile);
        
        $data = [
            'book' => $bookFile,
            'styles' => $styles,
            'createdAt' => time(),
            'version' => '1.0',
        ];
        
        // 生成对话示例的向量用于语义搜索
        $dialogueTexts = [];
        foreach ($styles as $characterName => $style) {
            $examples = $style['examples'] ?? [];
            foreach ($examples as $example) {
                $dialogueTexts[] = [
                    'character' => $characterName,
                    'dialogue' => $example,
                    'text' => "{$characterName}：{$example}",
                ];
            }
        }
        
        if (!empty($dialogueTexts)) {
            $texts = array_column($dialogueTexts, 'text');
            $embeddings = $this->embedder->embedBatch($texts);
            $data['dialogueTexts'] = $dialogueTexts;
            $data['embeddings'] = $embeddings;
        }
        
        // 保存到 Redis
        $redis = CacheService::getRedis();
        if ($redis) {
            $redis->setex("dialogue:{$bookFile}", 86400 * 30, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * 加载对话风格数据
     */
    public function loadDialogueData(string $bookFile): ?array
    {
        // 优先从 Redis 读取
        $redis = CacheService::getRedis();
        if ($redis) {
            $cached = $redis->get("dialogue:{$bookFile}");
            if ($cached) {
                return json_decode($cached, true);
            }
        }
        
        // 从文件读取
        $path = $this->getStoragePath($bookFile);
        if (!file_exists($path)) {
            return null;
        }
        
        $data = json_decode(file_get_contents($path), true);
        
        // 缓存到 Redis
        if ($redis && $data) {
            $redis->setex("dialogue:{$bookFile}", 86400 * 30, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        return $data;
    }
    
    /**
     * 获取所有角色的对话风格
     */
    public function getAllStyles(string $bookFile): array
    {
        $data = $this->loadDialogueData($bookFile);
        return $data['styles'] ?? [];
    }
    
    /**
     * 获取特定角色的对话风格
     */
    public function getCharacterStyle(string $bookFile, string $characterName): ?array
    {
        $styles = $this->getAllStyles($bookFile);
        
        // 精确匹配
        if (isset($styles[$characterName])) {
            return $styles[$characterName];
        }
        
        // 模糊匹配
        foreach ($styles as $name => $style) {
            if (mb_stripos($name, $characterName) !== false || 
                mb_stripos($characterName, $name) !== false) {
                return $style;
            }
        }
        
        return null;
    }
    
    /**
     * 搜索相似对话场景
     */
    public function searchSimilarDialogues(string $bookFile, string $context, int $topK = DEFAULT_TOP_K): array
    {
        $data = $this->loadDialogueData($bookFile);
        if (!$data || empty($data['dialogueTexts'])) {
            return [];
        }
        
        $dialogueTexts = $data['dialogueTexts'];
        $embeddings = $data['embeddings'] ?? [];
        
        if (empty($embeddings)) {
            return $this->keywordSearchDialogues($dialogueTexts, $context, $topK);
        }
        
        // 生成查询向量
        $queryEmbedding = $this->embedder->embedQuery($context);
        
        // 计算相似度
        $scores = [];
        foreach ($embeddings as $i => $embedding) {
            $scores[$i] = $this->cosineSimilarity($queryEmbedding, $embedding);
        }
        
        arsort($scores);
        
        // 返回 top-k
        $results = [];
        $count = 0;
        foreach ($scores as $i => $score) {
            if ($count >= $topK) break;
            if (isset($dialogueTexts[$i]) && $score > 0.3) {
                $results[] = [
                    'character' => $dialogueTexts[$i]['character'],
                    'dialogue' => $dialogueTexts[$i]['dialogue'],
                    'score' => $score,
                ];
                $count++;
            }
        }
        
        return $results;
    }
    
    /**
     * 关键词搜索对话
     */
    private function keywordSearchDialogues(array $dialogueTexts, string $context, int $topK): array
    {
        $keywords = $this->extractKeywords($context);
        $scores = [];
        
        foreach ($dialogueTexts as $i => $item) {
            $text = $item['text'];
            $score = 0;
            foreach ($keywords as $keyword) {
                if (mb_stripos($text, $keyword) !== false) {
                    $score++;
                }
            }
            $scores[$i] = $score;
        }
        
        arsort($scores);
        
        $results = [];
        $count = 0;
        foreach ($scores as $i => $score) {
            if ($count >= $topK) break;
            if ($score > 0) {
                $results[] = [
                    'character' => $dialogueTexts[$i]['character'],
                    'dialogue' => $dialogueTexts[$i]['dialogue'],
                    'score' => $score,
                ];
                $count++;
            }
        }
        
        return $results;
    }
    
    /**
     * 提取关键词
     */
    private function extractKeywords(string $text): array
    {
        $pattern = '/[\x{FF0C}\x{3002}\x{FF01}\x{FF1F}\x{3001}\x{FF1B}\x{FF1A}\x{201C}\x{201D}\x{2018}\x{2019}\x{FF08}\x{FF09}\x{3010}\x{3011}\[\]\s]+/u';
        $text = preg_replace($pattern, ' ', $text);
        $words = array_filter(explode(' ', $text));
        return array_filter($words, function($w) {
            return mb_strlen($w) >= 2;
        });
    }
    
    /**
     * 计算余弦相似度
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }
        
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        
        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        
        $normA = sqrt($normA);
        $normB = sqrt($normB);
        
        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }
        
        return $dotProduct / ($normA * $normB);
    }
    
    /**
     * 生成对话风格摘要（用于注入提示词）
     */
    public function generateDialogueSummary(array $characters, bool $includeExamples = true): string
    {
        if (empty($characters)) {
            return '';
        }
        
        $summary = "## 角色对话风格\n\n";
        $summary .= "以下是相关角色的说话风格，续写对话时请严格遵循：\n\n";
        
        foreach ($characters as $name => $style) {
            $summary .= "### {$name}\n\n";
            
            // 基本特征
            if (!empty($style['features'])) {
                $summary .= "**风格特征**：\n";
                foreach ($style['features'] as $feature => $value) {
                    $featureName = self::STYLE_FEATURES[$feature] ?? $feature;
                    $summary .= "- {$featureName}：{$value}\n";
                }
                $summary .= "\n";
            }
            
            // 口头禅
            if (!empty($style['catchphrases'])) {
                $summary .= "**口头禅**：" . implode('、', $style['catchphrases']) . "\n\n";
            }
            
            // 用词习惯
            if (!empty($style['vocabulary'])) {
                $summary .= "**常用词语**：" . implode('、', $style['vocabulary']) . "\n\n";
            }
            
            // 对话示例
            if ($includeExamples && !empty($style['examples'])) {
                $summary .= "**对话示例**：\n";
                foreach (array_slice($style['examples'], 0, 3) as $example) {
                    $summary .= "> \"{$example}\"\n";
                }
                $summary .= "\n";
            }
            
            // 禁忌用语
            if (!empty($style['forbidden'])) {
                $summary .= "**禁忌**：此角色不会说 " . implode('、', $style['forbidden']) . "\n\n";
            }
        }
        
        return $summary;
    }
    
    /**
     * 生成对话写作指南
     */
    public function generateDialogueGuidelines(string $bookFile): string
    {
        $styles = $this->getAllStyles($bookFile);
        
        if (empty($styles)) {
            return '';
        }
        
        $guidelines = "## 对话写作指南\n\n";
        
        // 统计共性特征
        $formalCount = 0;
        $casualCount = 0;
        $dialectChars = [];
        
        foreach ($styles as $name => $style) {
            $formality = $style['features']['formality'] ?? '';
            if (mb_strpos($formality, '正式') !== false || mb_strpos($formality, '文雅') !== false) {
                $formalCount++;
            } else {
                $casualCount++;
            }
            
            if (!empty($style['features']['dialect'])) {
                $dialectChars[$name] = $style['features']['dialect'];
            }
        }
        
        // 整体风格
        if ($formalCount > $casualCount) {
            $guidelines .= "**整体风格**：本书对话较为正式文雅\n\n";
        } else {
            $guidelines .= "**整体风格**：本书对话较为通俗自然\n\n";
        }
        
        // 方言角色
        if (!empty($dialectChars)) {
            $guidelines .= "**方言角色**：\n";
            foreach ($dialectChars as $name => $dialect) {
                $guidelines .= "- {$name}：{$dialect}\n";
            }
            $guidelines .= "\n";
        }
        
        // 快速参考
        $guidelines .= "**各角色关键特征**：\n";
        foreach ($styles as $name => $style) {
            $catchphrase = $style['catchphrases'][0] ?? '';
            $vocab = $style['vocabulary'][0] ?? '';
            
            $guidelines .= "- **{$name}**：";
            if ($catchphrase) {
                $guidelines .= "口头禅「{$catchphrase}」";
            }
            if ($vocab) {
                $guidelines .= "，常说「{$vocab}」";
            }
            $guidelines .= "\n";
        }
        
        return $guidelines;
    }
    
    /**
     * 检查是否有对话风格数据
     */
    public function hasDialogueData(string $bookFile): bool
    {
        $redis = CacheService::getRedis();
        if ($redis && $redis->exists("dialogue:{$bookFile}")) {
            return true;
        }
        
        return file_exists($this->getStoragePath($bookFile));
    }
}
