<?php

declare(strict_types=1);

use App\Models\Masters\Caste;
use App\Models\Masters\Religion;
use App\Rules\CasteBelongsToReligion;
use App\Services\Masters\Masters;
use Database\Seeders\MastersSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/*
| P0.4 — master data: seed lists, caste-by-religion, the cached Masters service (PRD §7.1, A11).
*/

function religionId(string $code): int
{
    return (int) Religion::query()->where('code', $code)->value('id');
}

it('seeds the Kerala master lists', function (): void {
    $this->seed(MastersSeeder::class);
    $masters = app(Masters::class);

    expect($masters->stars())->toHaveCount(27)
        ->and($masters->rasis())->toHaveCount(12)
        ->and(DB::table('master_districts')->count())->toBe(14)
        ->and($masters->religions()[0]->code)->toBe('HINDU')
        ->and(collect($masters->options('diet'))->pluck('code')->all())->toBe(['VEG', 'NON_VEG', 'EGGETARIAN', 'VEGAN']);
});

it('can seed the masters twice without duplicating a row', function (): void {
    $this->seed(MastersSeeder::class);
    $counts = collect(['master_religions', 'master_castes', 'master_districts', 'master_options', 'master_income_bands'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);

    $this->seed(MastersSeeder::class);

    $counts->each(fn (int $count, string $table) => expect(DB::table($table)->count())->toBe($count));
});

it('lists castes by religion only', function (): void {
    $this->seed(MastersSeeder::class);
    $masters = app(Masters::class);

    $christian = collect($masters->castesForReligion(religionId('CHRISTIAN')))->pluck('code');
    $hindu = collect($masters->castesForReligion(religionId('HINDU')))->pluck('code');

    expect($christian)->toContain('SYRO_MALABAR')->not->toContain('NAIR')
        ->and($hindu)->toContain('NAIR')->not->toContain('SYRO_MALABAR');
});

it('keeps income bands in paise with an open-ended top band', function (): void {
    $this->seed(MastersSeeder::class);

    $band = DB::table('master_income_bands')->where('code', '10_15L')->first();
    $top = DB::table('master_income_bands')->where('code', 'ABOVE_1CR')->first();

    expect($band->min_paise)->toBe(10 * 100_000 * 100)
        ->and($band->max_paise)->toBe(15 * 100_000 * 100)
        ->and($top->max_paise)->toBeNull();
});

it('hides inactive master rows from the dropdown lists', function (): void {
    $religion = Religion::factory()->create();
    Caste::factory()->for($religion)->create(['label' => 'Shown']);
    Caste::factory()->for($religion)->inactive()->create(['label' => 'Retired']);

    expect(collect(app(Masters::class)->castesForReligion($religion->id))->pluck('label')->all())->toBe(['Shown']);
});

it('serves lists from cache and refreshes them after any master write (A11)', function (): void {
    $religion = Religion::factory()->create();
    Caste::factory()->for($religion)->create(['label' => 'First']);
    $masters = app(Masters::class);

    expect($masters->castesForReligion($religion->id))->toHaveCount(1);

    // Cached: a raw insert (no model events) is not seen…
    DB::table('master_castes')->insert(['religion_id' => $religion->id, 'code' => 'RAW', 'label' => 'Raw', 'sort_order' => 9, 'is_active' => true]);
    expect($masters->castesForReligion($religion->id))->toHaveCount(1);

    // …but a write through the model (as A11 does) flushes every list.
    Caste::factory()->for($religion)->create(['label' => 'Second']);
    expect($masters->castesForReligion($religion->id))->toHaveCount(3);
});

it('accepts a caste only if it is an active caste of the chosen religion', function (): void {
    $this->seed(MastersSeeder::class);
    $hindu = religionId('HINDU');
    $nair = Caste::query()->where('religion_id', $hindu)->where('code', 'NAIR')->value('id');
    $syroMalabar = Caste::query()->where('religion_id', religionId('CHRISTIAN'))->where('code', 'SYRO_MALABAR')->value('id');
    $retired = Caste::factory()->inactive()->create(['religion_id' => $hindu])->id;

    $passes = fn (mixed $casteId, ?int $religion = null): bool => Validator::make(
        ['caste_id' => $casteId],
        ['caste_id' => [new CasteBelongsToReligion($religion ?? $hindu)]],
    )->passes();

    expect($passes($nair))->toBeTrue()
        ->and($passes(null))->toBeTrue()                 // caste is optional (caste no bar)
        ->and($passes($syroMalabar))->toBeFalse()        // tampered: another religion's caste
        ->and($passes($retired))->toBeFalse()            // deactivated
        ->and($passes(999_999))->toBeFalse()             // missing
        ->and($passes('1 OR 1=1'))->toBeFalse();         // not an id
});

it('never overwrites an admin-edited master row when the seeder runs again (A11)', function (): void {
    $this->seed(MastersSeeder::class);
    $nair = Caste::query()->where('code', 'NAIR')->firstOrFail();
    $nair->update(['label' => 'Nair (all sub-sects)', 'is_active' => false, 'sort_order' => 99]);

    $this->seed(MastersSeeder::class);

    expect($nair->fresh()->only(['label', 'is_active', 'sort_order']))
        ->toBe(['label' => 'Nair (all sub-sects)', 'is_active' => false, 'sort_order' => 99]);
});

it('bumps the cache version on every flush', function (): void {
    $masters = app(Masters::class);
    $before = (int) cache()->get(Masters::VERSION_KEY, 1);

    $masters->flush();
    $masters->flush();

    expect((int) cache()->get(Masters::VERSION_KEY))->toBe($before + 2);
});
