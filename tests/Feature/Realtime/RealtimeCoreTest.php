<?php

declare(strict_types=1);

use App\Actions\Activity\RecordMemberActivity;
use App\Actions\Admin\Impersonation\EndImpersonation;
use App\Actions\Admin\Members\DeleteMember;
use App\Actions\Admin\Members\ResetMemberPassword;
use App\Actions\Admin\Members\SuspendMember;
use App\Enums\Flag;
use App\Enums\ImpersonationEndReason;
use App\Events\Account\ForceLogout;
use App\Models\FeatureFlag;
use App\Models\ImpersonationSession;
use App\Models\PrivacySetting;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/*
| P3.1 — real-time core: forced logout (`.session.revoked`), the debounced "last seen / last
| active" writer, and the member layout's real-time switches (<meta>).
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
});

function rtMember(string $phone = '+919855500001'): User
{
    $member = memberWithPhone($phone);
    $member->forceFill(['email' => 'rt'.substr($phone, -4).'@example.com', 'email_verified_at' => now()])->save();

    return $member->refresh();
}

it('ForceLogout: suspend, delete and admin password reset sign every open tab out (scope ALL)', function (string $action): void {
    Event::fake([ForceLogout::class]);
    $member = rtMember();
    $admin = adminWithRole();

    match ($action) {
        'suspend' => app(SuspendMember::class)->handle($admin, $member, 'Abuse investigation.'),
        'delete' => app(DeleteMember::class)->handle($admin, $member, $member->profile->code, 'Member asked us to delete it.'),
        'password reset' => app(ResetMemberPassword::class)->handle($admin, $member, 'Member locked out, verified by phone call.'),
    };

    Event::assertDispatched(ForceLogout::class, fn (ForceLogout $e): bool => $e->userId === (string) $member->id && $e->scope === ForceLogout::ALL);
})->with(['suspend', 'delete', 'password reset']);

it('ForceLogout payload: the member\'s own private channel, ".session.revoked", the scope only — no ids or PII', function (): void {
    $event = new ForceLogout('01hzzzzzzzzzzzzzzzzzzzzzzz', ForceLogout::IMPERSONATION);

    expect($event->broadcastOn()[0]->name)->toBe('private-App.Models.User.01hzzzzzzzzzzzzzzzzzzzzzzz')
        ->and($event->broadcastAs())->toBe('session.revoked')
        ->and($event->broadcastWith())->toBe(['scope' => 'IMPERSONATION'])
        ->and($event)->toBeInstanceOf(Illuminate\Contracts\Broadcasting\ShouldBroadcastNow::class);
});

it('P1.7b: ending a support session sends an IMPERSONATION-scoped logout, once', function (): void {
    Event::fake([ForceLogout::class]);
    $member = rtMember();
    $running = ImpersonationSession::factory()->create(['user_id' => $member->id]);

    app(EndImpersonation::class)->handle($running, ImpersonationEndReason::Ended);
    app(EndImpersonation::class)->handle($running, ImpersonationEndReason::Ended);   // already ended

    Event::assertDispatchedTimes(ForceLogout::class, 1);
    Event::assertDispatched(ForceLogout::class, fn (ForceLogout $e): bool => $e->scope === ForceLogout::IMPERSONATION && $e->userId === (string) $member->id);
});

it('PRD §9.6: activity writes last_seen_at and last_active_at at most once per 5 minutes', function (): void {
    Cache::flush();
    $member = rtMember();
    $this->travelTo(now()->startOfMinute());

    app(RecordMemberActivity::class)->handle($member);
    $first = $member->refresh()->last_seen_at;
    expect($first)->not->toBeNull()->and($member->profile->refresh()->last_active_at?->equalTo($first))->toBeTrue();

    $this->travel(2)->minutes();
    app(RecordMemberActivity::class)->handle($member);
    expect($member->refresh()->last_seen_at->equalTo($first))->toBeTrue();   // debounced

    $this->travel(4)->minutes();
    Cache::flush();   // the 5-minute window has passed
    app(RecordMemberActivity::class)->handle($member);
    expect($member->refresh()->last_seen_at->gt($first))->toBeTrue();
});

it('activity is recorded by page requests, never for an admin support session or a non-member', function (): void {
    Cache::flush();
    $this->seed(PlansSeeder::class);
    $member = rtMember();

    $this->actingAs($member, 'web')->get(memberUrl('/me'))->assertOk();
    expect($member->refresh()->last_seen_at)->not->toBeNull();

    Cache::flush();
    $other = rtMember('+919855500002');
    $running = ImpersonationSession::factory()->create(['user_id' => $other->id]);
    session([Impersonation::SESSION_KEY => $running->id]);
    app()->forgetScopedInstances();   // a new request: Impersonation memoises per request
    app()->forgetInstance(Impersonation::class);
    app(RecordMemberActivity::class)->handle($other);
    expect($other->refresh()->last_seen_at)->toBeNull();
});

it('the member layout carries the real-time switches; guests get none (no socket on public pages)', function (): void {
    $this->seed(PlansSeeder::class);
    $member = rtMember();

    $this->get(memberUrl('/'))->assertOk()->assertDontSee('oppam-realtime', false);

    $this->actingAs($member, 'web')->get(memberUrl('/me'))->assertOk()
        ->assertSee('<meta name="oppam-realtime" content="on">', false)
        ->assertSee('<meta name="oppam-user" content="'.$member->id.'">', false)
        ->assertSee('<meta name="oppam-presence" content="on">', false)
        ->assertSee('<meta name="oppam-impersonating" content="off">', false)
        ->assertSee('<meta name="oppam-polling-fallback" content="off">', false)
        ->assertSee('Reconnecting', false);
});

it('presence is switched off for a member who hides their online status; the polling fallback follows its flag', function (): void {
    $this->seed(PlansSeeder::class);
    $member = rtMember();
    (PrivacySetting::query()->whereKey($member->profile->id)->first() ?? (new PrivacySetting)->forceFill(['profile_id' => $member->profile->id]))
        ->forceFill(['show_online_status' => false])->save();
    (FeatureFlag::query()->find(Flag::RealtimePollingFallback->value) ?? (new FeatureFlag)->forceFill(['key' => Flag::RealtimePollingFallback->value]))
        ->forceFill(['is_enabled' => true])->save();
    Cache::flush();

    $this->actingAs($member, 'web')->get(memberUrl('/me'))->assertOk()
        ->assertSee('<meta name="oppam-presence" content="off">', false)
        ->assertSee('<meta name="oppam-polling-fallback" content="on">', false);
});
