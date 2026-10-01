<?php

declare(strict_types=1);

use App\Actions\Profile\RecordProfileView;
use App\Enums\ProfileStatus;
use App\Events\Profile\ProfileViewed;
use App\Models\Block;
use App\Models\PrivacySetting;
use App\Models\ProfileView;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Event;

/*
| P1.5 — who may open /profile/{code} (R-M03-1, R-M03-3, same-gender rule) and the profile_views
| record + live "viewed you" event (M03, M15).
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
});

function blockPair(User $blocker, User $blocked): void
{
    $block = new Block;
    $block->forceFill(['blocker_profile_id' => $blocker->profile->id, 'blocked_profile_id' => $blocked->profile->id])->save();
}

function profileUrl(User $owner): string
{
    return memberUrl('/profile/'.$owner->profile->code);
}

it('shows an ACTIVE opposite-gender profile to a member', function (): void {
    $bride = bride();

    $this->actingAs(groom(), 'web')->get(profileUrl($bride))
        ->assertOk()
        ->assertSee($bride->profile->code)
        ->assertSee($bride->profile->first_name);
});

it('R-M03-1: a blocked pair gets 404 on each other\'s profile, in both directions', function (string $who): void {
    $bride = bride();
    $groom = groom();
    $who === 'bride blocked groom' ? blockPair($bride, $groom) : blockPair($groom, $bride);

    $this->actingAs($groom, 'web')->get(profileUrl($bride))->assertNotFound();
    $this->actingAs($bride, 'web')->get(profileUrl($groom))->assertNotFound();
})->with(['bride blocked groom', 'groom blocked bride']);

it('R-M03-3: a profile that is not ACTIVE is a 404 for others but open to its owner', function (ProfileStatus $status): void {
    $bride = bride();
    $bride->profile->forceFill(['status' => $status])->save();

    $this->actingAs(groom(), 'web')->get(profileUrl($bride))->assertNotFound();
    $this->actingAs($bride->refresh(), 'web')->get(profileUrl($bride))->assertOk();
})->with([ProfileStatus::PendingReview, ProfileStatus::Rejected, ProfileStatus::Hidden, ProfileStatus::Suspended]);

it('same-gender profiles are a 404 (owner decision 2026-10-01)', function (): void {
    $this->actingAs(bride('+919800000097'), 'web')->get(profileUrl(bride()))->assertNotFound();
});

it('security matrix: guest → login, broker → 404, unknown code → 404, malformed code → 404, draft viewer → wizard', function (): void {
    $bride = bride();

    $this->get(profileUrl($bride))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->broker()->create(), 'web')->get(profileUrl($bride))->assertNotFound();
    $this->actingAs(groom(), 'web')->get(memberUrl('/profile/OPM99999999'))->assertNotFound();
    $this->actingAs(groom('+919800000096'), 'web')->get(memberUrl('/profile/'.$bride->profile->id))->assertNotFound();   // ULID in URL

    $draft = memberWithPhone('+919800000095', ProfileStatus::Draft);
    $this->actingAs($draft, 'web')->get(profileUrl($bride))->assertRedirect(route('member.onboarding', ['step' => 1]));
});

it('records one profile_views row per viewer per day and counts repeat visits', function (): void {
    $bride = bride();
    $groom = groom();

    foreach (range(1, 3) as $ignored) {
        app(RecordProfileView::class)->handle($groom, $bride->profile);
    }

    $row = ProfileView::query()->sole();
    expect($row->viewer_profile_id)->toBe($groom->profile->id)
        ->and($row->viewed_profile_id)->toBe($bride->profile->id)
        ->and($row->count)->toBe(3);

    $this->travel(1)->days();
    app(RecordProfileView::class)->handle($groom, $bride->profile);
    expect(ProfileView::query()->count())->toBe(2);
});

it('fires ProfileViewed to the viewed member once per viewer per day, with today\'s count and no viewer identity', function (): void {
    Event::fake([ProfileViewed::class]);
    $bride = bride();

    $second = groom('+919800000094');

    app(RecordProfileView::class)->handle(groom(), $bride->profile);
    app(RecordProfileView::class)->handle($second, $bride->profile);
    app(RecordProfileView::class)->handle($second, $bride->profile);   // refresh: no new event

    Event::assertDispatchedTimes(ProfileViewed::class, 2);
    Event::assertDispatched(ProfileViewed::class, fn (ProfileViewed $e): bool => $e->broadcastOn()[0]->name === 'private-App.Models.User.'.$bride->id
        && $e->broadcastAs() === 'profile.viewed'
        && $e->broadcastWith() === ['count_today' => 2]);
});

it('records nothing for the owner, an incognito viewer or a blocked pair', function (string $case): void {
    $bride = bride();
    $groom = groom();

    $viewer = match ($case) {
        'owner' => $bride,
        'incognito' => tap($groom, function (User $u): void {
            $privacy = PrivacySetting::query()->whereKey($u->profile->id)->first() ?? new PrivacySetting;
            $privacy->forceFill(['profile_id' => $u->profile->id, 'incognito' => true])->save();
        })->refresh(),
        'blocked' => tap($groom, fn (User $u) => blockPair($bride, $u)),
    };

    app(RecordProfileView::class)->handle($viewer, $bride->profile);

    expect(ProfileView::query()->count())->toBe(0);
})->with(['owner', 'incognito', 'blocked']);

it('opening the page records the view', function (): void {
    $bride = bride();

    $this->actingAs(groom(), 'web')->get(profileUrl($bride))->assertOk();

    expect(ProfileView::query()->where('viewed_profile_id', $bride->profile->id)->count())->toBe(1);
});

it('a viewer whose own profile is SUSPENDED gets 404; pending, rejected and hidden viewers may browse', function (ProfileStatus $own, int $status): void {
    $groom = groom();
    $groom->profile->forceFill(['status' => $own])->save();

    $this->actingAs($groom->refresh(), 'web')->get(profileUrl(bride()))->assertStatus($status);
})->with([
    'suspended' => [ProfileStatus::Suspended, 404],
    'pending review' => [ProfileStatus::PendingReview, 200],
    'rejected' => [ProfileStatus::Rejected, 200],
    'hidden' => [ProfileStatus::Hidden, 200],
]);
