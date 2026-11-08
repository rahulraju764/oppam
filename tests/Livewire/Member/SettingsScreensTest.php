<?php

declare(strict_types=1);

use App\Enums\NotificationEvent;
use App\Enums\PhotoVisibility;
use App\Enums\PlanCode;
use App\Enums\SettingKey;
use App\Livewire\Member\Settings\NotificationSettings;
use App\Livewire\Member\Settings\PrivacySettings;
use App\Livewire\Member\Settings\Settings;
use App\Models\NotificationPreference;
use App\Models\PrivacySetting;
use App\Models\Setting;
use Database\Seeders\PlansSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
| P8.2a — /settings screens (PRD M14): the page and its tabs, the Privacy and Notifications forms
| (render, save, validation, refusals shown as toasts, loading markup), the Blocked tab inside the
| page, the moved home-page switch, and the links into the page.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
});

// ---- The page ------------------------------------------------------------------------------------

it('/settings: guests go to sign in; a member sees the page with its tabs', function (): void {
    $this->get(memberUrl('/settings'))->assertRedirect(memberUrl('/login'));

    $this->actingAs(groom(), 'web')->get(memberUrl('/settings'))->assertOk()
        ->assertSee('Settings')->assertSee('Privacy')->assertSee('Account')->assertSee('Notifications')->assertSee('Blocked &amp; ignored', false)->assertSee('Hide / delete');
});

it('/settings/blocked still works: it opens the Blocked & ignored tab', function (): void {
    $this->actingAs(groom(), 'web')->get(memberUrl('/settings/blocked'))->assertRedirect(memberUrl('/settings?tab=blocked'));
});

it('tabs: switching changes ?tab=, an unknown tab falls back to Privacy', function (): void {
    $this->actingAs(groom(), 'web');

    Livewire::test(Settings::class)->assertSet('tab', 'privacy')
        ->call('show', 'notifications')->assertSet('tab', 'notifications')
        ->call('show', 'blocked')->assertSet('tab', 'blocked')
        ->call('show', 'account')->assertSet('tab', 'account')            // P8.2b
        ->call('show', 'close')->assertSet('tab', 'privacy')              // not built yet (P8.2c): no tab
        ->call('show', '<script>')->assertSet('tab', 'privacy');

    Livewire::withQueryParams(['tab' => 'evil'])->test(Settings::class)->assertSet('tab', 'privacy');
    Livewire::withQueryParams(['tab' => 'notifications'])->test(Settings::class)
        ->assertSeeHtml('aria-pressed="true"')->assertSeeHtml('member.settings.notification-settings');
});

it('the tabs load lazily, behind a skeleton', function (): void {
    $this->actingAs(groom(), 'web');

    Livewire::test(PrivacySettings::class)->assertSeeHtml('ui-skeleton');
});

// ---- Privacy -----------------------------------------------------------------------------------------

it('Privacy: renders the stored choices with a loading Save button and the home-page switch', function (): void {
    $bride = bride();
    $this->actingAs($bride, 'web');
    Model::preventLazyLoading();

    Livewire::withoutLazyLoading()->test(PrivacySettings::class)
        ->assertSet('photoVisibility', PhotoVisibility::AllMembers->value)->assertSet('readReceipts', true)
        ->assertSee('Hide me from search for now')->assertSee('Browse incognito')->assertSee('Included with Diamond.')
        ->assertSeeHtml('wire:target="save"')->assertSeeHtml('member.settings.home-visibility-toggle');
});

it('Privacy: Save stores the choices and confirms; switching online status off tells the tab to leave the roster', function (): void {
    $bride = bride();
    $this->actingAs($bride, 'web');

    Livewire::withoutLazyLoading()->test(PrivacySettings::class)
        ->set('photoVisibility', PhotoVisibility::AcceptedOnly->value)
        ->set('showOnlineStatus', false)
        ->set('contactFilter', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('presence-hidden')
        ->assertDispatched('toast', type: 'success');

    $row = PrivacySetting::query()->findOrFail($bride->profile->id);
    expect($row->photo_visibility)->toBe(PhotoVisibility::AcceptedOnly)
        ->and($row->show_online_status)->toBeFalse()->and($row->contact_filter_enabled)->toBeTrue();
});

it('Privacy: an unknown visibility value is a validation error and nothing is saved', function (): void {
    $bride = bride();
    $this->actingAs($bride, 'web');

    Livewire::withoutLazyLoading()->test(PrivacySettings::class)
        ->set('photoVisibility', 'EVERYONE_ON_EARTH')->set('hideFromSearch', true)
        ->call('save')
        ->assertHasErrors(['photoVisibility'])->assertNotDispatched('toast');

    expect(PrivacySetting::query()->find($bride->profile->id)?->hide_from_search ?? false)->toBeFalse();
});

it('Privacy: incognito below Diamond is refused with a message; on Diamond it saves without the upgrade note', function (): void {
    $groom = groom();
    $this->actingAs($groom, 'web');

    Livewire::withoutLazyLoading()->test(PrivacySettings::class)->set('incognito', true)->call('save')
        ->assertDispatched('toast', type: 'danger', title: 'Incognito browsing comes with the Diamond plan.');
    expect(PrivacySetting::query()->find($groom->profile->id)?->incognito ?? false)->toBeFalse();

    chatOnPlan($groom, PlanCode::Diamond);
    Livewire::withoutLazyLoading()->test(PrivacySettings::class)->assertDontSee('Included with Diamond.')
        ->set('incognito', true)->call('save')->assertDispatched('toast', type: 'success');
    expect(PrivacySetting::query()->findOrFail($groom->profile->id)->incognito)->toBeTrue();
});

it('Privacy: past the hourly limit a looser save shows the rate-limit message', function (): void {
    Setting::factory()->keyed(SettingKey::MembersSettingsChangesPerHour, 5)->create();
    $bride = bride();
    $this->actingAs($bride, 'web');
    $page = Livewire::withoutLazyLoading()->test(PrivacySettings::class);

    for ($i = 0; $i < 5; $i++) {
        $page->set('showLastSeen', false)->call('save')->set('showLastSeen', true)->call('save');
    }
    $page->set('showLastSeen', false)->call('save')->assertDispatched('toast', type: 'success');
    $page->set('showLastSeen', true)->call('save')->assertDispatched('toast', type: 'danger');
});

it('My Profile no longer carries the home-page switch (it moved to the Privacy tab)', function (): void {
    $this->actingAs(bride(), 'web')->get(memberUrl('/me'))->assertOk()->assertDontSee('Show me on the home page');
});

// ---- Notifications --------------------------------------------------------------------------------

it('Notifications: renders the member events with fixed cells for mandatory events and unused channels', function (): void {
    $member = groom();
    $member->forceFill(['email' => null, 'email_verified_at' => null])->save();
    $this->actingAs($member->refresh(), 'web');

    Livewire::withoutLazyLoading()->test(NotificationSettings::class)
        ->assertSee(NotificationEvent::InterestReceived->label())->assertSee(NotificationEvent::ProfileApproved->label())
        ->assertDontSee(NotificationEvent::PayoutPaid->label())
        ->assertSee('always sent')->assertSee('not used for this update')
        ->assertSee('we only write to a verified email address')
        ->assertSeeHtml('wire:model="matrix.interest_received.sms"')
        ->assertDontSeeHtml('wire:model="matrix.profile_approved.in_app"')   // mandatory: shown, not editable
        ->assertDontSeeHtml('wire:model="matrix.profile_liked.email"')       // never emailed one by one
        ->assertSeeHtml('wire:target="save"')
        ->assertSee('between 22:00 and 07:00');   // A15 quiet hours, shown, not editable here
});

it('Notifications: Save stores the matrix; a tampered mandatory row is ignored', function (): void {
    $member = groom();
    $this->actingAs($member, 'web');

    Livewire::withoutLazyLoading()->test(NotificationSettings::class)
        ->set('matrix.interest_received.sms', false)
        ->set('matrix.profile_approved.in_app', false)   // not on the page — posted anyway
        ->call('save')
        ->assertHasNoErrors()->assertDispatched('toast', type: 'success')
        ->assertSet('matrix.profile_approved.in_app', true);

    expect(NotificationPreference::query()->where('user_id', $member->id)->where('event', 'interest_received')->sole()->sms)->toBeFalse()
        ->and(NotificationPreference::query()->where('user_id', $member->id)->where('event', 'profile_approved')->exists())->toBeFalse();
});

it('Notifications: a tampered matrix row that isn\'t a list of channels is a validation error', function (): void {
    $member = groom();
    $this->actingAs($member, 'web');

    Livewire::withoutLazyLoading()->test(NotificationSettings::class)
        ->set('matrix.interest_received', 'off')->call('save')
        ->assertHasErrors(['matrix.interest_received'])->assertNotDispatched('toast');
    expect(NotificationPreference::query()->count())->toBe(0);
});

it('links: /notifications and every notification email point at the Notifications tab', function (): void {
    $member = groom();
    $this->actingAs($member, 'web');

    $this->get(memberUrl('/notifications'))->assertOk()->assertSee(route('member.settings', ['tab' => 'notifications']), false);
    $mail = (new App\Notifications\Member\DailyMatchesReady(2))->toMail($member);
    expect(implode(' ', array_map(strval(...), [...$mail->introLines, ...$mail->outroLines])))
        ->toContain(route('member.settings', ['tab' => 'notifications']));
});
