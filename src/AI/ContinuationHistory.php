<?php
/**
 * 续写历史追踪系统
 * 
 * 记录和管理续写的历史内容，确保多次续写之间的连贯性
 */

namespace SmartBook\AI;

use SmartBook\Cache\CacheService;

class ContinuationHistory
{
    private string $apiKey;
    
    // 配置
    private array $config = [
        'max_history' => 10,        // 最多保存的续写历史条数
        'summary_length' => 500,    // 每条历史的摘要长度
    ];
    
    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }
    
    /**
     * 获取存储路径
     */
    private function getStoragePath(string $bookFile): string
    {
        return BOOKS_DIR . '/' . pathinfo($bookFile, PATHINFO_FILENAME) . '.history.json';
    }
    
    /**
     * 保存新的续写内容
     * 
     * @param string $bookFile 书籍文件名
     * @param string $prompt 用户的续写要求
     * @param string $content 续写生成的内容
     * @param array $metadata 额外信息（模型、时间等）
     * @return bool
     */
    public function saveContinuation(
        string $bookFile, 
        string $prompt, 
        string $content, 
        array $metadata = []
    ): bool {
        $data = $this->loadHistory($bookFile);
        if (!$data) {
            $data = [
                'book' => $bookFile,
                'continuations' => [],
                'total_words' => 0,
                'createdAt' => time(),
            ];
        }
        
        // 创建续写记录
        $continuation = [
            'id' => uniqid('cont_'),
            'prompt' => $prompt,
            'content' => $content,
            'summary' => $this->generateSummary($content),
            'wordCount' => mb_strlen($content),
            'timestamp' => time(),
            'metadata' => array_merge([
                'model' => 'unknown',
            ], $metadata),
        ];
        
        // 添加到历史
        $data['continuations'][] = $continuation;
        $data['total_words'] += $continuation['wordCount'];
        $data['lastUpdated'] = time();
        
        // 保持最大历史条数
        if (count($data['continuations']) > $this->config['max_history']) {
            // 移除最旧的记录
            $removed = array_shift($data['continuations']);
            $data['total_words'] -= $removed['wordCount'];
        }
        
        // 保存到 Redis
        $redis = CacheService::getRedis();
        if ($redis) {
            $redis->setex(
                "continuations:{$bookFile}", 
                86400 * 30, // 30天
                json_encode($data, JSON_UNESCAPED_UNICODE)
            );
        }
        
        // 保存到文件
        $path = $this->getStoragePath($bookFile);
        return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * 生成内容摘要
     */
    private function generateSummary(string $content): string
    {
        $length = $this->config['summary_length'];
        
        if (mb_strlen($content) <= $length) {
            return $content;
        }
        
        // 截取到最近的句子结尾
        $summary = mb_substr($content, 0, $length);
        if (preg_match('/^(.+[。！？.!?])/su', $summary, $matches)) {
            return $matches[1];
        }
        
        return $summary . '...';
    }
    
    /**
     * 加载续写历史
     */
    public function loadHistory(string $bookFile): ?array
    {
        // 优先从 Redis 读取
        $redis = CacheService::getRedis();
        if ($redis) {
            $cached = $redis->get("continuations:{$bookFile}");
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
            $redis->setex("continuations:{$bookFile}", 86400 * 30, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        return $data;
    }
    
    /**
     * 获取所有续写历史
     */
    public function getContinuations(string $bookFile): array
    {
        $data = $this->loadHistory($bookFile);
        return $data['continuations'] ?? [];
    }
    
    /**
     * 获取最近的续写
     * 
     * @param string $bookFile 书籍文件名
     * @param int $count 获取条数
     * @return array
     */
    public function getRecentContinuations(string $bookFile, int $count = 3): array
    {
        $continuations = $this->getContinuations($bookFile);
        
        // 返回最近的 n 条
        return array_slice(array_reverse($continuations), 0, $count);
    }
    
    /**
     * 获取最后一次续写
     */
    public function getLastContinuation(string $bookFile): ?array
    {
        $continuations = $this->getContinuations($bookFile);
        
        if (empty($continuations)) {
            return null;
        }
        
        return end($continuations);
    }
    
    /**
     * 生成续写上下文（用于注入提示词）
     * 
     * @param string $bookFile 书籍文件名
     * @return string
     */
    public function generateContext(string $bookFile): string
    {
        $continuations = $this->getContinuations($bookFile);
        
        if (empty($continuations)) {
            return '';
        }
        
        $context = "## 之前的续写内容\n\n";
        $context .= "以下是你之前为这本书生成的续写内容，请确保新续写与之保持连贯：\n\n";
        
        // 从最近的开始添加所有续写历史（使用完整内容）
        foreach (array_reverse($continuations) as $i => $cont) {
            $entry = "### 续写 " . ($i + 1) . "\n";
            $entry .= "**用户要求**: {$cont['prompt']}\n\n";
            $entry .= "**续写内容**:\n{$cont['content']}\n\n";  // 使用完整内容而不是摘要
            
            $context .= $entry;
        }
        
        $context .= "**重要**：新续写必须紧接上述内容，保持情节和人物的连贯性。\n";
        
        return $context;
    }
    
    /**
     * 获取续写统计信息
     */
    public function getStats(string $bookFile): array
    {
        $data = $this->loadHistory($bookFile);
        
        if (!$data) {
            return [
                'count' => 0,
                'total_words' => 0,
                'first_date' => null,
                'last_date' => null,
            ];
        }
        
        $continuations = $data['continuations'] ?? [];
        
        return [
            'count' => count($continuations),
            'total_words' => $data['total_words'] ?? 0,
            'first_date' => !empty($continuations) 
                ? date('Y-m-d H:i:s', $continuations[0]['timestamp'] ?? 0) 
                : null,
            'last_date' => !empty($continuations) 
                ? date('Y-m-d H:i:s', end($continuations)['timestamp'] ?? 0) 
                : null,
        ];
    }
    
    /**
     * 清除续写历史
     */
    public function clearHistory(string $bookFile): bool
    {
        $redis = CacheService::getRedis();
        if ($redis) {
            $redis->del("continuations:{$bookFile}");
        }
        
        $path = $this->getStoragePath($bookFile);
        if (file_exists($path)) {
            return unlink($path);
        }
        
        return true;
    }
    
    /**
     * 删除特定续写
     */
    public function deleteContinuation(string $bookFile, string $continuationId): bool
    {
        $data = $this->loadHistory($bookFile);
        if (!$data) {
            return false;
        }
        
        $found = false;
        foreach ($data['continuations'] as $i => $cont) {
            if ($cont['id'] === $continuationId) {
                $data['total_words'] -= $cont['wordCount'];
                unset($data['continuations'][$i]);
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            return false;
        }
        
        // 重新索引数组
        $data['continuations'] = array_values($data['continuations']);
        
        // 保存
        $redis = CacheService::getRedis();
        if ($redis) {
            $redis->setex("continuations:{$bookFile}", 86400 * 30, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        $path = $this->getStoragePath($bookFile);
        return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * 合并所有续写内容（导出用）
     */
    public function mergeAllContent(string $bookFile): string
    {
        $continuations = $this->getContinuations($bookFile);
        
        if (empty($continuations)) {
            return '';
        }
        
        $merged = '';
        foreach ($continuations as $cont) {
            $merged .= $cont['content'] . "\n\n";
        }
        
        return trim($merged);
    }
    
    /**
     * 检查是否有续写历史
     */
    public function hasHistory(string $bookFile): bool
    {
        $redis = CacheService::getRedis();
        if ($redis && $redis->exists("continuations:{$bookFile}")) {
            return true;
        }
        
        return file_exists($this->getStoragePath($bookFile));
    }
}
