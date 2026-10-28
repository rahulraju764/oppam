<?php

declare(strict_types=1);

namespace App\Domain\Broker\Import;

use App\Domain\Broker\ManagedProfileRules;
use App\Enums\ImportRowStatus;
use App\Enums\PhysicalStatus;
use App\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Validator;

/**
 * Checks one import row (§11A B.9 step 2, R-M13-28 / R-M13-29) — with ManagedProfileRules, the
 * SAME rule set as the single "Add profile" form (ImportValidationParityTest). Cells are parsed by
 * CellParser first; then:
 *  - any parse or rule error → ERROR (the row can't be imported as it is);
 *  - a mobile already on Oppam (account, managed profile, ban) or repeated in the file → ERROR;
 *  - the same name + date of birth already a client of this bureau → WARNING;
 *  - photo names missing from the zip, or more than MAX_PHOTOS of them → WARNING.
 * Messages carry the template's column names and friendly text — never another person's data.
 */
final class ImportRowValidator
{
    public const MAX_PHOTOS = 5;

    public function __construct(private readonly CellParser $cells) {}

    /**
     * @param  array<string, mixed>  $cells  field => raw cell (only mapped columns)
     * @return array{status: ImportRowStatus, values: array<string, mixed>, messages: list<array{field: string, level: string, text: string}>}
     */
    public function check(array $cells, ImportContext $context): array
    {
        $india = $this->cells->parse('master:countries', 'IN')[0];   // an empty country means India (the template says so)
        $values = ['current_country_id' => $india];   // set before state / district are read (they depend on it)
        $messages = [];

        foreach (ImportColumns::COLUMNS as $field => [, , $kind]) {
            if (! array_key_exists($field, $cells)) {
                continue;
            }
            [$value, $error] = $this->cells->parse($kind, $cells[$field], $values);
            $values[$field] = $field === 'current_country_id' && $value === null && $error === null ? $india : $value;
            if ($error !== null) {
                $messages[$field] = self::message($field, 'error', $error);
            }
        }

        // Defaults the single form also starts from.
        $values['physical_status'] ??= PhysicalStatus::Normal->value;
        $values['caste_no_bar'] ??= false;
        $values['current_country_id'] ??= $india;

        $input = [...$values, 'clientPhone' => $values['client_mobile'] ?? null, 'consent' => (bool) ($values['client_consent'] ?? false)];
        $validator = Validator::make($input, ManagedProfileRules::forImport($input), [
            ...ManagedProfileRules::messages(),
            'consent.accepted' => __('client_consent must say YES — the client has agreed to this profile.'),
        ], ManagedProfileRules::attributes());

        foreach ($validator->errors()->messages() as $key => $errors) {
            $field = match ($key) {
                'clientPhone' => 'client_mobile',
                'consent' => 'client_consent',
                default => $key,
            };
            $messages[$field] ??= self::message($field, 'error', (string) $errors[0]);   // a parse error says more than "is required"
        }

        $phone = isset($values['client_mobile']) ? PhoneNumber::tryParse((string) $values['client_mobile'])?->e164() : null;
        if ($phone !== null && ! isset($messages['client_mobile'])) {
            if (isset($context->taken[$phone])) {
                $messages['client_mobile'] = self::message('client_mobile', 'error', __('This mobile number is already on Oppam. A client with an account manages their own profile.'));
            } elseif (($context->inFile[$phone] ?? 0) > 1) {
                $messages['client_mobile'] = self::message('client_mobile', 'error', __('This mobile number appears more than once in the file. Each client needs their own mobile.'));
            }
        }

        $warnings = [];
        if (isset($values['first_name'], $values['dob']) && isset($context->bureauClients[ImportContext::personKey((string) $values['first_name'], (string) ($values['last_name'] ?? ''), (string) $values['dob'])])) {
            $warnings[] = self::message('first_name', 'warning', __('A client with the same name and date of birth is already in your bureau.'));
        }

        $photos = $values['photo_files'] ?? [];
        if (is_array($photos) && $photos !== []) {
            if (count($photos) > self::MAX_PHOTOS) {
                $warnings[] = self::message('photo_files', 'warning', __('Only the first :max photos will be used.', ['max' => self::MAX_PHOTOS]));
            }
            $missing = $context->zipNames === null
                ? $photos
                : array_values(array_filter($photos, fn (string $n): bool => ! isset($context->zipNames[mb_strtolower(basename($n))])));
            if ($missing !== []) {
                $warnings[] = self::message('photo_files', 'warning', $context->zipNames === null
                    ? __('No photo zip was uploaded; photos can be added later per profile.')
                    : __('Not in the zip: :names. Photos can be added later per profile.', ['names' => implode(', ', array_slice($missing, 0, 5))]));
            }
        }

        $all = [...array_values($messages), ...$warnings];

        return [
            'status' => $messages !== [] ? ImportRowStatus::Error : ($warnings !== [] ? ImportRowStatus::Warning : ImportRowStatus::Valid),
            'values' => $values,
            'messages' => $all,
        ];
    }

    /** @return array{field: string, level: string, text: string} */
    private static function message(string $field, string $level, string $text): array
    {
        return ['field' => ImportColumns::COLUMNS[$field][0] ?? $field, 'level' => $level, 'text' => $text];
    }
}
