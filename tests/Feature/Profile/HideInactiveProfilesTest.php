<?php

declare(strict_types=1);

use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Jobs\Profile\HideInactiveProfiles;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\Profile;
use App\Notifications\Account\ProfileHiddenForInactivity;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/*
| P8.4 — A15 "profile auto-hide after inactivity (180 d)": a member's own ACTIVE profile not active
| for `profile.auto_hide_inactive_days` is hidden (reversible — the earlier status is kept), audited
| as the system, and the member is told why. Never a bureau-run profile.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    Notification::fake();
});

function runAutoHide(): void
{
    app()->call([new HideInactiveProfiles, 'handle']);
}

it('A15 auto-hide: inactive past the setting → HIDDEN (unhide restores ACTIVE), audited as the system, the member told; a recent visitor is untouched', function (): void {
    $gone = campaignMember(profile: ['last_active_at' => now()->subDays(181)]);
    $recent = campaignMember(profile: ['last_active_at' => now()->subDays(179)]);

    runAutoHide();
    runAutoHide();   // a second run changes nothing

    expect($gone->profile->refresh()->status)->toBe(ProfileStatus::Hidden)
        ->and($gone->profile->previous_status)->toBe(ProfileStatus::Active)
        ->and($recent->profile->refresh()->status)->toBe(ProfileStatus::Active);
    expect(AuditLog::query()->where('action', 'profile.auto_hidden_inactive')->sole())
        ->actor_type->value->toBe('SYSTEM')->subject_label->toBe($gone->profile->code);
    Notification::assertSentToTimes($gone, ProfileHiddenForInactivity::class, 1);
    Notification::assertNotSentTo($recent, ProfileHiddenForInactivity::class);

    expect(app(App\Domain\Profile\MemberHiding::class)->unhide($gone->profile->refresh()))->toBeTrue()
        ->and($gone->profile->status)->toBe(ProfileStatus::Active);
});

it('A15 auto-hide follows the business rule without a deploy; never-active profiles count from publishing; bureau-run and non-ACTIVE profiles are skipped', function (): void {
    app(App\Actions\Admin\Settings\UpdateSettings::class)->handle(adminWithRole(), ['profile.auto_hide_inactive_days' => 60], 'Shorter');
    $sixtyOne = campaignMember(profile: ['last_active_at' => now()->subDays(61)]);
    $neverSeen = campaignMember(profile: ['last_active_at' => null, 'published_at' => now()->subDays(90)]);
    $managed = campaignMember(profile: ['last_active_at' => now()->subDays(400)]);
    $managed->profile->forceFill(['managed_by_broker_id' => Broker::factory()->create()->id])->save();
    $suspended = campaignMember(profile: ['last_active_at' => now()->subDays(400), 'status' => ProfileStatus::Suspended]);

    runAutoHide();

    expect($sixtyOne->profile->refresh()->status)->toBe(ProfileStatus::Hidden)
        ->and($neverSeen->profile->refresh()->status)->toBe(ProfileStatus::Hidden)
        ->and($managed->profile->refresh()->status)->toBe(ProfileStatus::Active)
        ->and($suspended->profile->refresh()->status)->toBe(ProfileStatus::Suspended)
        ->and(Profile::query()->where('status', ProfileStatus::Hidden->value)->count())->toBe(2)
        ->and(SettingKey::ProfileAutoHideInactiveDays->rules())->toContain('min:30');
});

it('A15 auto-hide runs daily at 03:20 IST', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->description, 'HideInactiveProfiles'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('20 3 * * *')
        ->and($event->timezone)->toBe('Asia/Kolkata');
});
