<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;

/**
 * An A09 report period (P9.1a): IST days from–to, both included. previous() is the same number of
 * days right before it (compare-to-previous). utcFrom() / utcTo() bound timestamp columns.
 */
final readonly class ReportRange
{
    public const MAX_DAYS = 400;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function of(string $from, string $to): self
    {
        $tz = (string) config('oppam.display_timezone');

        return new self(CarbonImmutable::parse($from, $tz)->startOfDay(), CarbonImmutable::parse($to, $tz)->startOfDay());
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function previous(): self
    {
        return new self($this->from->subDays($this->days()), $this->from->subDay());
    }

    public function utcFrom(): CarbonImmutable
    {
        return $this->from->utc();
    }

    public function utcTo(): CarbonImmutable
    {
        return $this->to->addDay()->utc();
    }

    public function label(): string
    {
        return $this->from->format('d M Y').' – '.$this->to->format('d M Y');
    }

    /** @return list<string> every IST date in the range, Y-m-d */
    public function dates(): array
    {
        $dates = [];
        for ($d = $this->from; $d->lessThanOrEqualTo($this->to); $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }
}
