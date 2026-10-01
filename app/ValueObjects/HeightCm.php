<?php

declare(strict_types=1);

namespace App\ValueObjects;

use InvalidArgumentException;

/**
 * A height in whole centimetres, shown the Kerala way: 5' 4" (163 cm). The wizard offers one
 * option per inch from 4' 6" to 7' 0" (template profile-creation.php, extended); the same range
 * bounds validation, search filters and bulk-import parsing.
 */
final readonly class HeightCm
{
    public const MIN = 137;   // 4' 6"

    public const MAX = 213;   // 7' 0"

    private function __construct(public int $cm) {}

    public static function of(int $cm): self
    {
        if ($cm < self::MIN || $cm > self::MAX) {
            throw new InvalidArgumentException('Height out of range.');
        }

        return new self($cm);
    }

    public function label(): string
    {
        $totalInches = (int) round($this->cm / 2.54);

        return intdiv($totalInches, 12)."' ".($totalInches % 12).'" ('.$this->cm.' cm)';
    }

    /** @return array<int, string> cm => "5' 4\" (163 cm)", one per inch, for the height select */
    public static function options(): array
    {
        $options = [];

        for ($inches = 54; $inches <= 84; $inches++) {
            $cm = (int) round($inches * 2.54);
            $options[$cm] = self::of($cm)->label();
        }

        return $options;
    }
}
