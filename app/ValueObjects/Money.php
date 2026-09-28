<?php

declare(strict_types=1);

namespace App\ValueObjects;

use InvalidArgumentException;

/**
 * An amount of Indian rupees held as integer paise (CLAUDE.md: money is never a float).
 */
final readonly class Money
{
    private function __construct(public int $paise)
    {
        if ($paise < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }
    }

    public static function paise(int $paise): self
    {
        return new self($paise);
    }

    public static function rupees(int $rupees): self
    {
        return new self($rupees * 100);
    }

    /** "1,999" or "1,00,000.50" — Indian digit grouping, paise shown only when non-zero. */
    public function format(): string
    {
        $rupees = (string) intdiv($this->paise, 100);
        $paise = $this->paise % 100;

        if (strlen($rupees) > 3) {
            $rupees = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($rupees, 0, -3)).','.substr($rupees, -3);
        }

        return $paise === 0 ? $rupees : sprintf('%s.%02d', $rupees, $paise);
    }

    /** "₹1,999" */
    public function formatWithSymbol(): string
    {
        return '₹'.$this->format();
    }

    public function add(self $other): self
    {
        return new self($this->paise + $other->paise);
    }

    public function multiply(int $times): self
    {
        return new self($this->paise * $times);
    }
}
