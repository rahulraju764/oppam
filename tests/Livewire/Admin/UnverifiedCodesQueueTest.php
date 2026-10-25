<?php

declare(strict_types=1);

use App\Actions\Admin\Brokers\ClearReferralCode;
use App\Actions\Admin\Brokers\LinkReferralCode;
use App\Enums\BrokerStatus;
use App\Enums\ReferralStatus;
use App\Livewire\Admin\Brokers\UnverifiedCodesQueue;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\BrokerReferral;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| P7.2 — A07 "Unverified codes" (PRD §11A B.12, R-M13-3): codes that matched no active bureau,
| fuzzy suggestions (Levenshtein ≤ 2 over ACTIVE codes, never auto-linked), link or clear with a
| reason, `brokers.edit`, audited.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Notification::fake();
});

function mistyped(string $raw, string $e164 = '+919811100401'): User
{
    $member = memberWithPhone($e164);
    $member->profile->forceFill(['broker_code_raw' => $raw, 'broker_verified' => false, 'broker_id' => null])->save();

    return $member->refresh();
}

it('lists unverified codes of proved members with suggestions within distance 2 of ACTIVE codes only', function (): void {
    Broker::factory()->create(['code' => 'BRK1042']);
    Broker::factory()->create(['code' => 'BRK1043']);
    Broker::factory()->create(['code' => 'BRK1044', 'status' => BrokerStatus::Suspended]);
    Broker::factory()->create(['code' => 'BRK9999']);
    $typo = mistyped('BKR1042');                                  // distance 2 from BRK1042, 3 from BRK1043
    $unproved = mistyped('BRK1000', '+919811100402');
    $unproved->forceFill(['phone_verified_at' => null])->save();
    $this->actingAs(adminWithRole('super_admin'), 'admin');

    Livewire::test(UnverifiedCodesQueue::class)
        ->assertSee($typo->profile->code)->assertSee('BKR1042')
        ->assertDontSee($unproved->profile->code)
        ->call('select', $typo->profile->code)
        ->assertSee('BRK1042')
        ->assertDontSee('BRK1044')     // suspended: never suggested
        ->assertDontSee('BRK9999');
});

it('link: attribution set, PENDING ledger row opened, audited with the raw code and reason', function (): void {
    $broker = Broker::factory()->create(['code' => 'BRK1042']);
    $member = mistyped('BRK1024');
    $this->actingAs(adminWithRole('super_admin'), 'admin');

    Livewire::test(UnverifiedCodesQueue::class)
        ->call('select', $member->profile->code)
        ->set('brokerCode', 'brk 1042')
        ->set('reason', 'Member confirmed on the phone')
        ->call('link')
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertDontSee($member->profile->code);

    $profile = $member->profile->refresh();
    $audit = AuditLog::query()->where('action', 'brokers.referral_linked')->sole();
    expect($profile->broker_id)->toBe($broker->id)->and($profile->broker_verified)->toBeTrue()
        ->and(BrokerReferral::query()->sole()->status)->toBe(ReferralStatus::Pending)
        ->and($audit->reason)->toBe('Member confirmed on the phone')
        ->and($audit->before['broker_code_raw'])->toBe('BRK1024');
});

it('link: a member who is live and already paid earns the commission at once', function (): void {
    $broker = Broker::factory()->create();
    $member = mistyped('BRK0001');
    paidOrder($member);

    app(LinkReferralCode::class)->handle(adminWithRole('super_admin'), $member->profile, $broker->code, 'Typo of the bureau code');

    expect(ledgerRow($member)->status)->toBe(ReferralStatus::Earned)->and(ledgerRow($member)->commission_paise)->toBe(9_990);
});

it('clear: the raw code is removed from the profile and kept in the audit row', function (): void {
    $member = mistyped('HELLO');
    $this->actingAs(adminWithRole('super_admin'), 'admin');

    Livewire::test(UnverifiedCodesQueue::class)
        ->call('select', $member->profile->code)
        ->set('reason', 'Not a bureau code')
        ->call('clear')
        ->assertHasNoErrors();

    expect($member->profile->refresh()->broker_code_raw)->toBeNull()
        ->and(AuditLog::query()->where('action', 'brokers.referral_cleared')->sole()->before['broker_code_raw'])->toBe('HELLO');
});

it('validation: a reason is required, the bureau must be ACTIVE, nothing written', function (): void {
    Broker::factory()->create(['code' => 'BRK2000', 'status' => BrokerStatus::Suspended]);
    $member = mistyped('BRK2000');
    $this->actingAs(adminWithRole('super_admin'), 'admin');

    Livewire::test(UnverifiedCodesQueue::class)
        ->call('select', $member->profile->code)
        ->set('brokerCode', 'BRK2000')->set('reason', 'x')
        ->call('link')->assertHasErrors(['reason'])
        ->set('reason', 'Suspended bureau, still try')
        ->call('link')->assertHasErrors(['brokerCode'])
        ->set('brokerCode', 'BRK7777')
        ->call('link')->assertHasErrors(['brokerCode']);

    expect($member->profile->refresh()->broker_id)->toBeNull()->and(BrokerReferral::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'like', 'brokers.referral_%')->count())->toBe(0);
});

it('a row already decided (by another admin) is refused: no second link, no second ledger row', function (): void {
    $broker = Broker::factory()->create();
    $member = mistyped('BRK0002');
    $admin = adminWithRole('super_admin');
    app(LinkReferralCode::class)->handle($admin, $member->profile, $broker->code, 'First decision');

    expect(fn () => app(LinkReferralCode::class)->handle($admin, $member->profile, $broker->code, 'Second decision'))->toThrow(ValidationException::class)
        ->and(fn () => app(ClearReferralCode::class)->handle($admin, $member->profile, 'Late clear'))->toThrow(ValidationException::class);
    expect(BrokerReferral::query()->count())->toBe(1)->and($member->profile->refresh()->broker_id)->toBe($broker->id);
});

it('security: brokers.edit is required — page and Actions', function (string $role): void {
    $member = mistyped('BRK0003');
    $admin = adminWithRole($role);
    signInAdmin($this, $admin);

    Livewire::test(UnverifiedCodesQueue::class)->assertForbidden();
    $this->get(adminUrl('/brokers/unverified-codes'))->assertForbidden();
    expect(fn () => app(ClearReferralCode::class)->handle($admin, $member->profile, 'Not allowed here'))->toThrow(AuthorizationException::class);
    expect($member->profile->refresh()->broker_code_raw)->toBe('BRK0003');
})->with(['support', 'finance', 'moderator']);

it('a tampered ?profile= code that is not in the queue opens nothing and decides nothing (404)', function (): void {
    $verified = memberWithPhone('+919811100499');
    $this->actingAs(adminWithRole('super_admin'), 'admin');

    Livewire::withQueryParams(['profile' => $verified->profile->code])->test(UnverifiedCodesQueue::class)
        ->assertDontSee('Link to a bureau')
        ->set('reason', 'Trying a profile that is not waiting')
        ->call('clear')
        ->assertNotFound();
});

it('empty state; the page is reachable for brokers.edit and in the sidebar', function (): void {
    signInAdmin($this, adminWithRole('super_admin'));

    $this->get(adminUrl('/brokers/unverified-codes'))->assertOk()
        ->assertSee('No codes are waiting for review.')
        ->assertSeeHtml('href="'.route('admin.brokers.unverified').'"');
});
