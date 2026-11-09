<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A14 delivery log: what happened to one outbound email / SMS. SENT = the provider accepted it;
 * DELIVERED / BOUNCED come from the provider's report (webhooks); FAILED = refused or unreachable.
 */
enum OutboundStatus: string
{
    case Sent = 'SENT';
    case Delivered = 'DELIVERED';
    case Failed = 'FAILED';
    case Bounced = 'BOUNCED';

    public function label(): string
    {
        return match ($this) {
            self::Sent => __('Sent'),
            self::Delivered => __('Delivered'),
            self::Failed => __('Failed'),
            self::Bounced => __('Bounced'),
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Sent => 'default',
            self::Delivered => 'success',
            self::Failed, self::Bounced => 'danger',
        };
    }

    public function isFailure(): bool
    {
        return $this === self::Failed || $this === self::Bounced;
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
