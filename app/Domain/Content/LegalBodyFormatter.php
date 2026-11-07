<?php

declare(strict_types=1);

namespace App\Domain\Content;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * Gives sanitised legal text the template's document classes (terms.php / privacy.php): clause
 * headings `privacy-shd` with an anchor id, paragraphs and lists `p-main`. Runs after the
 * sanitiser, so it only ever adds fixed class names and slug ids to safe markup.
 */
final class LegalBodyFormatter
{
    public function format(string $cleanHtml): string
    {
        if (trim($cleanHtml) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="legal-root">'.$cleanHtml.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $used = [];

        foreach ($xpath->query('//h2|//h3') ?: [] as $heading) {
            if (! $heading instanceof DOMElement) {
                continue;
            }
            $heading->setAttribute('class', $heading->tagName === 'h2' ? 't-head' : 'privacy-shd');
            $id = $heading->getAttribute('id') !== '' ? Str::slug($heading->getAttribute('id')) : Str::slug($heading->textContent);
            $id = $id === '' ? 'clause' : $id;
            $unique = $id;
            for ($n = 2; isset($used[$unique]); $n++) {
                $unique = $id.'-'.$n;
            }
            $used[$unique] = true;
            $heading->setAttribute('id', $unique);
        }

        foreach ($xpath->query('//p|//ul|//ol') ?: [] as $block) {
            if ($block instanceof DOMElement) {
                $intro = $block->tagName === 'p' && $block->getAttribute('class') === 'p-intro';
                $block->setAttribute('class', match (true) {
                    $intro => 'p-intro',
                    $block->tagName === 'p' => 'p-main',
                    default => 'p-main legal-list',
                });
            }
        }

        $root = $dom->getElementById('legal-root');
        $html = '';
        foreach ($root !== null ? $root->childNodes : [] as $child) {
            $html .= $dom->saveHTML($child);
        }

        return trim($html);
    }

    /**
     * The plain text of each block, for comparing two versions.
     *
     * @return list<string>
     */
    public function blocks(string $html): array
    {
        $text = preg_replace('/<\/(p|h2|h3|li)>/i', "\n", $html) ?? $html;
        $lines = array_map(fn (string $line): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), explode("\n", $text));

        return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
    }
}
