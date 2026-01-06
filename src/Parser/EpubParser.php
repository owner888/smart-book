<?php
/**
 * EPUB 解析器
 */

namespace SmartBook\Parser;

class EpubParser
{
    public static function extractText(string $epubPath): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($epubPath) !== true) return '';
        
        $text = '';
        $htmlFiles = [];
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (preg_match('/\.(html?|xhtml)$/i', $filename)) $htmlFiles[] = $filename;
        }
        sort($htmlFiles);
        
        foreach ($htmlFiles as $filename) {
            $content = $zip->getFromName($filename);
            if ($content) {
                if (preg_match('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', $content, $matches)) {
                    $text .= "\n\n### " . strip_tags($matches[1]) . "\n\n";
                }
                $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
                $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);
                $content = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $content = trim(preg_replace('/\s+/', ' ', $content));
                
                if (!empty($content) && mb_strlen($content) > 50) $text .= $content . "\n\n";
            }
        }
        $zip->close();
        return $text;
    }
    
    public static function extractMetadata(string $epubPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($epubPath) !== true) return [];
        
        $metadata = ['title' => basename($epubPath, '.epub'), 'authors' => '', 'description' => ''];
        
        $opfContent = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (preg_match('/\.opf$/i', $filename)) { $opfContent = $zip->getFromName($filename); break; }
        }
        
        if ($opfContent) {
            if (preg_match('/<dc:title[^>]*>(.*?)<\/dc:title>/is', $opfContent, $m)) $metadata['title'] = trim(strip_tags($m[1]));
            if (preg_match('/<dc:creator[^>]*>(.*?)<\/dc:creator>/is', $opfContent, $m)) $metadata['authors'] = trim(strip_tags($m[1]));
            if (preg_match('/<dc:description[^>]*>(.*?)<\/dc:description>/is', $opfContent, $m)) $metadata['description'] = trim(strip_tags($m[1]));
            if (preg_match('/<dc:language[^>]*>(.*?)<\/dc:language>/is', $opfContent, $m)) $metadata['language'] = trim(strip_tags($m[1]));
            if (preg_match('/<dc:publisher[^>]*>(.*?)<\/dc:publisher>/is', $opfContent, $m)) $metadata['publisher'] = trim(strip_tags($m[1]));
        }
        $zip->close();
        return $metadata;
    }
    
    /**
     * 从 EPUB 文件提取目录 (Table of Contents)
     * 
     * @param string $epubPath EPUB 文件路径
     * @return array 目录数组，每项包含 title, href, level
     */
    public static function extractToc(string $epubPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($epubPath) !== true) {
            return [];
        }
        
        $toc = [];
        
        // 1. 首先尝试从 NCX 文件提取目录 (EPUB 2)
        $ncxContent = null;
        $ncxPath = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (preg_match('/\.ncx$/i', $filename)) {
                $ncxContent = $zip->getFromName($filename);
                $ncxPath = $filename;
                break;
            }
        }
        
        if ($ncxContent) {
            $toc = self::parseNcxToc($ncxContent);
        }
        
        // 2. 如果 NCX 没有内容，尝试从 NAV 文件提取 (EPUB 3)
        if (empty($toc)) {
            $navContent = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (preg_match('/nav\.(xhtml|html)$/i', $filename)) {
                    $navContent = $zip->getFromName($filename);
                    break;
                }
            }
            
            if ($navContent) {
                $toc = self::parseNavToc($navContent);
            }
        }
        
        // 3. 如果还是没有，从 OPF 的 spine 中提取
        if (empty($toc)) {
            $toc = self::extractTocFromSpine($zip);
        }
        
        $zip->close();
        return $toc;
    }
    
    /**
     * 解析 NCX 格式的目录 (EPUB 2)
     */
    private static function parseNcxToc(string $ncxContent): array
    {
        $toc = [];
        
        // 使用正则表达式提取 navPoint 元素
        preg_match_all('/<navPoint[^>]*>(.*?)<\/navPoint>/is', $ncxContent, $navPoints, PREG_SET_ORDER);
        
        foreach ($navPoints as $navPoint) {
            $content = $navPoint[1];
            
            // 提取标题
            if (preg_match('/<text>(.*?)<\/text>/is', $content, $textMatch)) {
                $title = trim(strip_tags(html_entity_decode($textMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                
                // 提取链接
                $href = '';
                if (preg_match('/<content[^>]*src=["\']([^"\']+)["\']/i', $content, $srcMatch)) {
                    $href = $srcMatch[1];
                }
                
                // 计算层级（通过 playOrder 或嵌套深度）
                $level = 1;
                if (preg_match('/playOrder=["\'](\d+)["\']/i', $navPoint[0], $orderMatch)) {
                    // 简单处理：第一级
                }
                
                if (!empty($title)) {
                    $toc[] = [
                        'title' => $title,
                        'href' => $href,
                        'level' => $level,
                    ];
                }
            }
        }
        
        return $toc;
    }
    
    /**
     * 解析 NAV 格式的目录 (EPUB 3)
     */
    private static function parseNavToc(string $navContent): array
    {
        $toc = [];
        
        // 查找 nav[epub:type="toc"] 或 nav#toc
        if (preg_match('/<nav[^>]*epub:type=["\']toc["\'][^>]*>(.*?)<\/nav>/is', $navContent, $navMatch)) {
            $navHtml = $navMatch[1];
        } elseif (preg_match('/<nav[^>]*id=["\']toc["\'][^>]*>(.*?)<\/nav>/is', $navContent, $navMatch)) {
            $navHtml = $navMatch[1];
        } else {
            // 尝试获取第一个 nav 标签
            if (preg_match('/<nav[^>]*>(.*?)<\/nav>/is', $navContent, $navMatch)) {
                $navHtml = $navMatch[1];
            } else {
                return $toc;
            }
        }
        
        // 提取所有链接
        preg_match_all('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $navHtml, $links, PREG_SET_ORDER);
        
        foreach ($links as $link) {
            $href = $link[1];
            $title = trim(strip_tags(html_entity_decode($link[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            
            if (!empty($title)) {
                $toc[] = [
                    'title' => $title,
                    'href' => $href,
                    'level' => 1,
                ];
            }
        }
        
        return $toc;
    }
    
    /**
     * 从 OPF 的 spine 中提取目录
     */
    private static function extractTocFromSpine(\ZipArchive $zip): array
    {
        $toc = [];
        
        // 找到 OPF 文件
        $opfContent = null;
        $opfDir = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (preg_match('/\.opf$/i', $filename)) {
                $opfContent = $zip->getFromName($filename);
                $opfDir = dirname($filename);
                if ($opfDir === '.') $opfDir = '';
                break;
            }
        }
        
        if (!$opfContent) {
            return $toc;
        }
        
        // 提取 manifest 中的项目
        $manifest = [];
        preg_match_all('/<item[^>]*id=["\']([^"\']+)["\'][^>]*href=["\']([^"\']+)["\'][^>]*>/i', $opfContent, $items, PREG_SET_ORDER);
        foreach ($items as $item) {
            $manifest[$item[1]] = $item[2];
        }
        
        // 提取 spine 顺序
        preg_match_all('/<itemref[^>]*idref=["\']([^"\']+)["\'][^>]*>/i', $opfContent, $spineItems);
        
        $index = 1;
        foreach ($spineItems[1] as $idref) {
            if (isset($manifest[$idref])) {
                $href = $manifest[$idref];
                $fullPath = $opfDir ? "{$opfDir}/{$href}" : $href;
                
                // 尝试从文件中提取标题
                $title = "Chapter {$index}";
                $fileContent = $zip->getFromName($fullPath);
                if ($fileContent) {
                    // 尝试从 <title> 标签获取
                    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $fileContent, $titleMatch)) {
                        $extractedTitle = trim(strip_tags(html_entity_decode($titleMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                        if (!empty($extractedTitle)) {
                            $title = $extractedTitle;
                        }
                    }
                    // 或从第一个 h1-h3 获取
                    elseif (preg_match('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', $fileContent, $hMatch)) {
                        $extractedTitle = trim(strip_tags(html_entity_decode($hMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                        if (!empty($extractedTitle)) {
                            $title = $extractedTitle;
                        }
                    }
                }
                
                $toc[] = [
                    'title' => $title,
                    'href' => $href,
                    'level' => 1,
                ];
                $index++;
            }
        }
        
        return $toc;
    }
}
