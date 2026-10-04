<?php

declare(strict_types=1);

use App\Actions\Admin\Masters\DeleteMasterRow;
use App\Actions\Admin\Masters\ImportMasterRows;
use App\Actions\Admin\Masters\ReorderMasterRows;
use App\Actions\Admin\Masters\SaveMasterRow;
use App\Actions\Admin\Masters\SetMasterRowActive;
use App\Data\Masters\MasterImportRow;
use App\Data\Masters\MasterRowData;
use App\Domain\Masters\MasterLists;
use App\Domain\Masters\MasterUsage;
use App\Domain\Moderation\ModerationFlags;
use App\Exceptions\Admin\MasterRowInUse;
use App\Models\AuditLog;
use App\Models\Masters\Caste;
use App\Models\Masters\MasterOption;
use App\Models\Masters\Religion;
use App\Models\PartnerPreference;
use App\Services\Masters\Masters;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/*
| P1.8 — A11 master data: immutable codes, editable labels, deactivate-not-delete when used,
| ordering, CSV export + import with preview, cache flushed on every write, editable profanity
| word lists feeding the A04 pre-flags. Every write needs masters.edit and is audited.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
});

function a11(string $key): App\Domain\Masters\MasterList
{
    return MasterLists::find($key) ?? throw new RuntimeException($key);
}

function a11Religion(string $code = 'HINDU'): int
{
    return masterId(Religion::class, $code);
}

// ---- Done when: a label edit shows in the wizard on the next request -----------------------------

it('Done when: editing a caste label is in the wizard\'s dropdown on the very next read (cache flushed)', function (): void {
    $religion = a11Religion();
    $masters = app(Masters::class);
    $caste = Caste::query()->where('religion_id', $religion)->orderBy('sort_order')->firstOrFail();
    expect(collect($masters->castesForReligion($religion))->pluck('label'))->toContain($caste->label);   // cache is warm

    app(SaveMasterRow::class)->update(adminWithRole('content_editor'), a11('castes'), $religion, $caste->id, MasterRowData::from('IGNORED', 'Renamed caste label'));

    expect(collect(app(Masters::class)->castesForReligion($religion))->pluck('label'))->toContain('Renamed caste label')
        ->and(AuditLog::query()->where('action', 'masters.updated')->count())->toBe(1);
});

// ---- Codes, labels, scope -------------------------------------------------------------------------

it('A11 codes are immutable: an edit changes the label only', function (): void {
    $religion = Religion::query()->where('code', 'HINDU')->firstOrFail();

    app(SaveMasterRow::class)->update(adminWithRole(), a11('religions'), null, $religion->id, MasterRowData::from('CHANGED_CODE', 'Hindu (Sanatan)', 'ഹിന്ദു'));

    $religion->refresh();
    expect($religion->code)->toBe('HINDU')
        ->and($religion->label)->toBe('Hindu (Sanatan)')
        ->and($religion->label_ml)->toBe('ഹിന്ദു');
});

it('A11 new rows: UPPER_SNAKE code unique in its list / parent, label required, added at the end and active', function (): void {
    $hindu = a11Religion();
    $christian = a11Religion('CHRISTIAN');
    $save = app(SaveMasterRow::class);
    $admin = adminWithRole();

    $row = $save->create($admin, a11('castes'), $hindu, MasterRowData::from('new_caste_a', 'New caste A'));
    expect($row->code)->toBe('NEW_CASTE_A')
        ->and($row->is_active)->toBeTrue()
        ->and($row->sort_order)->toBe((int) Caste::query()->where('religion_id', $hindu)->max('sort_order'));

    // The same code under another religion is fine (castes are unique per religion).
    $save->create($admin, a11('castes'), $christian, MasterRowData::from('NEW_CASTE_A', 'New caste A'));

    foreach ([['NEW_CASTE_A', 'Another label'], ['1BAD', 'Bad code'], ['GOOD_CODE', ''], ['OTHER_CODE', 'New caste A']] as [$code, $label]) {
        expect(fn () => $save->create($admin, a11('castes'), $hindu, MasterRowData::from($code, $label)))->toThrow(ValidationException::class);
    }
});

it('A11 rows are only reachable inside their own list and parent (404 otherwise)', function (): void {
    $hindu = a11Religion();
    $christianCaste = Caste::query()->where('religion_id', a11Religion('CHRISTIAN'))->firstOrFail();

    expect(fn () => app(SaveMasterRow::class)->update(adminWithRole(), a11('castes'), $hindu, $christianCaste->id, MasterRowData::from('X', 'Moved')))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SaveMasterRow::class)->create(adminWithRole(), a11('castes'), 999999, MasterRowData::from('ORPHAN', 'Orphan')))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SetMasterRowActive::class)->handle(adminWithRole(), a11('stars'), null, $christianCaste->id + 100000, false))
        ->toThrow(ModelNotFoundException::class);
});

it('A11 every write needs masters.edit (moderators can only look)', function (): void {
    $religion = Religion::query()->firstOrFail();

    expect(fn () => app(SaveMasterRow::class)->update(adminWithRole('moderator'), a11('religions'), null, $religion->id, MasterRowData::from('X', 'Nope')))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetMasterRowActive::class)->handle(adminWithRole('moderator'), a11('religions'), null, $religion->id, false))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteMasterRow::class)->handle(adminWithRole('support'), a11('religions'), null, $religion->id))
        ->toThrow(AuthorizationException::class);
});

// ---- Deactivate, not delete, when used --------------------------------------------------------------

it('A11 a used row can\'t be deleted — by a profile, by a partner preference (JSON) or by child rows', function (string $case): void {
    $admin = adminWithRole();
    [$list, $parent, $id] = match ($case) {
        'profile' => (function (): array {
            $member = memberWithPhone('+919855500001');

            return ['religions', null, (int) $member->profile->religion_id];
        })(),
        'preference json' => (function (): array {
            $star = App\Models\Masters\Star::query()->firstOrFail();
            $member = memberWithPhone('+919855500002');
            PartnerPreference::factory()->create(['profile_id' => $member->profile->id, 'star_ids' => [(int) $star->id]]);

            return ['stars', null, (int) $star->id];
        })(),
        'diet preference json' => (function (): array {
            $diet = MasterOption::query()->where('group', 'diet')->firstOrFail();
            $member = memberWithPhone('+919855500006');
            PartnerPreference::factory()->create(['profile_id' => $member->profile->id, 'diet_option_ids' => [(int) $diet->id]]);

            return ['option-diet', null, (int) $diet->id];
        })(),
        default => ['religions', null, a11Religion('CHRISTIAN')],   // castes hang under it
    };

    expect(fn () => app(DeleteMasterRow::class)->handle($admin, a11($list), $parent, $id))->toThrow(MasterRowInUse::class);
})->with(['profile', 'preference json', 'diet preference json', 'children']);

it('P1.8 review: every JSON id list on partner_preferences is checked by some master list before a delete', function (): void {
    $jsonColumns = collect(Illuminate\Support\Facades\Schema::getColumns('partner_preferences'))
        ->filter(fn (array $c): bool => str_ends_with($c['name'], '_ids') && ! in_array($c['name'], ['marital_statuses', 'physical_statuses'], true))
        ->pluck('name')->sort()->values()->all();
    $mapped = collect(MasterLists::all())->flatMap(fn ($list) => $list->jsonReferences)
        ->filter(fn (array $ref): bool => $ref[0] === 'partner_preferences')->map(fn (array $ref): string => $ref[1])->sort()->values()->all();

    expect($mapped)->toBe($jsonColumns);
});

// ---- P1.8 review: districts under a state (a parent that has its own parent) ----------------------------

it('P1.8 review Blocker: districts are edited per state — add, rename, reorder, export; another state\'s district is a 404', function (): void {
    $admin = adminWithRole();
    $kerala = (int) App\Models\Masters\State::query()->where('code', 'KL')->value('id');
    $tamilNadu = (int) App\Models\Masters\State::query()->where('code', 'TN')->value('id');
    expect($kerala)->toBeGreaterThan(0);
    $foreign = (int) app(SaveMasterRow::class)->create($admin, a11('districts'), $tamilNadu, MasterRowData::from('CHENNAI', 'Chennai'))->getKey();

    $row = app(SaveMasterRow::class)->create($admin, a11('districts'), $kerala, MasterRowData::from('NEW_DISTRICT', 'New district'));
    app(SaveMasterRow::class)->update($admin, a11('districts'), $kerala, (int) $row->getKey(), MasterRowData::from('X', 'Renamed district'));
    $ids = App\Models\Masters\District::query()->where('state_id', $kerala)->orderBy('sort_order')->pluck('id')->map(fn ($v): int => (int) $v)->reverse()->values()->all();
    app(ReorderMasterRows::class)->handle($admin, a11('districts'), $kerala, $ids);

    expect($row->refresh()->label)->toBe('Renamed district')
        ->and(App\Models\Masters\District::query()->where('state_id', $kerala)->orderBy('sort_order')->value('id'))->toBe($ids[0]);

    expect(fn () => app(SetMasterRowActive::class)->handle($admin, a11('districts'), $kerala, $foreign, false))->toThrow(ModelNotFoundException::class);

    signInAdmin($this, $admin);
    expect($this->get(adminUrl('/masters/districts/export?parent='.$kerala))->assertOk()->streamedContent())->toContain('NEW_DISTRICT');
});

it('P1.8 review: income bands take no new rows here (their amount range isn\'t editable)', function (): void {
    expect(fn () => app(SaveMasterRow::class)->create(adminWithRole(), a11('income-bands'), null, MasterRowData::from('NEW_BAND', 'New band')))
        ->toThrow(ValidationException::class);

    $preview = app(ImportMasterRows::class)->preview(adminWithRole(), a11('income-bands'), null, "code,label\nNEW_BAND,New band\n");
    expect($preview[0]->status)->toBe(MasterImportRow::ERROR);
});

it('P1.8 review: imports follow the editor\'s rules — duplicate labels, word-list words, a seeded word keeps its row; a re-imported export is lossless', function (): void {
    $admin = adminWithRole();
    $star = App\Models\Masters\Star::query()->orderBy('sort_order')->firstOrFail();

    $rows = collect(app(ImportMasterRows::class)->preview($admin, a11('stars'), null,
        "code,label\nNEW_ONE,{$star->label}\nNEW_TWO,Twin\nNEW_THREE,Twin\n"))->keyBy('code');
    expect($rows['NEW_ONE']->status)->toBe(MasterImportRow::ERROR)      // the label of another code
        ->and($rows['NEW_THREE']->status)->toBe(MasterImportRow::ERROR);  // twice in the file

    $words = collect(app(ImportMasterRows::class)->preview($admin, a11('profanity-en'), null, "label\nFuck\nnot-a-w0rd\n"))->values();
    expect($words[0]->code)->toBe('W01')->and($words[0]->status)->toBe(MasterImportRow::SAME)
        ->and($words[1]->status)->toBe(MasterImportRow::ERROR);

    app(SaveMasterRow::class)->update($admin, a11('stars'), null, $star->id, MasterRowData::from('X', '-dash star'));
    signInAdmin($this, $admin);
    $export = $this->get(adminUrl('/masters/stars/export'))->streamedContent();
    $again = collect(app(ImportMasterRows::class)->preview($admin, a11('stars'), null, $export));
    expect($again->where('status', '!=', MasterImportRow::SAME)->count())->toBe(0);
});

it('A11 an unused row can be deleted; a used one is deactivated instead and leaves the dropdowns only', function (): void {
    $admin = adminWithRole();
    $row = app(SaveMasterRow::class)->create($admin, a11('stars'), null, MasterRowData::from('TYPO_STAR', 'Typo star'));
    app(DeleteMasterRow::class)->handle($admin, a11('stars'), null, (int) $row->getKey());
    expect(App\Models\Masters\Star::query()->where('code', 'TYPO_STAR')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'masters.deleted')->count())->toBe(1);

    $member = memberWithPhone('+919855500003');
    $religionId = (int) $member->profile->religion_id;
    app(SetMasterRowActive::class)->handle($admin, a11('religions'), null, $religionId, false);

    expect(collect(app(Masters::class)->religions())->pluck('id'))->not->toContain($religionId)
        ->and($member->profile()->firstOrFail()->religion_id)->toBe($religionId)   // the profile keeps it
        ->and(AuditLog::query()->where('action', 'masters.deactivated')->count())->toBe(1);
});

it('A11 usage counts come from every referencing column', function (): void {
    $member = memberWithPhone('+919855500004');
    $religionId = (int) $member->profile->religion_id;
    memberWithPhone('+919855500005')->profile->forceFill(['religion_id' => $religionId, 'caste_id' => null])->save();

    $counts = app(MasterUsage::class)->counts(a11('religions'), [$religionId]);

    // Profiles with that religion + the castes under it.
    expect($counts[$religionId])->toBeGreaterThanOrEqual(2 + Caste::query()->where('religion_id', $religionId)->count());
});

// ---- Order ----------------------------------------------------------------------------------------------

it('A11 reorder: exactly the rows of the list / parent, in the new order; anything else is refused', function (): void {
    $hindu = a11Religion();
    $ids = Caste::query()->where('religion_id', $hindu)->orderBy('sort_order')->pluck('id')->map(fn ($v): int => (int) $v)->all();
    $reversed = array_reverse($ids);

    app(ReorderMasterRows::class)->handle(adminWithRole(), a11('castes'), $hindu, $reversed);
    expect(Caste::query()->where('religion_id', $hindu)->orderBy('sort_order')->pluck('id')->map(fn ($v): int => (int) $v)->all())->toBe($reversed);

    $foreign = (int) Caste::query()->where('religion_id', a11Religion('CHRISTIAN'))->value('id');
    foreach ([array_slice($reversed, 1), [...$reversed, $foreign], [...array_slice($reversed, 1), $reversed[1]]] as $tampered) {
        expect(fn () => app(ReorderMasterRows::class)->handle(adminWithRole(), a11('castes'), $hindu, $tampered))->toThrow(ValidationException::class);
    }
    expect(Caste::query()->where('religion_id', $hindu)->orderBy('sort_order')->pluck('id')->map(fn ($v): int => (int) $v)->all())->toBe($reversed);
});

// ---- CSV import / export ------------------------------------------------------------------------------

it('A11 import preview: new / update / same / error per line; apply adds and updates, never deletes or renames', function (): void {
    $admin = adminWithRole();
    $before = App\Models\Masters\Star::query()->count();
    $first = App\Models\Masters\Star::query()->orderBy('sort_order')->firstOrFail();
    $csv = "\xEF\xBB\xBFcode,label,label_ml,sort_order,is_active\n"
        .$first->code.",{$first->label},,,\n"                // unchanged
        .$first->code."_X,Brand new star,,5,yes\n"         // new
        ."bad code,Broken,,,\n";                            // error

    $preview = collect(app(ImportMasterRows::class)->preview($admin, a11('stars'), null, $csv))->keyBy('line');
    expect($preview[2]->status)->toBe(MasterImportRow::SAME)
        ->and($preview[3]->status)->toBe(MasterImportRow::NEW)
        ->and($preview[4]->status)->toBe(MasterImportRow::ERROR);

    expect(fn () => app(ImportMasterRows::class)->apply($admin, a11('stars'), null, $csv))->toThrow(ValidationException::class);
    expect(App\Models\Masters\Star::query()->count())->toBe($before);

    $fixed = "code,label,is_active\n".$first->code.",Renamed first star,no\n".$first->code."_X,Brand new star,yes\n";
    $result = app(ImportMasterRows::class)->apply($admin, a11('stars'), null, $fixed);

    $first->refresh();
    expect($result)->toBe(['added' => 1, 'updated' => 1])
        ->and($first->label)->toBe('Renamed first star')
        ->and($first->is_active)->toBeFalse()
        ->and(App\Models\Masters\Star::query()->count())->toBe($before + 1)   // nothing deleted
        ->and(AuditLog::query()->where('action', 'masters.imported')->count())->toBe(1);
});

it('A11 import refuses a file that isn\'t a usable CSV', function (string $csv): void {
    expect(fn () => app(ImportMasterRows::class)->preview(adminWithRole(), a11('stars'), null, $csv))->toThrow(ValidationException::class);
})->with([
    'empty' => [''],
    'unknown column' => ["code,label,password\nA,B,C\n"],
    'no label column' => ["code\nA\n"],
    'not UTF-8' => ["code,label\nA,\xFF\xFE\n"],
]);

it('A11 export: the list as CSV in the import\'s columns, for masters.view only; audited', function (): void {
    signInAdmin($this, adminWithRole('moderator'));
    $csv = $this->get(adminUrl('/masters/religions/export'))->assertOk()->streamedContent();

    expect($csv)->toContain('code,label,label_ml,sort_order,is_active')->toContain('HINDU')
        ->and(AuditLog::query()->where('action', 'masters.exported')->count())->toBe(1);

    signInAdmin($this, adminWithRole('finance'));
    $this->get(adminUrl('/masters/religions/export'))->assertForbidden();
});

it('A11 export of a scoped list needs an existing parent', function (): void {
    signInAdmin($this, adminWithRole());

    $this->get(adminUrl('/masters/castes/export?parent=999999'))->assertNotFound();
    $this->get(adminUrl('/masters/castes/export?parent='.a11Religion()))->assertOk();
});

// ---- Word lists → moderation pre-flags ------------------------------------------------------------------

it('A11 profanity lists: an added word is flagged at once; a deactivated one no longer is', function (): void {
    $admin = adminWithRole();
    $flags = fn (string $text): array => array_map(fn ($f) => $f->code, app(ModerationFlags::class)->forTexts(['about' => $text]));
    expect($flags('He is such a chorizo'))->not->toContain('profanity');

    $word = app(SaveMasterRow::class)->create($admin, a11('profanity-en'), null, MasterRowData::from('', 'Chorizo'));
    expect($word->label)->toBe('chorizo')->and($word->code)->toStartWith('W')
        ->and($flags('He is such a chorizo'))->toContain('profanity');

    app(SetMasterRowActive::class)->handle($admin, a11('profanity-en'), null, (int) $word->getKey(), false);
    expect($flags('He is such a chorizo'))->not->toContain('profanity');
});

it('the seeded word lists cover English, Malayalam and Manglish', function (): void {
    expect(MasterOption::query()->whereIn('group', ['profanity_en', 'profanity_ml', 'profanity_manglish'])->distinct()->count('group'))->toBe(3);
});
