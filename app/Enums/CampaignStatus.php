<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A14 campaign lifecycle (P8.3b1):
 * DRAFT → (submit: the audience is frozen) → PENDING_APPROVAL (above the A15 approval threshold)
 * → SCHEDULED (a start time in the future) → SENDING → COMPLETED; CANCELLED from any open state.
 * COMPLETED and CANCELLED are final; a campaign is never sent twice.
 */
enum CampaignStatus: string
{
    case Draft = 'DRAFT';
    case PendingApproval = 'PENDING_APPROVAL';
    case Scheduled = 'SCHEDULED';
    case Sending = 'SENDING';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::PendingApproval => __('Waiting for approval'),
            self::Scheduled => __('Scheduled'),
            self::Sending => __('Sending'),
            self::Completed => __('Completed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::PendingApproval, self::Scheduled => 'warning',
            self::Sending => 'default',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
