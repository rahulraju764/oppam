<?php

declare(strict_types=1);

use App\Actions\Profile\HideProfile;
use App\Actions\Profile\UnhideProfile;
use App\Broadcasting\OnlinePresenceChannel;
use App\Data\Search\SearchCriteria;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\Profile\ProfileSearch;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P8.2c — /settings → Hide / delete: "hide (reversible any time)" (PRD M14). Proved where it
| matters: a hidden profile is out of search, can't be opened by anyone else, is off the online
| roster; showing it again brings back the status it had.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

it('R-M14 hide: the profile leaves search, can\'t be opened by others and leaves the online roster', function (): void {
    $bride = bride();
    $groom = groom();
    $codes = fn (): array => app(ProfileSearch::class)->query($groom->profile, SearchCriteria::fromInput([]))->pluck('code')->all();
    expect($codes())->toContain($bride->profile->code);

    app(HideProfile::class)->handle($bride);

    $profile = $bride->profile->refresh();
    expect($profile->status)->toBe(ProfileStatus::Hidden)
        ->and($profile->previous_status)->toBe(ProfileStatus::Active)
        ->and($codes())->not->toContain($profile->code)
        ->and(app(ProfileVisibility::class)->canView($profile, $groom->refresh()))->toBeFalse()
        ->and(app(ProfileVisibility::class)->canView($profile, $bride->refresh()))->toBeTrue()   // the owner always
        ->and(app(OnlinePresenceChannel::class)->join($bride))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'profile.hidden_by_member')->exists())->toBeTrue();

    $this->actingAs($groom->refresh(), 'web')->get(memberUrl('/profile/'.$profile->code))->assertNotFound();
});

it('R-M14 unhide: the profile is live again with the status it had', function (): void {
    $bride = bride();
    app(HideProfile::class)->handle($bride);

    app(UnhideProfile::class)->handle($bride->refresh());

    $profile = $bride->profile->refresh();
    expect($profile->status)->toBe(ProfileStatus::Active)->and($profile->previous_status)->toBeNull()
        ->and(app(ProfileSearch::class)->query(groom()->profile, SearchCriteria::fromInput([]))->pluck('code')->all())->toContain($profile->code)
        ->and(AuditLog::query()->where('action', 'profile.unhidden_by_member')->exists())->toBeTrue();
});

it('only a live profile can be hidden; only a hidden one can be shown again', function (): void {
    $pending = memberWithPhone('+919800000001', ProfileStatus::PendingReview);
    expect(fn () => app(HideProfile::class)->handle($pending))->toThrow(SettingsRefused::class);
    expect(statusOf($pending))->toBe(ProfileStatus::PendingReview);

    expect(fn () => app(UnhideProfile::class)->handle(bride()))->toThrow(SettingsRefused::class);
});

it('rule 10: showing the profile again shares the settings limit; hiding never does', function (): void {
    Setting::factory()->keyed(SettingKey::MembersSettingsChangesPerHour, 1)->create();
    $bride = bride();

    app(HideProfile::class)->handle($bride);
    app(UnhideProfile::class)->handle($bride->refresh());
    app(HideProfile::class)->handle($bride->refresh());   // hiding is never refused

    expect(fn () => app(UnhideProfile::class)->handle($bride->refresh()))->toThrow(SettingsRefused::class);
    expect(statusOf($bride))->toBe(ProfileStatus::Hidden);
});

it('refused in an admin support session; a suspended member or one without a profile gets a 404', function (string $case): void {
    $bride = bride();
    $this->actingAs($bride, 'web');

    $member = match ($case) {
        'support session' => tap($bride, fn () => chatImpersonate($bride)),
        'suspended' => tap($bride)->forceFill(['status' => UserStatus::Suspended])->save() ? $bride->refresh() : $bride,
        'no profile' => App\Models\User::factory()->create(),
    };

    expect(fn () => app(HideProfile::class)->handle($member))
        ->toThrow($case === 'support session' ? ImpersonationRestricted::class : NotFoundHttpException::class);
    expect(statusOf($bride))->toBe(ProfileStatus::Active);
})->with(['support session', 'suspended', 'no profile']);

it('owner decision (3): a hidden member can still accept an interest they received; the conversation opens', function (): void {
    $bride = bride();
    $groom = groom();
    App\Models\Interest::factory()->create(['from_profile_id' => $groom->profile->id, 'to_profile_id' => $bride->profile->id]);
    app(HideProfile::class)->handle($bride);

    $conversation = app(App\Actions\Engagement\AcceptInterest::class)->handle($bride->refresh(), $groom->profile);

    expect($conversation->exists)->toBeTrue()
        ->and(App\Models\Interest::query()->sole()->status)->toBe(App\Enums\InterestStatus::Accepted)
        ->and(statusOf($bride))->toBe(ProfileStatus::Hidden);   // still hidden from everyone else
});
