<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Masters\Caste;
use App\Models\Masters\Country;
use App\Models\Masters\District;
use App\Models\Masters\Education;
use App\Models\Masters\IncomeBand;
use App\Models\Masters\MasterOption;
use App\Models\Masters\MasterRecord;
use App\Models\Masters\MotherTongue;
use App\Models\Masters\Occupation;
use App\Models\Masters\Rasi;
use App\Models\Masters\Religion;
use App\Models\Masters\Star;
use App\Models\Masters\State;
use App\Services\Masters\Masters;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Master data from database/seeders/data/masters.php (proposed lists — docs/decisions.md).
 * INSERT-ONLY: a row is matched on its immutable code (within its parent) and created only if it
 * is missing. An existing row is never touched, so re-seeding can't undo an A11 edit (a relabel,
 * a deactivation, a new sort order). Runs without model events; the cache is flushed once.
 */
final class MastersSeeder extends Seeder
{
    private const PAISE_PER_LAKH = 100_000 * 100;

    public function run(Masters $masters): void
    {
        /** @var array<string, mixed> $data */
        $data = require database_path('seeders/data/masters.php');

        Model::withoutEvents(function () use ($data): void {
            $this->seedFlat(Religion::class, $data['religions']);
            $this->seedFlat(Star::class, $data['stars']);
            $this->seedFlat(Rasi::class, $data['rasis']);
            $this->seedFlat(Country::class, $data['countries']);
            $this->seedFlat(Education::class, $data['education']);
            $this->seedFlat(Occupation::class, $data['occupations']);
            $this->seedFlat(MotherTongue::class, $data['mother_tongues']);

            $this->seedChildren(Caste::class, Religion::class, 'religion_id', $data['castes']);
            $this->seedChildren(State::class, Country::class, 'country_id', $data['states']);
            $this->seedChildren(District::class, State::class, 'state_id', $data['districts']);

            $this->seedIncomeBands($data['income_bands']);
            $this->seedOptions($data['options']);
        });

        $masters->flush();
    }

    /**
     * @param  class-string<MasterRecord>  $model
     * @param  array<string, string>  $rows  code => label
     */
    private function seedFlat(string $model, array $rows): void
    {
        $order = 0;

        foreach ($rows as $code => $label) {
            $model::query()->firstOrCreate(['code' => $code], ['label' => $label, 'sort_order' => ++$order, 'is_active' => true]);
        }
    }

    /**
     * @param  class-string<MasterRecord>  $model
     * @param  class-string<MasterRecord>  $parentModel
     * @param  array<string, array<string, string>>  $groups  parent code => [code => label]
     */
    private function seedChildren(string $model, string $parentModel, string $foreignKey, array $groups): void
    {
        foreach ($groups as $parentCode => $rows) {
            // Fails loudly on a typo'd parent code rather than seeding orphans.
            $parentId = (int) $parentModel::query()->where('code', $parentCode)->valueOrFail('id');
            $order = 0;

            foreach ($rows as $code => $label) {
                $model::query()->firstOrCreate(
                    [$foreignKey => $parentId, 'code' => $code],
                    ['label' => $label, 'sort_order' => ++$order, 'is_active' => true],
                );
            }
        }
    }

    /** @param array<string, array{0: string, 1: int, 2: int|null}> $bands  code => [label, min lakh, max lakh] */
    private function seedIncomeBands(array $bands): void
    {
        $order = 0;

        foreach ($bands as $code => [$label, $minLakh, $maxLakh]) {
            IncomeBand::query()->firstOrCreate(['code' => $code], [
                'label' => $label,
                'min_paise' => $minLakh * self::PAISE_PER_LAKH,
                'max_paise' => $maxLakh === null ? null : $maxLakh * self::PAISE_PER_LAKH,
                'sort_order' => ++$order,
                'is_active' => true,
            ]);
        }
    }

    /** @param array<string, array<string, string>> $groups */
    private function seedOptions(array $groups): void
    {
        foreach ($groups as $group => $rows) {
            $order = 0;

            foreach ($rows as $code => $label) {
                MasterOption::query()->firstOrCreate(
                    ['group' => $group, 'code' => $code],
                    ['label' => $label, 'sort_order' => ++$order, 'is_active' => true],
                );
            }
        }
    }
}
