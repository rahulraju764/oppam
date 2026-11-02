<?php

declare(strict_types=1);

namespace App\Data\Content;

/** One question on /plans#faq. `answerHtml` was sanitised when it was saved. */
final readonly class FaqData
{
    public function __construct(
        public string $question,
        public string $answerHtml,
    ) {}
}
