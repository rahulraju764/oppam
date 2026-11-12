<?php

declare(strict_types=1);

namespace App\Data\Comms;

use App\Enums\Gender;
use App\Enums\PlanCode;
use Carbon\CarbonImmutable;

/**
 * The A14 segment builder's filters (PRD A14: plan, gender, religion, district, completeness, last
 * active, registered range, has photo, verified, broker-managed). Stored on the campaign as JSON
 * (toArray / fromArray) so a saved draft reopens with the same filters; every value is typed and
 * unknown keys are dropped. Null = "any".
 * `viaBureau` is "came through a bureau" (owner 2026-11-08 (4)): registered with a broker code or
 * claimed a profile a bureau created.
 */
final readonly class CampaignSegment
{
    public function __construct(
        public ?PlanCode $plan = null,
        public ?Gender $gender = null,
        public ?int $religionId = null,
        public ?int $districtId = null,
        public ?int $completenessMin = null,
        public ?int $activeWithinDays = null,
        public ?int $inactiveForDays = null,
        public ?CarbonImmutable $registeredFrom = null,
        public ?CarbonImmutable $registeredTo = null,
        public ?bool $hasPhoto = null,
        public ?bool $verified = null,
        public ?bool $viaBureau = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $int = fn (string $key): ?int => isset($data[$key]) && is_numeric($data[$key]) && (int) $data[$key] > 0 ? (int) $data[$key] : null;
        $bool = fn (string $key): ?bool => isset($data[$key]) && is_bool($data[$key]) ? $data[$key] : null;
        $date = function (string $key) use ($data): ?CarbonImmutable {
            $value = $data[$key] ?? null;
            if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return null;
            }

            return CarbonImmutable::createFromFormat('Y-m-d', $value, (string) config('oppam.display_timezone')) ?: null;
        };

        return new self(
            plan: is_string($data['plan'] ?? null) ? PlanCode::tryFrom($data['plan']) : null,
            gender: is_string($data['gender'] ?? null) ? Gender::tryFrom($data['gender']) : null,
            religionId: $int('religion_id'),
            districtId: $int('district_id'),
            completenessMin: $int('completeness_min'),
            activeWithinDays: $int('active_within_days'),
            inactiveForDays: $int('inactive_for_days'),
            registeredFrom: $date('registered_from'),
            registeredTo: $date('registered_to'),
            hasPhoto: $bool('has_photo'),
            verified: $bool('verified'),
            viaBureau: $bool('via_bureau'),
        );
    }

    /** @return array<string, int|bool|string> only the filters that are set */
    public function toArray(): array
    {
        return array_filter([
            'plan' => $this->plan?->value,
            'gender' => $this->gender?->value,
            'religion_id' => $this->religionId,
            'district_id' => $this->districtId,
            'completeness_min' => $this->completenessMin,
            'active_within_days' => $this->activeWithinDays,
            'inactive_for_days' => $this->inactiveForDays,
            'registered_from' => $this->registeredFrom?->format('Y-m-d'),
            'registered_to' => $this->registeredTo?->format('Y-m-d'),
            'has_photo' => $this->hasPhoto,
            'verified' => $this->verified,
            'via_bureau' => $this->viaBureau,
        ], fn (mixed $value): bool => $value !== null);
    }
}
