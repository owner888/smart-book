<?php
/**
 * 世界观记忆系统
 * 
 * 用于提取、存储和检索书籍的世界设定
 * 确保续写时世界观的一致性
 */

namespace SmartBook\AI;

use SmartBook\Cache\CacheService;
use SmartBook\RAG\EmbeddingClient;

class WorldMemory
{
    private EmbeddingClient $embedder;
    private string $apiKey;
    
    // 世界观设定类别
    public const CATEGORIES = [
        'era' => '时代背景',         // 历史时期、年代
        'geography' => '地理环境',   // 地点、地图、气候
        'society' => '社会结构',     // 阶级、制度、习俗
        'magic' => '魔法/能力体系', // 超自然力量系统
        'technology' => '科技水平', // 技术发展程度
        'religion' => '宗教信仰',   // 神灵、信仰体系
        'organization' => '组织势力', // 帮派、门派、国家
        'item' => '重要物品',       // 神器、宝物、道具
        'rule' => '世界规则',       // 物理法则、魔法规则
        'language' => '语言文化',   // 特殊用语、称谓
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
        $booksDir = dirname(__DIR__, 2) . '/books';
        return $booksDir . '/' . pathinfo($bookFile, PATHINFO_FILENAME) . '.world.json';
    }
    
    /**
     * 保存世界观设定
     */
    public function saveWorldSettings(string $bookFile, array $settings): bool
    {
        $path = $this->getStoragePath($bookFile);
        
        $data = [
            'book' => $bookFile,
            'settings' => $settings,
            'createdAt' => time(),
            'version' => '1.0',
        ];
        
        // 生成设定的向量用于语义搜索
        $settingTexts = [];
        foreach ($settings as $category => $items) {
            foreach ($items as $item) {
                $text = $this->settingToText($category, $item);
                $settingTexts[] = [
                    'category' => $category,
                    'item' => $item,
                    'text' => $text,
                ];
            }
        }
        
        if (!empty($settingTexts)) {
            $texts = array_column($settingTexts, 'text');
            $embeddings = $this->embedder->embedBatch($texts);
            $data['settingTexts'] = $settingTexts;
            $data['embeddings'] = $embeddings;
        }
        
        // 保存到 Redis
        $redis = CacheService::getRedis();
        if ($redis) {
            $redis->setex("world:{$bookFile}", 86400 * 30, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * 将设定转换为可搜索文本
     */
    private function settingToText(string $category, array $item): string
    {
        $categoryName = self::CATEGORIES[$category] ?? $category;
        $parts = ["[{$categoryName}]"];
        
        if (!empty($item['name'])) {
            $parts[] = $item['name'];
        }
        if (!empty($item['description'])) {
            $parts[] = $item['description'];
        }
        if (!empty($item['rules'])) {
            $parts[] = "规则：" . implode('；', $item['rules']);
        }
        if (!empty($item['limitations'])) {
            $parts[] = "限制：" . implode('；', $item['limitations']);
        }
        
        return implode("\n", $parts);
    }
    
    /**
     * 加载世界观数据
     */
    public function loadWorldData(string $bookFile): ?array
    {
        // 优先从 Redis 读取
        $redis = CacheService::getRedis();
        if ($redis) {
            $cached = $redis->get("world:{$bookFile}");
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
            $redis->setex("world:{$bookFile}", 86400 * 30, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        return $data;
    }
    
    /**
     * 获取所有设定
     */
    public function getAllSettings(string $bookFile): array
    {
        $data = $this->loadWorldData($bookFile);
        return $data['settings'] ?? [];
    }
    
    /**
     * 获取特定类别的设定
     */
    public function getSettingsByCategory(string $bookFile, string $category): array
    {
        $settings = $this->getAllSettings($bookFile);
        return $settings[$category] ?? [];
    }
    
    /**
     * 根据续写内容搜索相关世界观设定
     */
    public function searchRelevantSettings(string $bookFile, string $query, int $topK = 5): array
    {
        $data = $this->loadWorldData($bookFile);
        if (!$data || empty($data['settingTexts'])) {
            return [];
        }
        
        $settingTexts = $data['settingTexts'];
        $embeddings = $data['embeddings'] ?? [];
        
        // 如果没有向量，使用关键词匹配
        if (empty($embeddings)) {
            return $this->keywordSearchSettings($settingTexts, $query, $topK);
        }
        
        // 生成查询向量
        $queryEmbedding = $this->embedder->embedQuery($query);
        
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
            if (isset($settingTexts[$i]) && $score > 0.3) {
                $results[] = [
                    'category' => $settingTexts[$i]['category'],
                    'item' => $settingTexts[$i]['item'],
                    'score' => $score,
                ];
                $count++;
            }
        }
        
        return $results;
    }
    
    /**
     * 关键词搜索设定
     */
    private function keywordSearchSettings(array $settingTexts, string $query, int $topK): array
    {
        $keywords = $this->extractKeywords($query);
        $scores = [];
        
        foreach ($settingTexts as $i => $setting) {
            $text = $setting['text'];
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
                    'category' => $settingTexts[$i]['category'],
                    'item' => $settingTexts[$i]['item'],
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
     * 生成世界观摘要（用于注入提示词）
     */
    public function generateWorldSummary(array $relevantSettings, bool $detailed = false): string
    {
        if (empty($relevantSettings)) {
            return '';
        }
        
        $summary = "## 世界观设定\n\n";
        
        // 按类别分组
        $grouped = [];
        foreach ($relevantSettings as $item) {
            $category = $item['category'];
            $grouped[$category][] = $item['item'];
        }
        
        foreach ($grouped as $category => $items) {
            $categoryName = self::CATEGORIES[$category] ?? $category;
            $summary .= "### {$categoryName}\n\n";
            
            foreach ($items as $item) {
                $name = $item['name'] ?? '未命名';
                $summary .= "- **{$name}**";
                
                if (!empty($item['description'])) {
                    $summary .= ": {$item['description']}";
                }
                $summary .= "\n";
                
                if ($detailed) {
                    if (!empty($item['rules'])) {
                        $summary .= "  - 规则：" . implode('；', $item['rules']) . "\n";
                    }
                    if (!empty($item['limitations'])) {
                        $summary .= "  - 限制：" . implode('；', $item['limitations']) . "\n";
                    }
                    if (!empty($item['locations'])) {
                        $summary .= "  - 地点：" . implode('、', $item['locations']) . "\n";
                    }
                }
            }
            $summary .= "\n";
        }
        
        return $summary;
    }
    
    /**
     * 生成基础世界观概述（用于每次续写的基础上下文）
     */
    public function generateBasicOverview(string $bookFile): string
    {
        $settings = $this->getAllSettings($bookFile);
        
        if (empty($settings)) {
            return '';
        }
        
        $overview = "## 故事世界观\n\n";
        
        // 时代背景
        if (!empty($settings['era'])) {
            $era = $settings['era'][0] ?? null;
            if ($era) {
                $overview .= "**时代**：" . ($era['name'] ?? '') . "\n";
                if (!empty($era['description'])) {
                    $overview .= $era['description'] . "\n";
                }
                $overview .= "\n";
            }
        }
        
        // 地理环境
        if (!empty($settings['geography'])) {
            $overview .= "**主要地点**：";
            $locations = array_map(fn($g) => $g['name'] ?? '', $settings['geography']);
            $overview .= implode('、', array_filter($locations)) . "\n\n";
        }
        
        // 社会结构
        if (!empty($settings['society'])) {
            $overview .= "**社会背景**：";
            $society = $settings['society'][0] ?? null;
            if ($society && !empty($society['description'])) {
                $overview .= $society['description'] . "\n";
            }
            $overview .= "\n";
        }
        
        // 魔法/能力体系
        if (!empty($settings['magic'])) {
            $overview .= "**能力体系**：";
            $magic = $settings['magic'][0] ?? null;
            if ($magic) {
                $overview .= ($magic['name'] ?? '') . "\n";
                if (!empty($magic['rules'])) {
                    $overview .= "核心规则：" . implode('；', array_slice($magic['rules'], 0, 3)) . "\n";
                }
            }
            $overview .= "\n";
        }
        
        // 特殊用语
        if (!empty($settings['language'])) {
            $overview .= "**特殊用语**：\n";
            foreach (array_slice($settings['language'], 0, 5) as $lang) {
                $overview .= "- {$lang['name']}：{$lang['description']}\n";
            }
            $overview .= "\n";
        }
        
        return $overview;
    }
    
    /**
     * 添加单个设定
     */
    public function addSetting(string $bookFile, string $category, array $item): bool
    {
        $data = $this->loadWorldData($bookFile);
        if (!$data) {
            $data = [
                'book' => $bookFile,
                'settings' => [],
                'settingTexts' => [],
                'embeddings' => [],
                'createdAt' => time(),
                'version' => '1.0',
            ];
        }
        
        // 添加设定
        if (!isset($data['settings'][$category])) {
            $data['settings'][$category] = [];
        }
        $data['settings'][$category][] = $item;
        
        // 生成向量
        $text = $this->settingToText($category, $item);
        $embedding = $this->embedder->embedQuery($text);
        
        $data['settingTexts'][] = [
            'category' => $category,
            'item' => $item,
            'text' => $text,
        ];
        $data['embeddings'][] = $embedding;
        
        // 保存
        $redis = CacheService::getRedis();
        if ($redis) {
            $redis->setex("world:{$bookFile}", 86400 * 30, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        $path = $this->getStoragePath($bookFile);
        return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * 检查是否有世界观数据
     */
    public function hasWorldData(string $bookFile): bool
    {
        $redis = CacheService::getRedis();
        if ($redis && $redis->exists("world:{$bookFile}")) {
            return true;
        }
        
        return file_exists($this->getStoragePath($bookFile));
    }
}
