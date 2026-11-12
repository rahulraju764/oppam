<?php

declare(strict_types=1);

use App\Enums\JourneyKey;
use App\Livewire\Admin\Comms\Journeys;
use App\Models\AutomatedJourney;
use App\Models\JourneySend;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P8.3b2 — the A14 Journeys screen: lists the four journeys + the built-in plan-expiry reminders,
| writes nothing on a page load, edits through a reason dialog (campaigns.send), refuses tampered
| keys and invalid input.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    $this->actingAs(adminWithRole('super_admin'), 'admin');
});

it('A14 journeys screen: renders every journey, the built-in plan-expiry row and 30-day numbers — and writes nothing', function (): void {
    $member = campaignMember();
    JourneySend::query()->insert([
        ['journey_key' => 'win_back', 'user_id' => $member->id, 'step' => 30, 'subject_ref' => 'a', 'sent_on' => now()->toDateString(), 'status' => 'SENT', 'created_at' => now()],
        ['journey_key' => 'win_back', 'user_id' => $member->id, 'step' => 30, 'subject_ref' => 'b', 'sent_on' => now()->toDateString(), 'status' => 'SKIPPED_OPTED_OUT', 'created_at' => now()],
    ]);
    $before = AutomatedJourney::query()->orderBy('key')->get()->toArray();

    DB::enableQueryLog();
    Livewire::test(Journeys::class)->assertOk()
        ->assertSee('Automated journeys')
        ->assertSee(JourneyKey::WizardIncomplete->label())->assertSee(JourneyKey::PendingInterest->label())
        ->assertSee(JourneyKey::InactiveMatches->label())->assertSee(JourneyKey::WinBack->label())
        ->assertSee('Plan expiry reminders')->assertSee('Built in')
        ->assertSee('1, 3, 7')->assertSee('Sent 1')->assertSee('Skipped 1')
        ->assertSeeHtml('dusk="journey-edit-wizard_incomplete"')
        ->assertSeeHtml('wire:loading');
    $writes = collect(DB::getQueryLog())->filter(fn (array $q): bool => preg_match('/^\s*(insert|update|delete)/i', $q['query']) === 1);

    expect($writes->filter(fn (array $q): bool => str_contains($q['query'], 'automated_journeys') || str_contains($q['query'], 'journey_sends')))->toBeEmpty()
        ->and(AutomatedJourney::query()->orderBy('key')->get()->toArray())->toBe($before);
});

it('A14 journeys screen: edit → change offsets, channel and switch on with a reason; audited', function (): void {
    Livewire::test(Journeys::class)
        ->call('edit', 'pending_interest')
        ->assertSet('editing', 'pending_interest')->assertSet('offsets', [3, '', ''])->assertSet('channel', 'in_app')
        ->assertDispatched('open-modal', name: 'journey-edit')
        ->set('isActive', true)->set('offsets', ['4', '', '2'])->set('channel', 'email')->set('reason', 'Earlier nudge')
        ->call('save')
        ->assertHasNoErrors()->assertDispatched('close-modal', name: 'journey-edit')->assertDispatched('toast');

    expect(AutomatedJourney::query()->where('key', 'pending_interest')->sole())
        ->is_active->toBeTrue()->offsets->toBe([2, 4])->channel->value->toBe('email');
    expect(App\Models\AuditLog::query()->where('action', 'comms.journey_updated')->sole()->reason)->toBe('Earlier nudge');
});

it('A14 journeys screen: invalid input shows errors and changes nothing', function (array $set, string $error): void {
    $component = Livewire::test(Journeys::class)->call('edit', 'wizard_incomplete');
    foreach ($set as $property => $value) {
        $component->set($property, $value);
    }
    $component->call('save')->assertHasErrors([$error]);

    expect(AutomatedJourney::query()->where('key', 'wizard_incomplete')->sole())
        ->is_active->toBeFalse()->offsets->toBe([1, 3, 7]);
})->with([
    'no reason' => [['reason' => '', 'isActive' => true], 'reason'],
    'all days empty' => [['offsets' => ['', '', ''], 'reason' => 'A fine reason'], 'offsets'],
    'day 400' => [['offsets' => ['400', '', ''], 'reason' => 'A fine reason'], 'offsets.0'],
    'unknown channel' => [['channel' => 'push', 'reason' => 'A fine reason'], 'channel'],
    'tampered offsets (null + array) → errors, not a 500' => [['offsets' => [null, ['x'], ''], 'reason' => 'A fine reason'], 'offsets.0'],
]);

it('A14 journeys screen security: campaigns.view sees but can\'t change; no permission → 403; unknown or tampered key refused', function (): void {
    $this->actingAs(adminWithRole('content_editor'), 'admin');   // campaigns.view only
    Livewire::test(Journeys::class)->assertOk()->assertDontSeeHtml('dusk="journey-edit-wizard_incomplete"')
        ->call('edit', 'wizard_incomplete')->assertForbidden();

    $this->actingAs(adminWithRole('support'), 'admin');          // nothing in Communication
    Livewire::test(Journeys::class)->assertForbidden();

    $this->actingAs(adminWithRole('super_admin'), 'admin');
    Livewire::test(Journeys::class)->call('edit', 'plan_expiry')->assertNotFound();
    Livewire::test(Journeys::class)->call('save')->assertNotFound();   // nothing opened
    expect(fn () => Livewire::test(Journeys::class)->call('edit', 'wizard_incomplete')->set('editing', 'win_back'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
