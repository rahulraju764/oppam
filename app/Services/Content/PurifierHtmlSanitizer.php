<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Contracts\HtmlSanitizer;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * HTMLPurifier with a strict allow-list: paragraphs, emphasis, lists and links. No scripts, styles,
 * images, frames, classes or event handlers; links are http(s) or mailto only, and nofollow.
 */
final class PurifierHtmlSanitizer implements HtmlSanitizer
{
    private ?HTMLPurifier $purifier = null;

    public function clean(string $html): string
    {
        return trim($this->purifier()->purify($html));
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('Cache.DefinitionImpl', null);
            $config->set('HTML.Allowed', 'p,br,strong,em,b,i,ul,ol,li,a[href]');
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('HTML.Nofollow', true);
            $config->set('AutoFormat.RemoveEmpty', true);
            $this->purifier = new HTMLPurifier($config);
        }

        return $this->purifier;
    }
}
