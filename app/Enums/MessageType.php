<?php

declare(strict_types=1);

namespace App\Enums;

/** Chat message kinds (PRD §7.2). SYSTEM = written by the platform ("You are now connected"). */
enum MessageType: string
{
    case Text = 'TEXT';
    case Image = 'IMAGE';
    case System = 'SYSTEM';

    public function label(): string
    {
        return match ($this) {
            self::Text => __('Text'),
            self::Image => __('Image'),
            self::System => __('System'),
        };
    }
}
