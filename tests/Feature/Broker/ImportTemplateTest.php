<?php

declare(strict_types=1);

use App\Domain\Broker\Import\ImportColumns;
use App\Domain\Broker\Import\ImportContext;
use App\Domain\Broker\Import\ImportRowValidator;
use App\Enums\BureauRole;
use App\Enums\Flag;
use App\Enums\ImportRowStatus;
use App\Services\Broker\ImportTemplateBuilder;
use Database\Seeders\PlansSeeder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/*
| P7.6 — the import template (§11A B.9 "generated live from master data: a Profiles sheet in
| wizard order, reference sheets with drop-down validation, an Instructions sheet; a .csv
| version"). Downloaded by `profiles.import` roles only, behind the `broker.bulk_import` flag.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    flagOn(Flag::BrokerBulkImport);
});

function templateBook(): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    file_put_contents($path, app(ImportTemplateBuilder::class)->xlsx());

    return IOFactory::load($path);
}

it('B.9: the xlsx has the Profiles sheet (headers in wizard order), the reference sheets and Instructions', function (): void {
    $book = templateBook();

    expect($book->getSheetNames())->toBe(['Profiles', 'Lists', 'Castes', 'Locations', 'Instructions'])
        ->and($book->getSheetByName('Profiles')->rangeToArray('A1:AE1')[0])->toBe(ImportColumns::headers())
        // No example rows to import by mistake (the drop-downs cover rows 2–501, but hold no values).
        ->and(array_filter(collect($book->getSheetByName('Profiles')->rangeToArray('A2:AE20'))->flatten()->all(), fn ($v) => $v !== null && $v !== ''))->toBe([]);
    $book->disconnectWorksheets();
});

it('B.9: drop-downs come from LIVE master data (a new religion shows up in the next download)', function (): void {
    App\Models\Masters\Religion::factory()->create(['code' => 'ZZTEST', 'label' => 'Zz Test Faith', 'is_active' => true]);
    app(App\Services\Masters\Masters::class)->flush();

    $book = templateBook();
    $religionColumn = chr(ord('A') + array_search('religion', ImportColumns::headers(), true));
    $validation = $book->getSheetByName('Profiles')->getDataValidation($religionColumn.'2');

    expect($validation->getType())->toBe('list')
        ->and($validation->getFormula1())->toBe('list_religion_id');

    $lists = $book->getSheetByName('Lists')->toArray();
    expect(collect($lists)->flatten()->all())->toContain('Zz Test Faith')
        ->and(collect($book->getSheetByName('Castes')->toArray())->flatten()->all())->toContain('Hindu');
    $book->disconnectWorksheets();
});

it('the Instructions example row round-trips: it validates as VALID with the single form’s rules', function (): void {
    $book = templateBook();
    $rows = $book->getSheetByName('Instructions')->toArray(null, false, false, false);
    $book->disconnectWorksheets();
    $headerIndex = collect($rows)->search(fn (array $r): bool => ($r[0] ?? null) === 'first_name');
    $headers = $rows[$headerIndex];
    $example = array_combine($headers, array_slice($rows[$headerIndex + 1], 0, count($headers)));

    $cells = [];
    foreach (ImportColumns::autoMap($headers) as $field => $header) {
        $cells[$field] = $example[$header];
    }
    $result = app(ImportRowValidator::class)->check($cells, new ImportContext([], [], [], ['anjali_1.jpg' => true, 'anjali_2.jpg' => true]));

    expect($result['status'])->toBe(ImportRowStatus::Valid, json_encode($result['messages']));
});

it('the CSV variant: the header row only, UTF-8 with a BOM', function (): void {
    $csv = app(ImportTemplateBuilder::class)->csv();

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and(str_getcsv(trim(substr($csv, 3)), ',', '"', ''))->toBe(ImportColumns::headers());
});

it('B.11: template downloads are for `profiles.import` roles, behind the flag', function (): void {
    actingAsBureau(bureauLogin(BureauRole::DataEntry));
    $this->get(brokerUrl('/import/template.xlsx'))->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="oppam-import-template.xlsx"');
    $this->get(brokerUrl('/import/template.csv'))->assertOk();

    actingAsBureau(bureauLogin(BureauRole::Telecaller));
    $this->get(brokerUrl('/import/template.xlsx'))->assertForbidden();

    App\Models\FeatureFlag::query()->where('key', Flag::BrokerBulkImport->value)->update(['is_enabled' => false]);
    app(App\Services\Settings\FeatureFlags::class)->flush();
    actingAsBureau(bureauLogin(BureauRole::Owner));
    $this->get(brokerUrl('/import/template.xlsx'))->assertNotFound();
});
