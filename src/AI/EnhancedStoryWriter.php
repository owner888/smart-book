<?php
/**
 * 增强版小说续写服务
 * 
 * 使用 Context Cache + Few-shot Prompting + 语义搜索
 * 通过智能提示工程达到类似 Fine-tuning 的效果
 */

namespace SmartBook\AI;

use SmartBook\Cache\CacheService;
use SmartBook\RAG\VectorStore;
use SmartBook\RAG\EmbeddingClient;

class EnhancedStoryWriter
{
    private AsyncGeminiClient $gemini;
    private GeminiContextCache $cache;
    private EmbeddingClient $embedder;
    private string $apiKey;
    private string $model;
    
    // 续写配置
    private array $config = [
        'style_samples' => 5,       // 风格样本数量
        'sample_length' => 500,     // 每个样本的字符长度
        'character_limit' => 10,    // 最多分析的人物数量
        'use_semantic_search' => true, // 是否使用语义搜索选择样本
    ];
    
    public function __construct(
        string $apiKey,
        string $model = 'gemini-2.5-flash'
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->gemini = new AsyncGeminiClient($apiKey, $model);
        $this->cache = new GeminiContextCache($apiKey, $model);
        $this->embedder = new EmbeddingClient($apiKey);
    }
    
    /**
     * 基于用户输入的语义搜索选择风格样本
     * 
     * @param string $userPrompt 用户的续写要求
     * @param string $bookFile 书籍文件名
     * @param int $topK 返回样本数量
     * @return array 语义相关的风格样本
     */
    public function searchStyleSamples(string $userPrompt, string $bookFile, int $topK = 5): array
    {
        // 获取书籍索引缓存路径
        $booksDir = dirname(__DIR__, 2) . '/books';
        $indexPath = $booksDir . '/' . pathinfo($bookFile, PATHINFO_FILENAME) . '.index.json';
        
        if (!file_exists($indexPath)) {
            // 如果没有索引，返回空数组（回退到随机样本）
            return [];
        }
        
        // 加载向量存储
        $vectorStore = new VectorStore($indexPath);
        
        if ($vectorStore->isEmpty()) {
            return [];
        }
        
        // 生成用户输入的向量表示
        $queryEmbedding = $this->embedder->embedQuery($userPrompt);
        
        // 执行混合搜索
        $results = $vectorStore->hybridSearch($userPrompt, $queryEmbedding, $topK, 0.4);
        
        // 提取样本文本
        $samples = [];
        foreach ($results as $result) {
            $text = $result['chunk']['text'] ?? '';
            if (mb_strlen($text) > 100) {
                $samples[] = $text;
            }
        }
        
        return $samples;
    }
    
    /**
     * 为书籍准备续写环境
     * 
     * 1. 创建 Context Cache
     * 2. 提取风格样本
     * 3. 分析人物卡片
     * 
     * @return array 准备结果
     */
    public function prepareForBook(string $bookFile, string $bookContent): array
    {
        $results = [
            'book' => $bookFile,
            'steps' => [],
        ];
        
        // 1. 创建 Context Cache
        $cacheResult = $this->cache->createForBook($bookFile, $bookContent, 7200); // 2小时
        $results['steps']['cache'] = $cacheResult;
        
        if (!$cacheResult['success']) {
            return ['success' => false, 'error' => '创建缓存失败: ' . ($cacheResult['error'] ?? 'Unknown')];
        }
        
        $results['cacheName'] = $cacheResult['name'];
        
        // 2. 提取风格样本
        $styleSamples = $this->extractStyleSamples($bookContent);
        $results['steps']['samples'] = [
            'count' => count($styleSamples),
            'samples' => array_map(fn($s) => mb_substr($s, 0, 100) . '...', $styleSamples),
        ];
        
        // 3. 保存分析结果到 Redis
        $analysisKey = "story:analysis:{$bookFile}";
        $analysisData = [
            'cacheName' => $cacheResult['name'],
            'styleSamples' => $styleSamples,
            'createdAt' => time(),
            'bookFile' => $bookFile,
        ];
        
        $redis = CacheService::getRedis();
        if ($redis) {
            $redis->setex($analysisKey, 7200, json_encode($analysisData, JSON_UNESCAPED_UNICODE));
        }
        
        $results['success'] = true;
        $results['message'] = '续写环境准备完成';
        
        return $results;
    }
    
    /**
     * 从书籍中提取风格样本
     */
    private function extractStyleSamples(string $content): array
    {
        $samples = [];
        $contentLength = mb_strlen($content);
        $sampleCount = $this->config['style_samples'];
        $sampleLength = $this->config['sample_length'];
        
        // 在书籍中均匀分布地提取样本
        for ($i = 0; $i < $sampleCount; $i++) {
            // 计算起始位置（均匀分布）
            $startPos = intval(($contentLength / ($sampleCount + 1)) * ($i + 1));
            
            // 找到段落开始（寻找换行符后的位置）
            $adjustedStart = mb_strpos($content, "\n", $startPos);
            if ($adjustedStart === false || $adjustedStart > $startPos + 200) {
                $adjustedStart = $startPos;
            } else {
                $adjustedStart++; // 跳过换行符
            }
            
            // 提取样本
            $sample = mb_substr($content, $adjustedStart, $sampleLength);
            
            // 找到句子结尾（句号、问号、感叹号）
            if (preg_match('/^(.+[。！？.!?])/su', $sample, $matches)) {
                $sample = $matches[1];
            }
            
            // 清理并添加
            $sample = trim($sample);
            if (mb_strlen($sample) > 100) { // 确保样本足够长
                $samples[] = $sample;
            }
        }
        
        return $samples;
    }
    
    /**
     * 执行增强版续写
     * 
     * @param string $bookFile 书籍文件名
     * @param string $prompt 用户的续写要求
     * @param callable $onChunk 流式回调
     * @param callable $onComplete 完成回调
     * @param array $options 选项
     *   - style_samples: 自定义风格样本（如果提供则不使用语义搜索）
     *   - use_semantic_search: 是否使用语义搜索（默认 true）
     *   - sample_count: 样本数量（默认 5）
     */
    public function continueStory(
        string $bookFile,
        string $prompt,
        callable $onChunk,
        callable $onComplete,
        ?callable $onError = null,
        array $options = []
    ): void {
        // 直接从 Gemini API 查询书籍缓存
        $bookCache = $this->cache->getBookCache($bookFile);
        if (!$bookCache) {
            $onError ? $onError('请先为书籍准备续写环境（Context Cache）') : null;
            return;
        }
        
        // 获取风格样本
        $styleSamples = $options['style_samples'] ?? [];
        $useSemanticSearch = $options['use_semantic_search'] ?? $this->config['use_semantic_search'];
        $sampleCount = $options['sample_count'] ?? $this->config['style_samples'];
        
        // 如果没有提供样本且启用了语义搜索，则基于用户输入搜索相关段落
        if (empty($styleSamples) && $useSemanticSearch) {
            $styleSamples = $this->searchStyleSamples($prompt, $bookFile, $sampleCount);
            
            // 如果语义搜索无结果，从 Redis 获取预存样本
            if (empty($styleSamples)) {
                $redis = CacheService::getRedis();
                if ($redis) {
                    $analysisKey = "story:analysis:{$bookFile}";
                    $analysisData = $redis->get($analysisKey);
                    if ($analysisData) {
                        $data = json_decode($analysisData, true);
                        $styleSamples = $data['styleSamples'] ?? [];
                    }
                }
            }
        }
        
        // 构建分析数据
        $analysisData = [
            'cacheName' => $bookCache['name'],
            'styleSamples' => $styleSamples,
            'bookFile' => $bookFile,
        ];
        
        // 添加搜索方法标记（用于调试）
        $searchMethod = empty($styleSamples) ? 'none' : 
            ($useSemanticSearch ? 'semantic' : 'cached');
        
        // 构建增强版系统提示词
        $systemPrompt = $this->buildEnhancedSystemPrompt($analysisData, array_merge($options, [
            'search_method' => $searchMethod,
        ]));
        
        // 构建消息
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $prompt],
        ];
        
        // 调用 AI（使用 Context Cache）
        $this->gemini->chatStreamAsync(
            $messages,
            $onChunk,
            $onComplete,
            $onError,
            [
                'cachedContent' => $analysisData['cacheName'],
                'includeThoughts' => false,
            ]
        );
    }
    
    /**
     * 构建增强版系统提示词
     */
    private function buildEnhancedSystemPrompt(array $analysisData, array $options = []): string
    {
        $styleSamples = $analysisData['styleSamples'] ?? [];
        $bookFile = $analysisData['bookFile'] ?? '未知书籍';
        $tokenCount = $options['token_count'] ?? 0;
        
        // 从配置文件读取系统提示词模板
        $prompts = $GLOBALS['config']['prompts'] ?? [];
        $continuePrompts = $prompts['continue'] ?? [];
        
        // 基础指令（使用配置文件中的 system）
        $basePrompt = $continuePrompts['system'] ?? '你是一位专业的小说续写大师。你的任务是续写{title}。';
        
        // 替换变量
        $prompt = str_replace(
            ['{title}', '{tokens}'],
            ['《' . $bookFile . '》', number_format($tokenCount)],
            $basePrompt
        );
        
        // 如果有风格样本，添加参考部分
        if (!empty($styleSamples)) {
            $prompt .= "\n\n## 原作风格参考\n\n以下是从原作中提取的几段示例，请仔细学习其文风特点：\n";
            
            // 添加风格样本
            foreach ($styleSamples as $i => $sample) {
                $num = $i + 1;
                $prompt .= "\n### 样本 {$num}\n```\n{$sample}\n```\n";
            }
        }
        
        // 添加写作指南
        $prompt .= <<<PROMPT

## 写作指南

1. **开篇** - 从原作结尾处自然过渡，不要重复原作内容
2. **视角** - 保持与原作相同的叙事视角（第一人称/第三人称）
3. **节奏** - 保持原作的叙事节奏，不要突然加快或放慢
4. **对话** - 人物对话要符合其身份和性格，用词要与原作一致
5. **描写** - 场景、动作、心理描写的详略程度要与原作匹配
6. **禁忌** - 不要使用现代网络用语，不要打破原作的世界观

## 输出格式

直接输出续写内容，不需要标题或章节号（除非用户要求）。
使用纯文本格式，每段之间空一行。
PROMPT;

        // 添加自定义要求
        if (!empty($options['custom_instructions'])) {
            $prompt .= "\n\n## 用户特殊要求\n\n" . $options['custom_instructions'];
        }
        
        return $prompt;
    }
    
    /**
     * 分析书籍人物（可选功能）
     */
    public function analyzeCharacters(
        string $bookFile,
        callable $onChunk,
        callable $onComplete,
        ?callable $onError = null
    ): void {
        // 获取缓存名称
        $bookCache = $this->cache->getBookCache($bookFile);
        if (!$bookCache) {
            $onError ? $onError('请先为书籍创建缓存') : null;
            return;
        }
        
        $messages = [
            ['role' => 'system', 'content' => '你是一位文学分析专家。'],
            ['role' => 'user', 'content' => <<<PROMPT
请分析这本书的主要人物，为每个人物创建一张"人物卡片"，包含：

1. **姓名**
2. **身份/职业**
3. **性格特点**（3-5个关键词）
4. **口头禅或说话风格**
5. **与其他主要人物的关系**
6. **人物弧光**（在故事中的成长变化）

请分析最多 10 个主要人物，按重要性排序。
输出格式使用 Markdown。
PROMPT],
        ];
        
        $this->gemini->chatStreamAsync(
            $messages,
            $onChunk,
            $onComplete,
            $onError,
            [
                'cachedContent' => $bookCache['name'],
                'includeThoughts' => false,
            ]
        );
    }
    
    /**
     * 获取书籍的续写状态
     */
    public function getWriterStatus(string $bookFile): array
    {
        $redis = CacheService::getRedis();
        if (!$redis) {
            return ['ready' => false, 'error' => 'Redis 未连接'];
        }
        
        // 检查分析数据
        $analysisKey = "story:analysis:{$bookFile}";
        $analysisData = $redis->get($analysisKey);
        
        if (!$analysisData) {
            return [
                'ready' => false,
                'message' => '需要先准备续写环境',
            ];
        }
        
        $data = json_decode($analysisData, true);
        
        // 检查缓存是否有效
        $cacheInfo = $this->cache->get($data['cacheName']);
        if (!$cacheInfo) {
            return [
                'ready' => false,
                'message' => '缓存已过期，需要重新准备',
                'expired' => true,
            ];
        }
        
        return [
            'ready' => true,
            'cacheName' => $data['cacheName'],
            'samplesCount' => count($data['styleSamples'] ?? []),
            'createdAt' => date('Y-m-d H:i:s', $data['createdAt']),
            'expireTime' => $cacheInfo['expireTime'] ?? null,
        ];
    }
}
