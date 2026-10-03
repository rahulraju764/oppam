<?php

declare(strict_types=1);

namespace App\Data\Admin;

/**
 * The profile fields an admin may correct from A03 "Profile (edit with diff + reason)". Gender is
 * deliberately not here (it decides who sees whom — a support case, not an edit). Keys of
 * toArray() are the profiles column names, validated with the wizard's own ProfileRules.
 */
final readonly class MemberProfileEditData
{
    public function __construct(
        public string $firstName,
        public ?string $lastName,
        public ?string $dob,
        public ?int $heightCm,
        public ?int $weightKg,
        public ?string $maritalStatus,
        public ?int $religionId,
        public ?int $casteId,
        public ?string $subCaste,
        public ?string $about,
    ) {}

    /** The editable columns. */
    public const FIELDS = ['first_name', 'last_name', 'dob', 'height_cm', 'weight_kg', 'marital_status', 'religion_id', 'caste_id', 'sub_caste', 'about'];

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'dob' => $this->dob,
            'height_cm' => $this->heightCm,
            'weight_kg' => $this->weightKg,
            'marital_status' => $this->maritalStatus,
            'religion_id' => $this->religionId,
            'caste_id' => $this->casteId,
            'sub_caste' => $this->subCaste,
            'about' => $this->about,
        ];
    }

    /** @param  array<string, mixed>  $input  form state (strings from the browser) */
    public static function fromInput(array $input): self
    {
        $text = fn (string $key): ?string => is_scalar($input[$key] ?? null) && trim((string) $input[$key]) !== '' ? trim((string) $input[$key]) : null;
        $int = fn (string $key): ?int => is_numeric($input[$key] ?? null) ? (int) $input[$key] : null;

        return new self(
            firstName: $text('first_name') ?? '',
            lastName: $text('last_name'),
            dob: $text('dob'),
            heightCm: $int('height_cm'),
            weightKg: $int('weight_kg'),
            maritalStatus: $text('marital_status'),
            religionId: $int('religion_id'),
            casteId: $int('caste_id'),
            subCaste: $text('sub_caste'),
            about: $text('about'),
        );
    }
}
