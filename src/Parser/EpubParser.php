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
        }
        $zip->close();
        return $metadata;
    }
}
