<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why money goes back (A06 "refunds by category"; the owner's six categories, decisions
 * 2026-10-23). Each refund picks one; reports and the GST export carry it.
 */
enum RefundCategory: string
{
    case DuplicatePayment = 'DUPLICATE_PAYMENT';
    case NotActivated = 'NOT_ACTIVATED';
    case AccidentalPurchase = 'ACCIDENTAL_PURCHASE';
    case ServiceComplaint = 'SERVICE_COMPLAINT';
    case AccountClosed = 'ACCOUNT_CLOSED';
    case Goodwill = 'GOODWILL';

    public function label(): string
    {
        return match ($this) {
            self::DuplicatePayment => __('Duplicate / extra payment'),
            self::NotActivated => __('Paid but not activated (technical)'),
            self::AccidentalPurchase => __('Accidental purchase'),
            self::ServiceComplaint => __('Service complaint'),
            self::AccountClosed => __('Account closed by Oppam (ban / fraud)'),
            self::Goodwill => __('Goodwill'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
