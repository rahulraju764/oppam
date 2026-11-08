<?php

declare(strict_types=1);

use App\Actions\Engagement\SendInterest;
use App\Actions\Profile\RecordProfileView;
use App\Actions\Profile\UpdatePrivacySettings;
use App\Broadcasting\OnlinePresenceChannel;
use App\Data\Profile\PrivacySettingsData;
use App\Data\Search\SearchCriteria;
use App\Domain\Chat\LastSeen;
use App\Domain\Chat\ReadReceipts;
use App\Domain\Media\HoroscopeAccess;
use App\Domain\Media\PhotoAccess;
use App\Domain\Profile\ContactAccessPolicy;
use App\Enums\ContactAccess;
use App\Enums\HoroscopeVisibility;
use App\Enums\PhoneVisibility;
use App\Enums\PhotoVisibility;
use App\Enums\PlanCode;
use App\Enums\SettingKey;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Engagement\EngagementRefused;
use App\Exceptions\Profile\SettingsRefused;
use App\Models\Interest;
use App\Models\PartnerPreference;
use App\Models\PrivacySetting;
use App\Models\ProfileView;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Profile\ProfileSearch;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P8.2a — /settings → Privacy (PRD M14). "Done when: each privacy toggle has a test proving its
| effect elsewhere": every switch is saved through UpdatePrivacySettings (what the tab calls) and
| then checked where it is enforced — photos, phone, horoscope, presence, last seen, read receipts,
| search, incognito (Diamond, owner 2026-11-07), contact filter. Plus the Action's own rules:
| Diamond-only incognito, the rate limit that never blocks going more private, support sessions,
| and a member who may not change anything.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

/** Save the member's current privacy with some fields changed, as the Privacy tab does. */
function psSave(User $member, array $changes): bool
{
    $current = PrivacySettingsData::from(PrivacySetting::query()->find($member->profile->id));
    $wanted = new PrivacySettingsData(...[...get_object_vars($current), ...$changes]);
    $member->profile->unsetRelation('privacySetting');

    return app(UpdatePrivacySettings::class)->handle($member, $wanted);
}

function psRow(User $member): ?PrivacySetting
{
    return PrivacySetting::query()->find($member->profile->id);
}

// ---- Each switch, proved where it is enforced ------------------------------------------------------

it('R-M14 photos: "accepted matches only" hides the photo from a member who isn\'t connected', function (): void {
    $bride = bride();
    $groom = groom();
    expect(app(PhotoAccess::class)->canSeeClearly($bride->profile->refresh(), $groom))->toBeTrue();

    psSave($bride, ['photoVisibility' => PhotoVisibility::AcceptedOnly]);

    expect(app(PhotoAccess::class)->canSeeClearly($bride->profile->refresh(), $groom))->toBeFalse()
        ->and(app(PhotoAccess::class)->canSeeClearly($bride->profile, $bride))->toBeTrue();   // the owner always
});

it('R-M14 phone: "hidden" stops every member from revealing the number', function (): void {
    $bride = bride();
    $groom = chatOnPlan(groom(), PlanCode::Gold);

    psSave($bride, ['phoneVisibility' => PhoneVisibility::Hidden]);

    expect(app(ContactAccessPolicy::class)->decide($bride->profile->refresh(), $groom->profile))->toBe(ContactAccess::HiddenByMember);
});

it('R-M14 horoscope: "accepted matches only" refuses a member who isn\'t connected', function (): void {
    $bride = bride();
    $groom = groom();
    expect(app(HoroscopeAccess::class)->canView($bride->profile->refresh(), $groom))->toBeTrue();

    psSave($bride, ['horoscopeVisibility' => HoroscopeVisibility::AcceptedOnly]);

    expect(app(HoroscopeAccess::class)->canView($bride->profile->refresh(), $groom))->toBeFalse();
});

it('R-M14 online status: switched off, the member never joins presence.online and the tab is told to leave it', function (): void {
    $bride = bride();
    expect(app(OnlinePresenceChannel::class)->join($bride))->toBe(['code' => $bride->profile->code]);

    expect(psSave($bride, ['showOnlineStatus' => false]))->toBeTrue()
        ->and(app(OnlinePresenceChannel::class)->join($bride->refresh()))->toBeFalse();
});

it('R-M14 last seen: switched off, nobody is shown when the member was last active', function (): void {
    $bride = bride();
    $bride->forceFill(['last_seen_at' => now()->subHour()])->save();
    expect(app(LastSeen::class)->label($bride->profile))->not->toBeNull();

    psSave($bride, ['showLastSeen' => false]);

    expect(app(LastSeen::class)->label($bride->profile))->toBeNull();
});

it('R-M07-7 / R-M14 read receipts: switched off, they are neither sent nor shown', function (): void {
    $bride = bride();
    $groom = groom();

    psSave($bride, ['readReceipts' => false]);

    expect(app(ReadReceipts::class)->enabled($bride->profile->id))->toBeFalse()
        ->and(app(ReadReceipts::class)->shownBetween($bride->profile->id, $groom->profile->id))->toBeFalse();
});

it('R-M14 hide from search (every plan): out of search and off the online roster, back when switched off', function (): void {
    $bride = bride();
    $groom = groom();
    $codes = fn (): array => app(ProfileSearch::class)->query($groom->profile, SearchCriteria::fromInput([]))->pluck('code')->all();
    expect($codes())->toContain($bride->profile->code);

    expect(psSave($bride, ['hideFromSearch' => true]))->toBeTrue();   // Free plan: allowed
    expect($codes())->not->toContain($bride->profile->code)
        ->and(app(OnlinePresenceChannel::class)->join($bride->refresh()))->toBeFalse();

    psSave($bride, ['hideFromSearch' => false]);
    expect($codes())->toContain($bride->profile->code);
});

it('R-M14 incognito (Diamond): profiles the member opens get no "who viewed me" row, and they stay searchable', function (): void {
    $groom = chatOnPlan(groom(), PlanCode::Diamond);
    $bride = bride();

    psSave($groom, ['incognito' => true]);
    app(RecordProfileView::class)->handle($groom->refresh(), $bride->profile);

    expect(ProfileView::query()->count())->toBe(0)
        ->and(psRow($groom)?->incognito)->toBeTrue()
        ->and(app(ProfileSearch::class)->query($bride->profile, SearchCriteria::fromInput([]))->pluck('code')->all())->toContain($groom->profile->code);
});

it('R-M14 incognito lapses with the plan: once Diamond ends, views are recorded again', function (): void {
    $groom = chatOnPlan(groom(), PlanCode::Diamond);
    $bride = bride();
    psSave($groom, ['incognito' => true]);

    Subscription::query()->where('profile_id', $groom->profile->id)->delete();
    app(EntitlementService::class)->forget($groom->profile);
    app(RecordProfileView::class)->handle($groom->refresh(), $bride->profile);

    expect(ProfileView::query()->where('viewer_profile_id', $groom->profile->id)->count())->toBe(1);
});

it('R-M14 incognito is refused below Diamond, and nothing is saved', function (PlanCode $plan): void {
    $groom = $plan === PlanCode::Free ? groom() : chatOnPlan(groom(), $plan);

    expect(fn () => psSave($groom, ['incognito' => true, 'readReceipts' => false]))
        ->toThrow(SettingsRefused::class, 'Diamond');
    expect(psRow($groom)?->incognito ?? false)->toBeFalse()
        ->and(psRow($groom)?->read_receipts ?? true)->toBeTrue();
})->with([PlanCode::Free, PlanCode::Silver, PlanCode::Gold]);

it('R-M14 incognito kept "on" through a lapse can still be saved unchanged, and switched off', function (): void {
    $groom = chatOnPlan(groom(), PlanCode::Diamond);
    psSave($groom, ['incognito' => true]);
    Subscription::query()->where('profile_id', $groom->profile->id)->delete();
    app(EntitlementService::class)->forget($groom->profile);

    psSave($groom, ['readReceipts' => false]);   // incognito still true in the form: not a switch-on
    expect(psRow($groom)?->incognito)->toBeTrue();

    psSave($groom, ['incognito' => false]);
    expect(psRow($groom)?->incognito)->toBeFalse();
});

it('R-M06-3 / R-M14 contact filter: switched on, a member outside the partner preferences can\'t send an interest', function (): void {
    $groom = groom();
    $bride = bride();
    $preference = PartnerPreference::query()->where('profile_id', $bride->profile->id)->first() ?? new PartnerPreference;
    $preference->forceFill(['profile_id' => $bride->profile->id, 'age_min' => 60, 'age_max' => 70])->save();

    psSave($bride, ['contactFilter' => true]);

    $refusal = null;
    try {
        app(SendInterest::class)->handle($groom, $bride->profile->refresh());
    } catch (EngagementRefused $e) {
        $refusal = $e;
    }
    expect($refusal?->reason)->toBe(EngagementRefused::CONTACT_FILTER)
        ->and(Interest::query()->count())->toBe(0);
});

it('R-M14 horoscope: "on request" refuses until the member approves that viewer\'s request (P8.2c gap)', function (): void {
    $bride = bride();
    $groom = groom();

    psSave($bride, ['horoscopeVisibility' => HoroscopeVisibility::OnRequest]);
    expect(app(HoroscopeAccess::class)->canView($bride->profile->refresh(), $groom))->toBeFalse();

    // The request + approval themselves are proved in AccessRequestsTest (they need an uploaded file).
    App\Models\AccessRequest::query()->forceCreate([
        'requester_profile_id' => $groom->profile->id, 'owner_profile_id' => $bride->profile->id,
        'type' => App\Enums\AccessRequestType::Horoscope, 'status' => App\Enums\AccessRequestStatus::Approved, 'responded_at' => now(),
    ]);
    app(App\Domain\Engagement\Connections::class)->forget();

    expect(app(HoroscopeAccess::class)->canView($bride->profile->refresh(), $groom))->toBeTrue()
        ->and(app(HoroscopeAccess::class)->canView($bride->profile, groom('+919800000088')))->toBeFalse();   // only that viewer
});

it('R-M14 / M04 contact filter in search: the member is still listed, with "Send Interest" switched off (P8.2c)', function (): void {
    $groom = groom();
    $bride = bride();
    $preference = PartnerPreference::query()->where('profile_id', $bride->profile->id)->first() ?? new PartnerPreference;
    $preference->forceFill(['profile_id' => $bride->profile->id, 'age_min' => 60, 'age_max' => 70])->save();
    $card = function () use ($groom, $bride): ?App\Data\Profile\ProfileCardData {
        $profiles = app(ProfileSearch::class)->query($groom->profile, SearchCriteria::fromInput([]))->with('privacySetting')->get();

        return collect(app(App\Domain\Profile\ProfileCards::class)->forViewers($profiles, $groom->refresh()))
            ->firstWhere('code', $bride->profile->code);
    };
    expect($card()?->interest)->toBe(App\Domain\Engagement\Connections::NONE);

    psSave($bride, ['contactFilter' => true]);

    expect($card()?->interest)->toBe(App\Domain\Engagement\Connections::FILTERED);   // listed, button off
    $this->actingAs($groom, 'web');
    $html = Illuminate\Support\Facades\Blade::render('<x-engagement.interest-button code="'.$bride->profile->code.'" state="filtered" />');
    expect($html)->toContain('disabled')->toContain('accepts interests only from profiles matching their preferences');

    // A member who does match her preferences still gets the normal button.
    $preference->forceFill(['age_min' => 18, 'age_max' => 99])->save();
    expect($card()?->interest)->toBe(App\Domain\Engagement\Connections::NONE);
});

// ---- The Action's own rules -----------------------------------------------------------------------

it('creates the row with every choice when the member has none yet (the defaults go in before the lock)', function (): void {
    $bride = bride();
    PrivacySetting::query()->whereKey($bride->profile->id)->delete();

    psSave($bride, ['phoneVisibility' => PhoneVisibility::AcceptedOnly, 'contactFilter' => true]);

    expect(psRow($bride))->not->toBeNull()
        ->and(psRow($bride)?->phone_visibility)->toBe(PhoneVisibility::AcceptedOnly)
        ->and(psRow($bride)?->contact_filter_enabled)->toBeTrue()
        ->and(psRow($bride)?->photo_visibility)->toBe(PhotoVisibility::AllMembers);
});

it('rule 10: past members.settings_changes_per_hour a looser save is refused; a stricter or unchanged one always works', function (): void {
    Setting::factory()->keyed(SettingKey::MembersSettingsChangesPerHour, 5)->create();
    $bride = bride();

    for ($i = 0; $i < 5; $i++) {
        psSave($bride, ['showLastSeen' => false]);   // stricter: not counted
        psSave($bride, ['showLastSeen' => true]);    // looser: counted
    }

    psSave($bride, []);                              // unchanged: nothing saved, nothing counted
    psSave($bride, ['showLastSeen' => false]);       // stricter: still allowed
    expect(psRow($bride)?->show_last_seen)->toBeFalse();

    expect(fn () => psSave($bride, ['showLastSeen' => true]))->toThrow(SettingsRefused::class);
    expect(psRow($bride)?->show_last_seen)->toBeFalse();
});

it('a stricter save is never refused: every "more private" direction counts as stricter', function (string $field, mixed $loose, mixed $strict): void {
    $before = new PrivacySettingsData(PhotoVisibility::AllMembers, PhoneVisibility::PremiumOnly, HoroscopeVisibility::AllMembers, true, true, true, false, false, false);
    $looser = new PrivacySettingsData(...[...get_object_vars($before), $field => $loose]);
    $stricter = new PrivacySettingsData(...[...get_object_vars($before), $field => $strict]);

    expect($stricter->isNoLooserThan($looser))->toBeTrue()
        ->and($looser->isNoLooserThan($stricter))->toBeFalse();
})->with([
    'photo' => ['photoVisibility', PhotoVisibility::AllMembers, PhotoVisibility::AcceptedOnly],
    'phone' => ['phoneVisibility', PhoneVisibility::PremiumOnly, PhoneVisibility::Hidden],
    'horoscope' => ['horoscopeVisibility', HoroscopeVisibility::AllMembers, HoroscopeVisibility::AcceptedOnly],
    'online status' => ['showOnlineStatus', true, false],
    'last seen' => ['showLastSeen', true, false],
    'read receipts' => ['readReceipts', true, false],
    'hide from search' => ['hideFromSearch', false, true],
    'incognito' => ['incognito', false, true],
    'contact filter' => ['contactFilter', false, true],
]);

it('refused in an admin support session: nothing changes', function (): void {
    $bride = bride();
    $this->actingAs($bride, 'web');
    chatImpersonate($bride);

    expect(fn () => psSave($bride, ['hideFromSearch' => true]))->toThrow(ImpersonationRestricted::class);
    expect(psRow($bride)?->hide_from_search ?? false)->toBeFalse();
});

it('a suspended account, or a member without a profile, can\'t change anything (404)', function (string $case): void {
    $bride = bride();
    if ($case === 'suspended') {
        $bride->forceFill(['status' => App\Enums\UserStatus::Suspended])->save();
    }
    $wanted = PrivacySettingsData::from(null);
    $member = $case === 'no profile' ? User::factory()->create() : $bride->refresh();

    expect(fn () => app(UpdatePrivacySettings::class)->handle($member, new PrivacySettingsData(...[...get_object_vars($wanted), 'hideFromSearch' => true])))
        ->toThrow(NotFoundHttpException::class);
})->with(['suspended', 'no profile']);
