<?php
/**
 * Token 统计和金钱消耗计算
 * 基于 calibre 的 Python 实现移植
 */

namespace SmartBook\AI;

class TokenCounter
{
    // Gemini 模型定价 (USD per token)
    // 参考: https://ai.google.dev/gemini-api/docs/pricing
    const PRICING = [
        'gemini-2.5-pro' => [
            'input' => ['above' => 2.5/1e6, 'below' => 1.25/1e6, 'threshold' => 200000],
            'output' => ['above' => 15/1e6, 'below' => 10/1e6, 'threshold' => 200000],
            'caching' => ['above' => 0.25/1e6, 'below' => 0.125/1e6, 'threshold' => 200000],
        ],
        'gemini-2.5-flash' => [
            'input' => ['above' => 0.3/1e6, 'below' => 0.3/1e6, 'threshold' => 0],
            'output' => ['above' => 2.5/1e6, 'below' => 2.5/1e6, 'threshold' => 0],
            'caching' => ['above' => 0.03/1e6, 'below' => 0.03/1e6, 'threshold' => 0],
        ],
        'gemini-2.5-flash-lite' => [
            'input' => ['above' => 0.1/1e6, 'below' => 0.1/1e6, 'threshold' => 0],
            'output' => ['above' => 0.4/1e6, 'below' => 0.4/1e6, 'threshold' => 0],
            'caching' => ['above' => 0.01/1e6, 'below' => 0.01/1e6, 'threshold' => 0],
        ],
        'gemini-2.0-flash' => [
            'input' => ['above' => 0, 'below' => 0, 'threshold' => 0],  // Free tier
            'output' => ['above' => 0, 'below' => 0, 'threshold' => 0],
            'caching' => ['above' => 0, 'below' => 0, 'threshold' => 0],
        ],
    ];
    
    /**
     * 计算单个价格
     */
    private static function getPriceCost(array $price, int $numTokens): float
    {
        $rate = ($numTokens <= $price['threshold']) ? $price['below'] : $price['above'];
        return $rate * $numTokens;
    }
    
    /**
     * 从 usageMetadata 计算成本
     * @param array $usageMetadata Gemini API 返回的 usageMetadata
     * @param string $modelId 模型 ID
     * @return array ['cost' => float, 'currency' => string, 'tokens' => array]
     */
    public static function calculateCost(array $usageMetadata, string $modelId = 'gemini-2.5-flash'): array
    {
        // 提取 token 数量
        $promptTokens = $usageMetadata['promptTokenCount'] ?? 0;
        $cachedTokens = $usageMetadata['cachedContentTokenCount'] ?? 0;
        $totalTokens = $usageMetadata['totalTokenCount'] ?? 0;
        $candidatesTokens = $usageMetadata['candidatesTokenCount'] ?? ($totalTokens - $promptTokens);
        
        // 实际输入 token（减去缓存）
        $inputTokens = $promptTokens - $cachedTokens;
        
        // 获取定价
        $pricing = self::PRICING[$modelId] ?? self::PRICING['gemini-2.5-flash'];
        
        // 计算成本
        $inputCost = self::getPriceCost($pricing['input'], $inputTokens);
        $outputCost = self::getPriceCost($pricing['output'], $candidatesTokens);
        $cachingCost = self::getPriceCost($pricing['caching'], $cachedTokens);
        
        $totalCost = $inputCost + $outputCost + $cachingCost;
        
        return [
            'cost' => $totalCost,
            'currency' => 'USD',
            'tokens' => [
                'input' => $inputTokens,
                'output' => $candidatesTokens,
                'cached' => $cachedTokens,
                'total' => $totalTokens,
            ],
            'breakdown' => [
                'input_cost' => $inputCost,
                'output_cost' => $outputCost,
                'caching_cost' => $cachingCost,
            ],
        ];
    }
    
    /**
     * 格式化成本显示
     */
    public static function formatCost(float $cost, string $currency = 'USD'): string
    {
        if ($cost == 0) {
            return 'Free';
        }
        
        // 保留6位小数，去除末尾零
        $formatted = rtrim(rtrim(sprintf('%.6f', $cost), '0'), '.');
        return $formatted . ' ' . $currency;
    }
    
    /**
     * 格式化 token 数量显示
     */
    public static function formatTokens(array $tokens): string
    {
        $total = $tokens['total'] ?? ($tokens['input'] + $tokens['output']);
        
        if ($total >= 1000000) {
            return round($total / 1000000, 2) . 'M';
        } elseif ($total >= 1000) {
            return round($total / 1000, 1) . 'K';
        }
        return (string) $total;
    }
    
    /**
     * 获取模型的定价信息
     */
    public static function getModelPricing(string $modelId): array
    {
        $pricing = self::PRICING[$modelId] ?? self::PRICING['gemini-2.5-flash'];
        
        // 转换为每百万 token 的价格（更易读）
        return [
            'input_per_million' => $pricing['input']['above'] * 1e6,
            'output_per_million' => $pricing['output']['above'] * 1e6,
            'is_free' => $pricing['input']['above'] == 0 && $pricing['output']['above'] == 0,
        ];
    }
}
