<?php

declare(strict_types=1);

use App\Actions\Profile\SetHomeVisibility;
use App\Enums\PhotoStatus;
use App\Enums\PhotoVisibility;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Livewire\Public\Home;
use App\Models\AuditLog;
use App\Models\Block;
use App\Models\PrivacySetting;
use App\Models\Profile;
use App\Models\Setting;
use App\Models\User;
use App\Queries\Content\FeaturedMembers;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\ContentSeeder;
use Database\Seeders\PlansSeeder;
use Livewire\Livewire;

/*
| M12 featured members, owner 2026-11-01: ACTIVE + premium + verified + ALL_MEMBERS + explicit opt-in +
| an approved photo. Each condition is tested failing alone. First name, district and photo only.
*/

beforeEach(function (): void {
    seedMasters();
    fakeMediaDisks();
    homeMembersMin(1);
});

function homeMembersMin(int $count): void
{
    Setting::query()->whereKey(SettingKey::ContentHomeMembersMin->value)->delete();
    Setting::factory()->keyed(SettingKey::ContentHomeMembersMin, $count)->create();
    app(SettingsRepository::class)->flush();
}

function featuredProfile(array $profile = [], array $privacy = [], PhotoStatus $photo = PhotoStatus::Approved): Profile
{
    $user = contentMember();
    addTestPhoto($user, convert: true)->forceFill(['moderation_status' => $photo])->save();

    $row = $user->profile;
    $row->forceFill(['first_name' => 'Featured', 'is_premium' => true, 'is_verified' => true, ...$profile])->save();

    $settings = PrivacySetting::query()->find($row->id) ?? (new PrivacySetting)->forceFill(['profile_id' => $row->id]);
    $settings->forceFill(['photo_visibility' => PhotoVisibility::AllMembers, 'show_on_home' => true, 'incognito' => false, ...$privacy])->save();

    return $row->refresh();
}

function featuredNames(?User $viewer = null): array
{
    return array_map(fn ($card) => $card->name, app(FeaturedMembers::class)->forViewer($viewer));
}

it('M12: a member who meets every condition appears, with first name and district only', function (): void {
    $profile = featuredProfile();
    $clear = App\Models\Media::query()->where('model_id', $profile->id)->sole()->getUrl('card');

    $cards = app(FeaturedMembers::class)->forViewer(null);

    expect($cards)->toHaveCount(1)
        ->and($cards[0]->name)->toBe('Featured')
        ->and($cards[0]->code)->toBe('')
        ->and($cards[0]->url)->toBeNull()
        ->and($cards[0]->photoUrl)->toBe($clear);
});

it('M12: each condition failing alone keeps the member off the home page', function (array $profile, array $privacy, PhotoStatus $photo = PhotoStatus::Approved): void {
    featuredProfile($profile, $privacy, $photo);

    expect(featuredNames())->toBe([]);
})->with([
    'not premium' => [['is_premium' => false], []],
    'not verified' => [['is_verified' => false], []],
    'not opted in' => [[], ['show_on_home' => false]],
    'premium-only photos' => [[], ['photo_visibility' => PhotoVisibility::PremiumOnly]],
    'on-request photos' => [[], ['photo_visibility' => PhotoVisibility::OnRequest]],
    'accepted-only photos' => [[], ['photo_visibility' => PhotoVisibility::AcceptedOnly]],
    'incognito' => [[], ['incognito' => true]],
    'hidden' => [['status' => ProfileStatus::Hidden], []],
    'suspended profile' => [['status' => ProfileStatus::Suspended], []],
    'pending review' => [['status' => ProfileStatus::PendingReview], []],
    'photo not approved' => [[], [], PhotoStatus::Pending],
    'photo rejected' => [[], [], PhotoStatus::Rejected],
]);

it('M12: opt-in defaults to off', function (): void {
    $user = contentMember();
    (new PrivacySetting)->forceFill(['profile_id' => $user->profile->id])->save();

    expect(PrivacySetting::query()->findOrFail($user->profile->id)->show_on_home)->toBeFalse();
});

it('M12: a suspended account, or a profile soft-deleted, disappears on the next load', function (string $how): void {
    $profile = featuredProfile();
    expect(featuredNames())->toBe(['Featured']);

    $how === 'suspended'
        ? $profile->user->forceFill(['status' => UserStatus::Suspended])->save()
        : $profile->delete();

    expect(featuredNames())->toBe([]);
})->with(['suspended', 'deleted']);

it('M12: opting out applies on the next load, and the choice is audited', function (): void {
    $profile = featuredProfile();
    expect(featuredNames())->toBe(['Featured']);

    app(SetHomeVisibility::class)->handle($profile->user, false);

    expect(featuredNames())->toBe([])
        ->and(AuditLog::query()->where('action', 'profile.home_visibility_changed')->exists())->toBeTrue();
});

it('M12 rule 4: a signed-in viewer never sees a blocked pair, in either direction, or themselves', function (string $direction): void {
    $featured = featuredProfile();
    $viewer = contentMember();

    $direction === 'viewer blocked them'
        ? Block::factory()->create(['blocker_profile_id' => $viewer->profile->id, 'blocked_profile_id' => $featured->id])
        : Block::factory()->create(['blocker_profile_id' => $featured->id, 'blocked_profile_id' => $viewer->profile->id]);

    expect(featuredNames($viewer->refresh()))->toBe([])
        ->and(featuredNames($featured->user))->toBe([])
        ->and(featuredNames())->toBe(['Featured']);
})->with(['viewer blocked them', 'they blocked viewer']);

it('M12: below the A15 minimum the band is empty rather than singling a few out', function (): void {
    homeMembersMin(2);
    featuredProfile();

    expect(featuredNames())->toBe([]);

    featuredProfile();
    expect(featuredNames())->toHaveCount(2);
});

it('M12: the home page shows the band with no phone, profile code or ULID', function (): void {
    $this->seed(PlansSeeder::class);
    $this->seed(ContentSeeder::class);
    $profile = featuredProfile();
    $profile->user->forceFill(['phone' => '+919876543210', 'email' => 'private@example.test'])->save();

    $html = $this->get('/')->assertOk()->assertSee('data-featured-members', false)->assertSee('Featured')->getContent();

    expect($html)->not->toContain($profile->code)
        ->not->toContain((string) $profile->id)
        ->not->toContain('9876543210')
        ->not->toContain('private@example.test');
});

it('M12: with nobody opted in, the home page has no band', function (): void {
    $this->seed(PlansSeeder::class);
    $this->seed(ContentSeeder::class);

    $this->get('/')->assertOk()->assertDontSee('data-featured-members', false);
});

it('the toggle: support staff can’t opt a member in during a support session', function (): void {
    $user = contentMember();
    chatImpersonate($user);

    expect(fn () => app(SetHomeVisibility::class)->handle($user, true))->toThrow(App\Exceptions\Admin\ImpersonationRestricted::class)
        ->and(PrivacySetting::query()->find($user->profile->id)?->show_on_home ?? false)->toBeFalse();
});

it('the toggle: an inactive member gets a 404', function (): void {
    $user = contentMember();
    $user->forceFill(['status' => UserStatus::Suspended])->save();

    expect(fn () => app(SetHomeVisibility::class)->handle($user, true))->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

it('M12: featured cards have no link for a visitor (a visitor can’t open profiles)', function (): void {
    $this->seed(PlansSeeder::class);
    $this->seed(ContentSeeder::class);
    featuredProfile();

    Livewire::test(Home::class)->assertSee('Featured')->assertDontSeeHtml('class="member-link"');
});

it('the toggle: joining is rate limited, leaving never is, and leaving takes effect at once', function (): void {
    $profile = featuredProfile();
    $user = $profile->user;
    $action = app(SetHomeVisibility::class);
    $action->handle($user, false);

    foreach (range(1, SettingKey::ContentHomeToggleMaxPerHour->default()) as $i) {
        $action->handle($user, true);
        $action->handle($user, false);
    }

    expect(fn () => $action->handle($user, true))->toThrow(App\Exceptions\Admin\ContentRefused::class)
        ->and(featuredNames())->toBe([]);

    // Over the limit and opted in from before: leaving still works.
    PrivacySetting::query()->findOrFail($profile->id)->forceFill(['show_on_home' => true])->save();
    expect(featuredNames())->toBe(['Featured']);

    $action->handle($user, false);

    expect(featuredNames())->toBe([]);
});

it('the toggle: repeating the same choice changes nothing and writes no audit row', function (): void {
    $user = contentMember();
    $action = app(SetHomeVisibility::class);

    $action->handle($user, false);
    expect(AuditLog::query()->where('action', 'profile.home_visibility_changed')->count())->toBe(0);

    $action->handle($user, true);
    $action->handle($user, true);
    expect(AuditLog::query()->where('action', 'profile.home_visibility_changed')->count())->toBe(1);
});
