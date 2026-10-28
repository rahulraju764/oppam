<?php

declare(strict_types=1);

use App\Actions\Broker\ManagedProfiles\CreateManagedProfile;
use App\Domain\Broker\Import\ImportColumns;
use App\Domain\Broker\Import\ImportContext;
use App\Domain\Broker\Import\ImportRowValidator;
use App\Domain\Broker\ManagedProfileRules;
use App\Domain\Profile\ProfileRules;
use App\Enums\BureauRole;
use App\Enums\ImportRowStatus;
use App\Models\Masters\Caste;
use App\Models\Masters\Religion;
use Database\Seeders\PlansSeeder;
use Illuminate\Validation\ValidationException;

/*
| ★ ImportValidationParityTest (PRD §11A B.17, B.22, R-M13-28 "imported rows use the identical
| validation rules as the single form") — P7.6. Two proofs: (1) structural — the import rule set is
| built from the same ProfileRules / ManagedProfileRules entries the "Add profile" form uses, rule
| for rule; (2) behavioural — the same bad inputs are refused on the same fields by
| CreateManagedProfile and by an import row.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
});

/** A rule as comparable data: strings as they are, objects by class + state (closures by class). */
function ruleShape(array $rules): array
{
    return array_map(fn (mixed $r): mixed => is_object($r) ? [get_class($r), $r instanceof Closure ? null : (array) $r] : $r, $rules);
}

/** Header-keyed cells → field-keyed cells, as the job hands them to the validator. */
function asFieldCells(array $row): array
{
    $cells = [];
    foreach (ImportColumns::autoMap(array_keys($row)) as $field => $header) {
        $cells[$field] = $row[$header];
    }

    return $cells;
}

function checkImportRow(array $row): array
{
    return app(ImportRowValidator::class)->check(asFieldCells($row), new ImportContext([], [], [], null));
}

/** @return list<string> template headers with an ERROR message */
function importErrorFields(array $result): array
{
    return array_values(array_unique(array_map(fn (array $m): string => $m['field'],
        array_filter($result['messages'], fn (array $m): bool => $m['level'] === 'error'))));
}

it('★ R-M13-28 structural: every step-1 rule of the single form is the import’s rule, unchanged', function (): void {
    $values = basicData()->toArray();
    $form = ManagedProfileRules::forCreate($values);
    $import = ManagedProfileRules::forImport($values);

    // The form's own rules come from ProfileRules::basic (no copy in the Action) …
    foreach (ProfileRules::basic($values) as $field => $rules) {
        expect(ruleShape($form[$field]))->toEqual(ruleShape($rules), "form: {$field}");
        // … and the import uses exactly the same entry.
        expect(ruleShape($import[$field]))->toEqual(ruleShape($rules), "import: {$field}");
    }

    expect(ruleShape($import['clientPhone']))->toEqual(ruleShape(ManagedProfileRules::clientPhone()))
        ->and($import['consent'])->toBe(['accepted'])
        ->and($form['consent'])->toBe(['accepted'])
        ->and(array_keys(array_intersect_key($import, ProfileRules::basic($values))))->toEqual(array_keys(ProfileRules::basic($values)));

    // The location and the optional career / about fields are the wizard's own rules, not copies.
    $career = ProfileRules::career($values);
    foreach (['current_country_id', 'current_state_id', 'current_district_id'] as $field) {
        expect(ruleShape($import[$field]))->toEqual(ruleShape($career[$field]), $field);
    }
    foreach (ManagedProfileRules::OPTIONAL_CAREER as $field) {
        expect(ruleShape($import[$field]))->toEqual(ruleShape(ProfileRules::career($values, partial: true)[$field]), $field);
    }
    expect(ruleShape($import['about']))->toEqual(ruleShape(ProfileRules::about(partial: true)['about']));
});

it('★ R-M13-28 structural: CreateManagedProfile validates through ManagedProfileRules (no inline rule set left)', function (): void {
    $source = (string) file_get_contents(app_path('Actions/Broker/ManagedProfiles/CreateManagedProfile.php'));

    expect($source)->toContain('ManagedProfileRules::forCreate(')
        ->and($source)->not->toContain('ProfileRules::basic(')
        ->and($source)->not->toContain("'consent' => ['accepted']");
});

it('a valid row is VALID — the same data the single form accepts', function (): void {
    $result = checkImportRow(importRow());

    expect($result['status'])->toBe(ImportRowStatus::Valid)
        ->and($result['messages'])->toBe([]);

    // … and the form accepts the same person.
    $profile = app(CreateManagedProfile::class)->handle(bureauLogin(BureauRole::DataEntry), managedData('9876500999'), basicData());
    expect($profile->exists)->toBeTrue();
});

it('★ R-M13-28 behavioural: the same bad input is refused on the same field by the form and by an import row', function (array $basic, array $cells, string $formKey, string $importHeader, ?string $phone, bool $consent): void {
    $login = bureauLogin(BureauRole::DataEntry);

    try {
        app(CreateManagedProfile::class)->handle($login, managedData($phone ?? '9876500888', $consent), basicData($basic));
        $this->fail('the form should refuse this');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($formKey);
    }

    $result = checkImportRow(importRow($cells));

    expect($result['status'])->toBe(ImportRowStatus::Error)
        ->and(importErrorFields($result))->toContain($importHeader);
})->with([
    'bride under 18' => [
        fn () => ['dob' => now('Asia/Kolkata')->subYears(17)->format('Y-m-d')],
        fn () => ['date_of_birth' => now('Asia/Kolkata')->subYears(17)->format('d-m-Y')],
        'dob', 'date_of_birth', null, true,
    ],
    'groom under 21' => [
        fn () => ['gender' => 'MALE', 'dob' => now('Asia/Kolkata')->subYears(20)->format('Y-m-d')],
        fn () => ['gender' => 'Male', 'date_of_birth' => now('Asia/Kolkata')->subYears(20)->format('d-m-Y')],
        'dob', 'date_of_birth', null, true,
    ],
    'caste of another religion' => [
        fn () => ['caste_id' => (int) Caste::query()->where('religion_id', Religion::query()->where('code', 'CHRISTIAN')->value('id'))->value('id')],
        fn () => ['caste' => (string) Caste::query()->where('religion_id', Religion::query()->where('code', 'CHRISTIAN')->value('id'))->value('code')],
        'caste_id', 'caste', null, true,
    ],
    'height out of range' => [['height_cm' => 90], ['height' => '90'], 'height_cm', 'height', null, true],
    'no religion' => [['religion_id' => null, 'caste_id' => null], ['religion' => '', 'caste' => ''], 'religion_id', 'religion', null, true],
    'no marital status' => [['marital_status' => null], ['marital_status' => ''], 'marital_status', 'marital_status', null, true],
    'bad mobile' => [[], ['client_mobile' => '12345'], 'clientPhone', 'client_mobile', '12345', true],
    'no consent' => [[], ['client_consent' => 'NO'], 'consent', 'client_consent', null, false],
]);
