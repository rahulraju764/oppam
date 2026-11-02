<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A question on /plans#faq (M12, A08).
 *
 * @property string $question
 * @property string $answer sanitised HTML
 */
final class FaqItem extends EditorialContent
{
    /** @use HasFactory<\Database\Factories\FaqItemFactory> */
    use HasFactory;

    public function contentType(): ContentType
    {
        return ContentType::Faq;
    }

    public function title(): string
    {
        return $this->question;
    }
}
