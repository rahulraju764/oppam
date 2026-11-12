<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Contracts\IntegrationProbe;

/**
 * The A15 integrations, in screen order (P8.4). Tests bind fakes for any of them through the
 * container (each probe is resolved by class).
 */
final class Probes
{
    /** @var list<class-string<IntegrationProbe>> */
    public const ALL = [
        RazorpayProbe::class, Msg91Probe::class, MailProbe::class, StorageProbe::class, BroadcastProbe::class, TurnstileProbe::class,
    ];

    /** @return array<string, IntegrationProbe> key => probe */
    public function all(): array
    {
        $probes = [];
        foreach (self::ALL as $class) {
            $probe = app($class);
            $probes[$probe->key()] = $probe;
        }

        return $probes;
    }

    public static function mask(?string $value): string
    {
        $value = (string) $value;
        if ($value === '') {
            return __('Not set');
        }

        return strlen($value) <= 8 ? '••••••••' : substr($value, 0, 4).'••••'.substr($value, -4);
    }
}
