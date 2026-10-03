<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\Gender;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\UserStatus;
use Carbon\CarbonImmutable;

/**
 * The A03 member list's search + facets, built from untrusted input (URL / form state) by
 * fromInput(): every value is allow-listed or parsed, anything else is dropped. Shared by the
 * list and the CSV export, so both always mean the same members.
 */
final readonly class MemberSearchCriteria
{
    public const LAST_ACTIVE = ['7d' => 7, '30d' => 30, '90d' => 90];

    public const INACTIVE = '90d+';

    public const SORTS = ['newest', 'oldest', 'last_active'];

    public function __construct(
        public ?string $search = null,
        public ?UserStatus $accountStatus = null,
        public ?ProfileStatus $profileStatus = null,
        public ?bool $verified = null,
        public ?PlanCode $plan = null,
        public ?int $completenessMin = null,
        public ?Gender $gender = null,
        public ?int $religionId = null,
        public ?int $districtId = null,
        public ?CarbonImmutable $registeredFrom = null,
        public ?CarbonImmutable $registeredTo = null,
        public ?string $lastActive = null,
        public string $sort = 'newest',
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function fromInput(array $input): self
    {
        $string = fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $id = fn (string $key): ?int => ctype_digit($string($key)) && (int) $string($key) > 0 ? (int) $string($key) : null;
        $date = function (string $key) use ($string): ?CarbonImmutable {
            $value = $string($key);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return null;
            }
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value, (string) config('oppam.display_timezone'));

            return $parsed !== null && $parsed->format('Y-m-d') === $value ? $parsed : null;
        };

        $search = mb_substr($string('q'), 0, 100);
        $verified = $string('verified');
        $completeness = $string('completeness');
        $lastActive = $string('active');
        $sort = $string('sort');

        return new self(
            search: $search !== '' ? $search : null,
            accountStatus: UserStatus::tryFrom($string('status')),
            profileStatus: ProfileStatus::tryFrom($string('profileStatus')),
            verified: match ($verified) {
                '1' => true, '0' => false, default => null
            },
            plan: PlanCode::tryFrom($string('plan')),
            completenessMin: in_array($completeness, ['25', '50', '75', '100'], true) ? (int) $completeness : null,
            gender: Gender::tryFrom($string('gender')),
            religionId: $id('religion'),
            districtId: $id('district'),
            registeredFrom: $date('from'),
            registeredTo: $date('to'),
            lastActive: array_key_exists($lastActive, self::LAST_ACTIVE) || $lastActive === self::INACTIVE ? $lastActive : null,
            sort: in_array($sort, self::SORTS, true) ? $sort : 'newest',
        );
    }

    /** @return array<string, string|int|bool|null> a readable summary for the export audit row */
    public function summary(): array
    {
        return array_filter([
            'q' => $this->search !== null ? '[search]' : null,   // may be a phone / email: not logged
            'status' => $this->accountStatus?->value,
            'profile_status' => $this->profileStatus?->value,
            'verified' => $this->verified,
            'plan' => $this->plan?->value,
            'completeness_min' => $this->completenessMin,
            'gender' => $this->gender?->value,
            'religion' => $this->religionId,
            'district' => $this->districtId,
            'from' => $this->registeredFrom?->toDateString(),
            'to' => $this->registeredTo?->toDateString(),
            'active' => $this->lastActive,
        ], fn (mixed $v): bool => $v !== null);
    }
}
