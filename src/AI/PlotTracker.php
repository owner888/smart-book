<?php
/**
 * 情节追踪系统
 * 
 * 用于提取、存储和检索故事中的关键情节和事件
 * 确保续写时不会出现前后矛盾
 */

namespace SmartBook\AI;

use SmartBook\Cache\CacheService;
use SmartBook\RAG\EmbeddingClient;

class PlotTracker
{
    private EmbeddingClient $embedder;
    private string $apiKey;
    
    // 事件类型
    public const EVENT_TYPES = [
        'plot' => '情节发展',      // 主要情节点
        'revelation' => '揭示',    // 秘密/真相揭露
        'death' => '死亡',         // 角色死亡
        'relationship' => '关系变化', // 角色关系变化
        'location' => '地点变化',   // 重要地点转移
        'item' => '物品',          // 重要道具/物品
        'ability' => '能力变化',   // 角色能力变化
        'conflict' => '冲突',      // 主要冲突
        'resolution' => '解决',    // 问题解决
    ];
    
    // 事件状态
    public const STATUS = [
        'ongoing' => '进行中',     // 尚未解决
        'resolved' => '已解决',    // 已解决
        'foreshadow' => '伏笔',    // 未来可能发生
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
        return $booksDir . '/' . pathinfo($bookFile, PATHINFO_FILENAME) . '.plot.json';
    }
    
    /**
     * 保存情节数据
     */
    public function savePlotData(string $bookFile, array $events, array $timeline = []): bool
    {
        $path = $this->getStoragePath($bookFile);
        
        $data = [
            'book' => $bookFile,
            'events' => $events,
            'timeline' => $timeline,
            'unresolved' => $this->filterUnresolved($events),
            'createdAt' => time(),
            'version' => '1.0',
        ];
        
        // 生成事件的向量用于语义搜索
        $eventTexts = array_map(fn($e) => $this->eventToText($e), $events);
        if (!empty($eventTexts)) {
            $embeddings = $this->embedder->embedBatch($eventTexts);
            $data['embeddings'] = $embeddings;
        }
        
        // 保存到 Redis
        $redis = CacheService::getRedis();
        if ($redis) {
            $redis->setex("plot:{$bookFile}", 86400 * 7, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * 将事件转换为可搜索文本
     */
    private function eventToText(array $event): string
    {
        $parts = [];
        
        if (!empty($event['title'])) {
            $parts[] = $event['title'];
        }
        if (!empty($event['description'])) {
            $parts[] = $event['description'];
        }
        if (!empty($event['characters'])) {
            $parts[] = "相关人物：" . implode('、', $event['characters']);
        }
        if (!empty($event['location'])) {
            $parts[] = "地点：{$event['location']}";
        }
        if (!empty($event['consequences'])) {
            $parts[] = "后果：" . implode('；', $event['consequences']);
        }
        
        return implode("\n", $parts);
    }
    
    /**
     * 过滤出未解决的事件
     */
    private function filterUnresolved(array $events): array
    {
        return array_values(array_filter($events, function($e) {
            $status = $e['status'] ?? 'ongoing';
            return $status === 'ongoing' || $status === 'foreshadow';
        }));
    }
    
    /**
     * 加载情节数据
     */
    public function loadPlotData(string $bookFile): ?array
    {
        // 优先从 Redis 读取
        $redis = CacheService::getRedis();
        if ($redis) {
            $cached = $redis->get("plot:{$bookFile}");
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
            $redis->setex("plot:{$bookFile}", 86400 * 7, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        return $data;
    }
    
    /**
     * 获取所有事件
     */
    public function getAllEvents(string $bookFile): array
    {
        $data = $this->loadPlotData($bookFile);
        return $data['events'] ?? [];
    }
    
    /**
     * 获取未解决的事件（伏笔/悬念）
     */
    public function getUnresolvedEvents(string $bookFile): array
    {
        $data = $this->loadPlotData($bookFile);
        return $data['unresolved'] ?? [];
    }
    
    /**
     * 获取时间线
     */
    public function getTimeline(string $bookFile): array
    {
        $data = $this->loadPlotData($bookFile);
        return $data['timeline'] ?? [];
    }
    
    /**
     * 根据续写内容搜索相关事件
     */
    public function searchRelevantEvents(string $bookFile, string $query, int $topK = 5): array
    {
        $data = $this->loadPlotData($bookFile);
        if (!$data || empty($data['events'])) {
            return [];
        }
        
        $events = $data['events'];
        $embeddings = $data['embeddings'] ?? [];
        
        // 如果没有向量，使用关键词匹配
        if (empty($embeddings)) {
            return $this->keywordSearchEvents($events, $query, $topK);
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
            if (isset($events[$i]) && $score > 0.3) { // 相似度阈值
                $results[] = [
                    'event' => $events[$i],
                    'score' => $score,
                ];
                $count++;
            }
        }
        
        return $results;
    }
    
    /**
     * 搜索与特定角色相关的事件
     */
    public function searchEventsByCharacter(string $bookFile, string $characterName): array
    {
        $events = $this->getAllEvents($bookFile);
        
        return array_values(array_filter($events, function($e) use ($characterName) {
            $characters = $e['characters'] ?? [];
            foreach ($characters as $char) {
                if (mb_stripos($char, $characterName) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }
    
    /**
     * 关键词搜索事件
     */
    private function keywordSearchEvents(array $events, string $query, int $topK): array
    {
        $keywords = $this->extractKeywords($query);
        $scores = [];
        
        foreach ($events as $i => $event) {
            $text = $this->eventToText($event);
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
                    'event' => $events[$i],
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
     * 生成情节摘要（用于注入提示词）
     */
    public function generatePlotSummary(array $events, bool $includeUnresolved = true): string
    {
        if (empty($events)) {
            return '';
        }
        
        $summary = "## 相关情节\n\n";
        
        // 按类型分组
        $grouped = [];
        foreach ($events as $item) {
            $event = $item['event'] ?? $item;
            $type = $event['type'] ?? 'plot';
            $grouped[$type][] = $event;
        }
        
        // 输出分组后的事件
        foreach ($grouped as $type => $typeEvents) {
            $typeName = self::EVENT_TYPES[$type] ?? $type;
            $summary .= "### {$typeName}\n\n";
            
            foreach ($typeEvents as $event) {
                $title = $event['title'] ?? '未命名事件';
                $status = $event['status'] ?? 'ongoing';
                $statusName = self::STATUS[$status] ?? $status;
                
                $summary .= "- **{$title}**";
                if ($status !== 'resolved') {
                    $summary .= " [{$statusName}]";
                }
                $summary .= "\n";
                
                if (!empty($event['description'])) {
                    $summary .= "  {$event['description']}\n";
                }
                
                if (!empty($event['characters'])) {
                    $summary .= "  相关人物：" . implode('、', $event['characters']) . "\n";
                }
                
                if (!empty($event['consequences']) && $includeUnresolved) {
                    $summary .= "  后果/影响：" . implode('；', $event['consequences']) . "\n";
                }
                
                $summary .= "\n";
            }
        }
        
        return $summary;
    }
    
    /**
     * 生成未解决事件提示（用于续写时参考）
     */
    public function generateUnresolvedPrompt(string $bookFile): string
    {
        $unresolved = $this->getUnresolvedEvents($bookFile);
        
        if (empty($unresolved)) {
            return '';
        }
        
        $prompt = "## ⚠️ 未解决的情节\n\n";
        $prompt .= "以下是原作中尚未解决的情节/伏笔，续写时请注意：\n\n";
        
        foreach ($unresolved as $event) {
            $title = $event['title'] ?? '未知';
            $status = $event['status'] ?? 'ongoing';
            
            $prompt .= "- **{$title}**";
            if ($status === 'foreshadow') {
                $prompt .= " [伏笔]";
            }
            $prompt .= "\n";
            
            if (!empty($event['description'])) {
                $prompt .= "  {$event['description']}\n";
            }
        }
        
        $prompt .= "\n**提示**：续写时可以选择解决这些悬念，或继续保持悬念。\n";
        
        return $prompt;
    }
    
    /**
     * 添加新事件（用于追踪续写产生的新情节）
     */
    public function addEvent(string $bookFile, array $event): bool
    {
        $data = $this->loadPlotData($bookFile);
        if (!$data) {
            $data = [
                'book' => $bookFile,
                'events' => [],
                'timeline' => [],
                'unresolved' => [],
                'createdAt' => time(),
                'version' => '1.0',
            ];
        }
        
        // 添加事件 ID 和时间戳
        $event['id'] = $event['id'] ?? uniqid('event_');
        $event['addedAt'] = time();
        $event['source'] = $event['source'] ?? 'continuation'; // 标记为续写产生
        
        $data['events'][] = $event;
        
        // 更新未解决事件列表
        $data['unresolved'] = $this->filterUnresolved($data['events']);
        
        // 生成新事件的向量
        $eventText = $this->eventToText($event);
        $newEmbedding = $this->embedder->embedQuery($eventText);
        $data['embeddings'][] = $newEmbedding;
        
        // 保存
        $redis = CacheService::getRedis();
        if ($redis) {
            $redis->setex("plot:{$bookFile}", 86400 * 7, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        $path = $this->getStoragePath($bookFile);
        return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * 更新事件状态
     */
    public function updateEventStatus(string $bookFile, string $eventId, string $status): bool
    {
        $data = $this->loadPlotData($bookFile);
        if (!$data) {
            return false;
        }
        
        foreach ($data['events'] as &$event) {
            if (($event['id'] ?? '') === $eventId) {
                $event['status'] = $status;
                $event['updatedAt'] = time();
                break;
            }
        }
        
        // 更新未解决事件列表
        $data['unresolved'] = $this->filterUnresolved($data['events']);
        
        // 保存
        $redis = CacheService::getRedis();
        if ($redis) {
            $redis->setex("plot:{$bookFile}", 86400 * 7, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        $path = $this->getStoragePath($bookFile);
        return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * 检查是否有情节数据
     */
    public function hasPlotData(string $bookFile): bool
    {
        $redis = CacheService::getRedis();
        if ($redis && $redis->exists("plot:{$bookFile}")) {
            return true;
        }
        
        return file_exists($this->getStoragePath($bookFile));
    }
}
