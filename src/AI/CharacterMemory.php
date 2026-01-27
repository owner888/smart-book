<?php
/**
 * 人物记忆系统
 * 
 * 用于提取、存储和检索书籍中的人物信息
 * 确保续写时人物性格和行为的一致性
 */

namespace SmartBook\AI;

use SmartBook\Cache\CacheService;
use SmartBook\RAG\VectorStore;
use SmartBook\RAG\EmbeddingClient;

class CharacterMemory
{
    private EmbeddingClient $embedder;
    private string $apiKey;
    
    // 人物卡片结构
    public const CARD_TEMPLATE = [
        'name' => '',           // 姓名
        'aliases' => [],        // 别名/外号
        'identity' => '',       // 身份/职业
        'personality' => [],    // 性格特点
        'appearance' => '',     // 外貌描述
        'speech_style' => '',   // 说话风格/口头禅
        'relationships' => [],  // 人物关系
        'arc' => '',           // 人物弧光
        'key_events' => [],    // 关键事件
    ];
    
    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->embedder = new EmbeddingClient($apiKey);
    }
    
    /**
     * 获取书籍的人物记忆存储路径
     */
    private function getStoragePath(string $bookFile): string
    {
        return BOOKS_DIR . '/' . pathinfo($bookFile, PATHINFO_FILENAME) . '.characters.json';
    }
    
    /**
     * 保存人物卡片到存储
     */
    public function saveCharacters(string $bookFile, array $characters): bool
    {
        $path = $this->getStoragePath($bookFile);
        
        $data = [
            'book' => $bookFile,
            'characters' => $characters,
            'createdAt' => time(),
            'version' => '1.0',
        ];
        
        // 生成人物名称的向量用于语义搜索
        $characterTexts = [];
        foreach ($characters as $char) {
            $text = $this->characterToText($char);
            $characterTexts[] = $text;
        }
        
        if (!empty($characterTexts)) {
            $embeddings = $this->embedder->embedBatch($characterTexts);
            $data['embeddings'] = $embeddings;
        }
        
        // 同时保存到 Redis 用于快速访问
        $redis = CacheService::getRedis();
        if ($redis) {
            $redisKey = "characters:{$bookFile}";
            $redis->setex($redisKey, 86400 * 7, json_encode($data, JSON_UNESCAPED_UNICODE)); // 7天
        }
        
        return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * 将人物卡片转换为可搜索的文本
     */
    private function characterToText(array $character): string
    {
        $parts = [];
        
        if (!empty($character['name'])) {
            $parts[] = "人物：{$character['name']}";
        }
        if (!empty($character['aliases'])) {
            $parts[] = "别名：" . implode('、', $character['aliases']);
        }
        if (!empty($character['identity'])) {
            $parts[] = "身份：{$character['identity']}";
        }
        if (!empty($character['personality'])) {
            $parts[] = "性格：" . implode('、', $character['personality']);
        }
        if (!empty($character['appearance'])) {
            $parts[] = "外貌：{$character['appearance']}";
        }
        if (!empty($character['speech_style'])) {
            $parts[] = "说话风格：{$character['speech_style']}";
        }
        if (!empty($character['relationships'])) {
            $rels = [];
            foreach ($character['relationships'] as $rel) {
                $rels[] = "{$rel['target']}({$rel['relation']})";
            }
            $parts[] = "关系：" . implode('、', $rels);
        }
        if (!empty($character['arc'])) {
            $parts[] = "人物弧光：{$character['arc']}";
        }
        
        return implode("\n", $parts);
    }
    
    /**
     * 加载书籍的人物数据
     */
    public function loadCharacters(string $bookFile): ?array
    {
        // 优先从 Redis 读取
        $redis = CacheService::getRedis();
        if ($redis) {
            $redisKey = "characters:{$bookFile}";
            $cached = $redis->get($redisKey);
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
        
        // 加载到 Redis
        if ($redis && $data) {
            $redis->setex("characters:{$bookFile}", 86400 * 7, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        return $data;
    }
    
    /**
     * 获取所有人物列表
     */
    public function getCharacterList(string $bookFile): array
    {
        $data = $this->loadCharacters($bookFile);
        if (!$data) {
            return [];
        }
        
        return array_map(function($char) {
            return [
                'name' => $char['name'] ?? '',
                'identity' => $char['identity'] ?? '',
                'personality' => $char['personality'] ?? [],
            ];
        }, $data['characters'] ?? []);
    }
    
    /**
     * 根据名字获取单个人物
     */
    public function getCharacter(string $bookFile, string $name): ?array
    {
        $data = $this->loadCharacters($bookFile);
        if (!$data) {
            return null;
        }
        
        foreach ($data['characters'] ?? [] as $char) {
            if ($char['name'] === $name) {
                return $char;
            }
            // 检查别名
            if (in_array($name, $char['aliases'] ?? [])) {
                return $char;
            }
        }
        
        return null;
    }
    
    /**
     * 根据续写内容搜索相关人物
     * 
     * @param string $bookFile 书籍文件名
     * @param string $query 搜索内容（如续写提示词）
     * @param int $topK 返回人物数量
     * @return array 相关人物列表
     */
    public function searchRelevantCharacters(string $bookFile, string $query, int $topK = DEFAULT_TOP_K): array
    {
        $data = $this->loadCharacters($bookFile);
        if (!$data || empty($data['characters'])) {
            return [];
        }
        
        $characters = $data['characters'];
        $embeddings = $data['embeddings'] ?? [];
        
        // 如果没有向量，使用关键词匹配
        if (empty($embeddings)) {
            return $this->keywordSearch($characters, $query, $topK);
        }
        
        // 生成查询向量
        $queryEmbedding = $this->embedder->embedQuery($query);
        
        // 计算相似度
        $scores = [];
        foreach ($embeddings as $i => $embedding) {
            $scores[$i] = $this->cosineSimilarity($queryEmbedding, $embedding);
        }
        
        // 排序
        arsort($scores);
        
        // 返回 top-k
        $results = [];
        $count = 0;
        foreach ($scores as $i => $score) {
            if ($count >= $topK) break;
            if (isset($characters[$i])) {
                $results[] = [
                    'character' => $characters[$i],
                    'score' => $score,
                ];
                $count++;
            }
        }
        
        return $results;
    }
    
    /**
     * 关键词搜索人物
     */
    private function keywordSearch(array $characters, string $query, int $topK): array
    {
        $keywords = $this->extractKeywords($query);
        $scores = [];
        
        foreach ($characters as $i => $char) {
            $text = $this->characterToText($char);
            $score = 0;
            foreach ($keywords as $keyword) {
                if (mb_stripos($text, $keyword) !== false) {
                    $score++;
                }
                // 名字匹配权重更高
                if (mb_stripos($char['name'] ?? '', $keyword) !== false) {
                    $score += 3;
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
                    'character' => $characters[$i],
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
        // 简单的中文分词（按标点和空格分割）
        $pattern = '/[\x{FF0C}\x{3002}\x{FF01}\x{FF1F}\x{3001}\x{FF1B}\x{FF1A}\x{201C}\x{201D}\x{2018}\x{2019}\x{FF08}\x{FF09}\x{3010}\x{3011}\[\]\s]+/u';
        $text = preg_replace($pattern, ' ', $text);
        $words = array_filter(explode(' ', $text));
        
        // 过滤掉太短的词
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
     * 生成人物信息摘要（用于注入提示词）
     */
    public function generateCharacterSummary(array $characters, bool $detailed = false): string
    {
        if (empty($characters)) {
            return '';
        }
        
        $summary = "## 相关人物\n\n";
        
        foreach ($characters as $item) {
            $char = $item['character'] ?? $item;
            
            $summary .= "### {$char['name']}\n";
            
            if (!empty($char['identity'])) {
                $summary .= "- **身份**: {$char['identity']}\n";
            }
            
            if (!empty($char['personality'])) {
                $traits = is_array($char['personality']) 
                    ? implode('、', $char['personality']) 
                    : $char['personality'];
                $summary .= "- **性格**: {$traits}\n";
            }
            
            if ($detailed) {
                if (!empty($char['appearance'])) {
                    $summary .= "- **外貌**: {$char['appearance']}\n";
                }
                
                if (!empty($char['speech_style'])) {
                    $summary .= "- **说话风格**: {$char['speech_style']}\n";
                }
                
                if (!empty($char['relationships'])) {
                    $rels = [];
                    foreach ($char['relationships'] as $rel) {
                        $rels[] = "{$rel['target']}（{$rel['relation']}）";
                    }
                    $summary .= "- **人物关系**: " . implode('、', $rels) . "\n";
                }
            }
            
            $summary .= "\n";
        }
        
        return $summary;
    }
    
    /**
     * 检查人物记忆是否存在
     */
    public function hasCharacterData(string $bookFile): bool
    {
        $redis = CacheService::getRedis();
        if ($redis && $redis->exists("characters:{$bookFile}")) {
            return true;
        }
        
        return file_exists($this->getStoragePath($bookFile));
    }
}
