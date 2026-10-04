<?php

declare(strict_types=1);

use App\Livewire\Admin\Masters\Index;
use App\Livewire\Admin\Masters\ListEditor;
use App\Models\Masters\Caste;
use App\Models\Masters\Religion;
use App\Models\Masters\Star;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P1.8 — A11 screens: the lists index, the list editor (parent picker, add / edit / activate /
| delete / move, import preview + apply); masters.view to look, masters.edit to change.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
});

function a11As(string $role): void
{
    test()->actingAs(adminWithRole($role), 'admin');
}

it('lists every master list for masters.view and forbids the rest', function (): void {
    a11As('moderator');
    Livewire::test(Index::class)->assertOk()->assertSee('Castes')->assertSee('Profanity — Malayalam')->assertSee('Family values');

    a11As('finance');
    Livewire::test(Index::class)->assertForbidden();
});

it('the editor shows one parent\'s castes at a time; unknown lists 404; the list key can\'t be tampered with', function (): void {
    $hindu = Religion::query()->where('code', 'HINDU')->firstOrFail();
    $christian = Religion::query()->where('code', 'CHRISTIAN')->firstOrFail();
    $hinduCaste = Caste::query()->where('religion_id', $hindu->id)->firstOrFail();
    $christianCaste = Caste::query()->where('religion_id', $christian->id)->whereNotIn('label', Caste::query()->where('religion_id', $hindu->id)->pluck('label'))->firstOrFail();
    a11As('moderator');

    Livewire::withQueryParams(['parent' => (string) $hindu->id])->test(ListEditor::class, ['list' => 'castes'])
        ->assertSee($hinduCaste->label)->assertDontSee($christianCaste->label)
        ->assertDontSee('Add a row')                                    // moderator: view only
        ->set('parent', (string) $christian->id)->assertSee($christianCaste->label);

    Livewire::test(ListEditor::class, ['list' => 'no-such-list'])->assertNotFound();

    expect(fn () => Livewire::test(ListEditor::class, ['list' => 'stars'])->set('listKey', 'religions'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('a moderator can\'t change anything even by calling the actions directly', function (): void {
    $star = Star::query()->firstOrFail();
    a11As('moderator');

    Livewire::test(ListEditor::class, ['list' => 'stars'])
        ->set('newCode', 'X_STAR')->set('newLabel', 'X star')->call('add')->assertForbidden();
    Livewire::test(ListEditor::class, ['list' => 'stars'])->call('toggle', $star->id)->assertForbidden();

    expect($star->refresh()->is_active)->toBeTrue()
        ->and(Star::query()->where('code', 'X_STAR')->exists())->toBeFalse();
});

it('adds, edits, deactivates and reorders rows; errors land on the right field', function (): void {
    a11As('content_editor');
    $component = Livewire::test(ListEditor::class, ['list' => 'stars']);

    $component->set('newCode', 'bad code')->set('newLabel', 'Bad')->call('add')->assertHasErrors(['newCode']);
    $component->set('newCode', 'NEW_STAR')->set('newLabel', 'New star')->set('newLabelMl', 'പുതിയ')->call('add')
        ->assertHasNoErrors()->assertSee('New star')->assertSee('പുതിയ');

    $star = Star::query()->where('code', 'NEW_STAR')->firstOrFail();
    $component->call('edit', $star->id)->set('editLabel', 'Renamed star')->call('saveEdit');
    expect($star->refresh()->label)->toBe('Renamed star');

    $component->call('toggle', $star->id);
    expect($star->refresh()->is_active)->toBeFalse();

    $component->call('move', $star->id, -1);
    $order = Star::query()->orderBy('sort_order')->pluck('id')->map(fn ($v): int => (int) $v)->all();
    expect(array_search($star->id, $order, true))->toBe(count($order) - 2);   // it was last, now second to last
});

it('deleting a row in use shows why instead of failing', function (): void {
    $member = memberWithPhone('+919866600001');
    a11As('super_admin');

    Livewire::test(ListEditor::class, ['list' => 'religions'])
        ->call('delete', (int) $member->profile->religion_id)
        ->assertDispatched('toast', type: 'error');

    expect(Religion::query()->whereKey($member->profile->religion_id)->exists())->toBeTrue();
});

it('imports a CSV through preview then apply', function (): void {
    a11As('content_editor');
    $file = UploadedFile::fake()->createWithContent('stars.csv', "code,label\nIMPORTED_STAR,Imported star\n");

    Livewire::test(ListEditor::class, ['list' => 'stars'])
        ->set('csv', $file)->call('previewImport')
        ->assertSee('IMPORTED_STAR')->assertSee('1 new')
        ->call('applyImport')
        ->assertDispatched('toast', type: 'success');

    expect(Star::query()->where('code', 'IMPORTED_STAR')->exists())->toBeTrue();
});

it('P1.8 review Blocker: the districts editor offers states (with their country) and shows that state\'s districts', function (): void {
    $kerala = App\Models\Masters\State::query()->where('code', 'KL')->firstOrFail();
    a11As('content_editor');

    Livewire::withQueryParams(['parent' => (string) $kerala->id])->test(ListEditor::class, ['list' => 'districts'])
        ->assertSee('Kerala (India)')
        ->assertSee('Ernakulam')
        ->set('newCode', 'NEW_DISTRICT')->set('newLabel', 'New district')->call('add')
        ->assertHasNoErrors()->assertSee('New district');

    expect(App\Models\Masters\District::query()->where('code', 'NEW_DISTRICT')->value('state_id'))->toBe($kerala->id);
});

it('choosing another CSV file throws away the old preview (apply always follows a preview of that file)', function (): void {
    a11As('content_editor');

    Livewire::test(ListEditor::class, ['list' => 'stars'])
        ->set('csv', UploadedFile::fake()->createWithContent('a.csv', "code,label\nA_STAR,A star\n"))->call('previewImport')
        ->assertSee('A_STAR')
        ->set('csv', UploadedFile::fake()->createWithContent('b.csv', "code,label\nB_STAR,B star\n"))
        ->assertSet('preview', []);
});

it('the income bands editor has no Add form', function (): void {
    a11As('content_editor');

    Livewire::test(ListEditor::class, ['list' => 'income-bands'])->assertOk()->assertDontSee('Add a row');
});

it('master data pages send guests to the admin sign-in and serve masters.view admins', function (): void {
    $this->get(adminUrl('/masters'))->assertRedirect(route('admin.login'));

    signInAdmin($this, adminWithRole('moderator'));
    $this->get(adminUrl('/masters'))->assertOk();
    $this->get(adminUrl('/masters/districts'))->assertOk();
    $this->get(adminUrl('/masters/profanity-ml'))->assertOk();
});
