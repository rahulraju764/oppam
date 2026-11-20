<?php

declare(strict_types=1);

namespace App\Enums;

/** The A09 report catalogue (P9.1a: growth, engagement, marketplace; P9.1b: revenue, trust & ops). */
enum ReportKey: string
{
    case Growth = 'growth';
    case Engagement = 'engagement';
    case Marketplace = 'marketplace';
    case Revenue = 'revenue';
    case Trust = 'trust';

    public function label(): string
    {
        return match ($this) {
            self::Growth => __('Growth'),
            self::Engagement => __('Engagement'),
            self::Marketplace => __('Marketplace health'),
            self::Revenue => __('Revenue'),
            self::Trust => __('Trust & operations'),
        };
    }

    public function question(): string
    {
        return match ($this) {
            self::Growth => __('Who is joining, from where, and how many finish their profile?'),
            self::Engagement => __('Are members active, liking, sending interests, chatting and searching?'),
            self::Marketplace => __('Is the mix of live profiles healthy — gender balance, photos, members nobody contacts?'),
            self::Revenue => __('What do plans earn, who upgrades, who leaves?'),
            self::Trust => __('Are verification, moderation, reports and support within their SLAs?'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Growth => 'fa-line-chart',
            self::Engagement => 'fa-heart',
            self::Marketplace => 'fa-balance-scale',
            self::Revenue => 'fa-inr',
            self::Trust => 'fa-shield',
        };
    }
}
