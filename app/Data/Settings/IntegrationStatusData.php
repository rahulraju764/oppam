<?php

declare(strict_types=1);

namespace App\Data\Settings;

/**
 * The result of one A15 integration check (P8.4): ok / warning / error / info + a message an admin
 * can act on. Never carries a secret (only masked public ids, driver names, counts).
 */
final readonly class IntegrationStatusData
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    public const INFO = 'info';

    public function __construct(
        public string $state,
        public string $message,
    ) {}

    public static function ok(string $message): self
    {
        return new self(self::OK, $message);
    }

    public static function warning(string $message): self
    {
        return new self(self::WARNING, $message);
    }

    public static function error(string $message): self
    {
        return new self(self::ERROR, $message);
    }

    public static function info(string $message): self
    {
        return new self(self::INFO, $message);
    }

    /** The <x-ui.alert> type for this state. */
    public function variant(): string
    {
        return match ($this->state) {
            self::OK => 'success',
            self::WARNING => 'warning',
            self::ERROR => 'danger',
            default => 'info',
        };
    }
}
