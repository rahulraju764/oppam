<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Livewire\Admin\Content\ContentIndex;
use App\Livewire\Member\Settings\HomeVisibilityToggle;
use App\Models\Branch;
use App\Models\FaqItem;
use App\Models\HomeBlock;
use App\Models\PrivacySetting;
use App\Models\SuccessStory;
use Database\Seeders\ContentSeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/* A08 screens (P8.1a): every type renders; permissions, tampering, workflow buttons, validation. */

beforeEach(function (): void {
    seedAdminRoles();
    $this->admin = adminWithRole('content_editor');
    $this->actingAs($this->admin, 'admin');
});

function adminHolding(string ...$permissions): App\Models\AdminUser
{
    $admin = adminWithRole('content_editor');
    $role = Role::query()->create(['name' => 'screens-'.uniqid(), 'guard_name' => 'admin']);
    $role->syncPermissions(array_map(fn (string $p) => Permission::findByName($p, 'admin'), $permissions));
    $admin->syncRoles([$role]);

    return $admin->refresh();
}

it('A08: every content type has a screen, and an empty list explains itself', function (ContentType $type): void {
    Livewire::test(ContentIndex::class, ['type' => $type->value])
        ->assertOk()->assertSee($type->label())->assertSee('Nothing here yet');
})->with(ContentType::cases());

it('A08: routes open with the permission, and are refused without it', function (ContentType $type): void {
    $url = adminUrl('/content/'.$type->value);

    signInAdmin($this, adminHolding('content.publish'));
    $this->get($url)->assertOk();

    signInAdmin($this, adminHolding('content.edit'));
    $this->get($url)->assertOk();

    signInAdmin($this, adminWithRole('support'));
    $this->get($url)->assertForbidden();
})->with(ContentType::cases());

it('A08: guests are sent to the admin login', function (): void {
    auth('admin')->logout();
    $this->get(adminUrl('/content/stories'))->assertRedirect(route('admin.login'));
});

it('A08: an unknown type is a 404', function (): void {
    Livewire::test(ContentIndex::class, ['type' => 'nope'])->assertNotFound();
});

it('A08: the type and the row being edited can’t be tampered with', function (string $prop, string|int $value): void {
    Livewire::test(ContentIndex::class, ['type' => 'branches'])->set($prop, $value);
})->throws(CannotUpdateLockedPropertyException::class)
    ->with([['type', 'stories'], ['editing', 'X'], ['acting', 'X'], ['previewUrl', 'https://evil.example'], ['editingVersion', 99], ['actingVersion', 99]]);

it('A08: a code from another type or nowhere is a 404, nothing changes', function (string $action): void {
    $story = SuccessStory::factory()->inReview()->create();

    Livewire::test(ContentIndex::class, ['type' => 'branches'])->call($action, $story->code)->assertNotFound();

    expect($story->refresh()->status)->toBe(ContentStatus::InReview);
})->with(['edit', 'publish', 'submit', 'preview']);

it('A08: creates a row through the dialog, in draft', function (): void {
    Livewire::test(ContentIndex::class, ['type' => 'branches'])
        ->call('create')->assertDispatched('open-modal', name: 'content-form')
        ->set('fields', ['sort_order' => 2, 'city' => 'Kannur', 'role' => 'Branch', 'address' => 'Fort Road', 'phone' => '0497', 'tel' => '+914972701100', 'hours' => '10–6'])
        ->call('save')->assertHasNoErrors()->assertDispatched('toast')
        ->assertSee('Kannur');

    expect(Branch::query()->sole()->status)->toBe(ContentStatus::Draft);
});

it('A08: validation errors land on the field, nothing is written', function (): void {
    Livewire::test(ContentIndex::class, ['type' => 'branches'])
        ->call('create')->set('fields', ['sort_order' => 0, 'city' => '', 'tel' => 'abc'])
        ->call('save')->assertHasErrors(['fields.city', 'fields.tel']);

    expect(Branch::query()->count())->toBe(0);
});

it('A08: editing loads the waiting text of a live row, and the save message says so', function (): void {
    $branch = Branch::factory()->published()->create(['city' => 'Live', 'draft' => ['city' => 'Waiting']]);

    Livewire::test(ContentIndex::class, ['type' => 'branches'])
        ->call('edit', $branch->code)->assertSet('fields.city', 'Waiting')
        ->call('save')->assertHasNoErrors()
        ->assertSee('Changes waiting');
});

it('A08: home blocks load their fields, and can’t be added', function (): void {
    $this->seed(ContentSeeder::class);
    $block = HomeBlock::query()->where('block_key', 'plans')->sole();

    Livewire::test(ContentIndex::class, ['type' => 'home-blocks'])
        ->assertDontSeeHtml('wire:click="create"')
        ->call('edit', $block->code)->assertSet('fields.content.heading', $block->content['heading'])->assertSee('Heading')
        ->set('fields.content.heading', 'New heading')->call('save')->assertHasNoErrors();

    expect($block->refresh()->draft['content']['heading'])->toBe('New heading');

    Livewire::test(ContentIndex::class, ['type' => 'home-blocks'])->call('create')->assertNotFound();
});

it('A08: workflow buttons follow the permission, and the action refuses anyway', function (): void {
    $row = FaqItem::factory()->inReview()->create();

    $editor = adminHolding('content.edit');
    $this->actingAs($editor, 'admin');
    Livewire::test(ContentIndex::class, ['type' => 'faq'])
        ->assertDontSee('Publish now')->assertSee('Back to draft')
        ->call('publish', $row->code)->assertForbidden();
    expect($row->refresh()->status)->toBe(ContentStatus::InReview);

    $publisher = adminHolding('content.publish');
    $this->actingAs($publisher, 'admin');
    Livewire::test(ContentIndex::class, ['type' => 'faq'])
        ->assertSee('Publish now')->assertDontSee('>Edit<', false)
        ->call('publish', $row->code)->assertHasNoErrors();
    expect($row->refresh()->status)->toBe(ContentStatus::Published);
});

it('A08: publish without consent shows why, as a toast', function (): void {
    $story = SuccessStory::factory()->inReview()->create(['consent_a_at' => null]);

    Livewire::test(ContentIndex::class, ['type' => 'stories'])
        ->assertSee('Consent missing')
        ->call('publish', $story->code)->assertDispatched('toast', type: 'danger');

    expect($story->refresh()->status)->toBe(ContentStatus::InReview);
});

it('A08: archive and withdraw ask for a reason in their dialogs', function (): void {
    $story = SuccessStory::factory()->published()->create();
    $screen = Livewire::test(ContentIndex::class, ['type' => 'stories']);

    $screen->call('confirm', $story->code, 'content-withdraw')->assertDispatched('open-modal', name: 'content-withdraw')
        ->set('reason', 'no')->call('withdraw')->assertHasErrors('reason');
    expect($story->refresh()->isWithdrawn())->toBeFalse();

    $screen->set('reason', 'Partner asked to remove it')->call('withdraw')->assertHasNoErrors();
    expect($story->refresh()->isWithdrawn())->toBeTrue();
});

it('A08: a dialog name from the browser is an allow-list', function (): void {
    $row = FaqItem::factory()->create();

    Livewire::test(ContentIndex::class, ['type' => 'faq'])->call('confirm', $row->code, 'anything')->assertNotFound();
});

it('A08: schedule refuses a bad date on the field and a past one with a toast', function (): void {
    $row = FaqItem::factory()->inReview()->create();
    $screen = Livewire::test(ContentIndex::class, ['type' => 'faq'])->call('confirm', $row->code, 'content-schedule');

    $screen->set('scheduleAt', 'not a date')->call('schedule')->assertHasErrors('scheduleAt');
    $screen->set('scheduleAt', '2001-01-01T10:00')->call('schedule')->assertDispatched('toast', type: 'danger');
    expect($row->refresh()->status)->toBe(ContentStatus::InReview);

    $screen->set('scheduleAt', now()->addDay()->format('Y-m-d\TH:i'))->call('schedule')->assertHasNoErrors();
    expect($row->refresh()->status)->toBe(ContentStatus::Scheduled);
});

it('A08: the status filter narrows the list and ignores a tampered value', function (): void {
    FaqItem::factory()->published()->create(['question' => 'Live one']);
    FaqItem::factory()->create(['question' => 'Draft one']);

    Livewire::test(ContentIndex::class, ['type' => 'faq'])
        ->set('statusFilter', 'PUBLISHED')->assertSee('Live one')->assertDontSee('Draft one')
        ->set('statusFilter', "x' OR 1=1")->assertSee('Live one')->assertSee('Draft one');
});

it('A08: loading state is on the save button; the preview link opens in a dialog', function (): void {
    $row = FaqItem::factory()->create();

    Livewire::test(ContentIndex::class, ['type' => 'faq'])
        ->assertSeeHtml('wire:loading')
        ->call('preview', $row->code)->assertDispatched('open-modal', name: 'content-preview')->assertSee('Open preview');
});

it('the member toggle: off by default, saves the choice, and explains who sees it', function (): void {
    seedMasters();
    $user = contentMember();
    $this->actingAs($user, 'web');

    Livewire::test(HomeVisibilityToggle::class)
        ->assertSet('show', false)->assertSee('Anyone visiting Oppam')->assertSee('You’ll only appear while')
        ->set('show', true)->assertDispatched('toast', type: 'success');

    expect(PrivacySetting::query()->find($user->profile->id)?->show_on_home)->toBeTrue();
});

it('the member toggle: a guest gets a 404', function (): void {
    auth('web')->logout();
    Livewire::test(HomeVisibilityToggle::class)->assertNotFound();
});

it('A08 review: a dialog left open when a story is withdrawn can’t bring it back', function (): void {
    $story = SuccessStory::factory()->published()->create(['consent_note' => 'Signed forms 2024']);
    $screen = Livewire::test(ContentIndex::class, ['type' => 'stories'])->call('edit', $story->code);

    app(App\Actions\Admin\Content\WithdrawStory::class)->handle($this->admin, $story, 'Partner asked to remove it');

    $screen->call('save')->assertDispatched('toast', type: 'danger');
    expect($story->refresh()->hasBothConsents())->toBeFalse()->and($story->isWithdrawn())->toBeTrue();
});

it('A08 review: Publish buttons carry the version on screen, and a stale click is refused', function (): void {
    $row = FaqItem::factory()->inReview()->create();

    Livewire::test(ContentIndex::class, ['type' => 'faq'])
        ->assertSeeHtml("publish('{$row->code}', {$row->lock_version})")
        ->call('publish', $row->code, $row->lock_version - 1)->assertDispatched('toast', type: 'danger');

    expect($row->refresh()->status)->toBe(ContentStatus::InReview);
});

it('A08 review: a withdrawn story offers no preview; block fields have readable labels', function (): void {
    $story = SuccessStory::factory()->archived()->create(['withdrawn_at' => now()]);
    Livewire::test(ContentIndex::class, ['type' => 'stories'])->assertDontSeeHtml("preview('{$story->code}')");

    expect(App\Enums\HomeBlockKey::fieldLabel('step1_title'))->toBe('Step 1 title');
});

it('A08 review: a suspended admin is refused', function (): void {
    $admin = adminWithRole('content_editor');
    signInAdmin($this, $admin);
    $admin->forceFill(['status' => App\Enums\AdminStatus::Suspended])->save();

    $response = $this->get(adminUrl('/content/stories'));

    expect($response->status())->toBeIn([302, 403]);
    $response->assertDontSee('Success stories');
});

it('the member toggle: refused in a support session, and the box goes back', function (): void {
    seedMasters();
    $user = contentMember();
    $this->actingAs($user, 'web');
    chatImpersonate($user);

    Livewire::test(HomeVisibilityToggle::class)->set('show', true)->assertDispatched('toast', type: 'danger')->assertSet('show', false);

    expect(PrivacySetting::query()->find($user->profile->id)?->show_on_home ?? false)->toBeFalse();
});

it('A08 review: Schedule carries the version on screen too, and a withdrawn story offers no Edit', function (): void {
    $row = FaqItem::factory()->inReview()->create();
    $story = SuccessStory::factory()->archived()->create(['withdrawn_at' => now()]);
    $seen = $row->lock_version;

    Livewire::test(ContentIndex::class, ['type' => 'faq'])->assertSeeHtml("confirm('{$row->code}', 'content-schedule', {$seen})");
    Livewire::test(ContentIndex::class, ['type' => 'stories'])->assertDontSeeHtml("edit('{$story->code}')");

    $screen = Livewire::test(ContentIndex::class, ['type' => 'faq'])->call('confirm', $row->code, 'content-schedule', $seen);
    $row->forceFill(['question' => 'Changed behind the list'])->save();

    $screen->set('scheduleAt', now()->addDay()->format('Y-m-d\TH:i'))->call('schedule')->assertDispatched('toast', type: 'danger');
    expect($row->refresh()->status)->toBe(ContentStatus::InReview);
});
