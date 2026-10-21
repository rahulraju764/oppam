<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a chat safety rule does when it matches (PRD A13 rules editor "per-rule action (mask / flag /
 * block)"). MASK hides the matched text from the recipient and flags the message; FLAG keeps the text
 * and puts the message in the A13 queue; BLOCK refuses the send — nothing is stored and the sender is
 * told why (decisions 2026-10-18); OFF ignores the rule.
 */
enum SafetyAction: string
{
    case Mask = 'MASK';
    case Flag = 'FLAG';
    case Block = 'BLOCK';
    case Off = 'OFF';

    public function label(): string
    {
        return match ($this) {
            self::Mask => __('Hide and flag'),
            self::Flag => __('Flag only'),
            self::Block => __('Block the message'),
            self::Off => __('Off'),
        };
    }

    /** <x-ui.badge> variant (the label always says the action too). */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Mask => 'verified',
            self::Flag, self::Block => 'warning',
            self::Off => 'muted',
        };
    }
}
