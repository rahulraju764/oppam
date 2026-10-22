<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the GST on an invoice is split (decisions 2026-10-21): CGST + SGST when the place of supply
 * (the member's state on record) is the seller's state, IGST otherwise. A member with no Indian
 * state on record is billed as intra-state (place of supply = the supplier's location).
 */
enum GstSplit: string
{
    case IntraState = 'INTRA_STATE';
    case InterState = 'INTER_STATE';

    public function label(): string
    {
        return match ($this) {
            self::IntraState => __('CGST + SGST'),
            self::InterState => __('IGST'),
        };
    }
}
