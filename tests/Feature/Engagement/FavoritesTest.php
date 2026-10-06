<?php

declare(strict_types=1);

use App\Actions\Engagement\RemoveFavorites;
use App\Actions\Engagement\SaveFavoriteNote;
use App\Actions\Engagement\ToggleFavorite;
use App\Domain\Matching\DailyBatch;
use App\Enums\Entitlement;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Events\Engagement\LikeChanged;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Billing\QuotaExceeded;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\Block;
use App\Models\DailyMatch;
use App\Models\Favorite;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Queries\Engagement\EngagementLists;
use App\Services\Admin\Impersonation;
use App\Services\Entitlements\EntitlementService;
use App\Support\Facades\Settings;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P3.3 — favourites (PRD §10 M06, §7.3): a private bookmark with a private note. ToggleFavorite
| (idempotent, the plan's total, nobody ever told — R-M06-6), the note, removing several, who may
| bookmark whom (the security matrix).
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    Event::fake();
});

function favSet(User $member, Profile $target, bool $on = true): bool
{
    return app(ToggleFavorite::class)->handle($member, $target, $on);
}

function favTarget(): Profile
{
    return Profile::factory()->female()->active()->create();
}

function favUsed(User $member): int
{
    return app(EntitlementService::class)->used($member->profile, Entitlement::FavoritesTotal);
}

it('R-M06-6: a favourite is stored for the member only — nobody is told, live or in-app', function (): void {
    $groom = groom();
    $bride = bride();

    expect(favSet($groom, $bride->profile))->toBeTrue()
        ->and(Favorite::query()->where('profile_id', $groom->profile->id)->where('favorite_profile_id', $bride->profile->id)->count())->toBe(1)
        ->and(favUsed($groom))->toBe(1);

    Notification::assertNothingSent();
    Event::assertNotDispatched(LikeChanged::class);
    expect(Illuminate\Notifications\DatabaseNotification::query()->count())->toBe(0);
});

it('R-M06-1: adding twice and removing twice change nothing the second time; removing gives the slot back', function (): void {
    $groom = groom();
    $bride = bride();

    favSet($groom, $bride->profile);
    favSet($groom, $bride->profile);
    expect(Favorite::query()->count())->toBe(1)->and(favUsed($groom))->toBe(1);

    expect(favSet($groom, $bride->profile, false))->toBeFalse();
    favSet($groom, $bride->profile, false);
    expect(Favorite::query()->count())->toBe(0)->and(favUsed($groom))->toBe(0);
});

it('§7.3: a Free member keeps 25 favourites — the 26th is refused and nothing is written; removing one makes room', function (): void {
    $free = groom();
    $targets = collect(range(1, 25))->map(fn (): Profile => favTarget());
    $targets->each(fn (Profile $target) => favSet($free, $target));
    $this->travel(2)->minutes();   // past the per-minute rate limit

    expect(fn () => favSet($free, favTarget()))->toThrow(QuotaExceeded::class)
        ->and(Favorite::query()->count())->toBe(25)->and(favUsed($free))->toBe(25);

    favSet($free, $targets->first(), false);
    expect(favSet($free, favTarget()))->toBeTrue()->and(Favorite::query()->count())->toBe(25);
});

it('§7.3: Gold has no favourites limit', function (): void {
    $gold = groom();
    Subscription::factory()->create(['profile_id' => $gold->profile->id, 'plan_id' => Plan::query()->where('code', PlanCode::Gold->value)->sole()->id]);
    app(EntitlementService::class)->forget($gold->profile);

    foreach (range(1, 30) as $i) {
        favSet($gold, favTarget());
    }

    expect(Favorite::query()->count())->toBe(30);
});

it('A13: too many favourite changes in a minute are refused (A15 setting)', function (): void {
    Setting::factory()->keyed(SettingKey::FavoritesMaxPerMinute, 5)->create();
    Settings::flush();
    $groom = groom();
    foreach (range(1, 5) as $i) {
        favSet($groom, favTarget());
    }

    $refusal = null;
    try {
        favSet($groom, favTarget());
    } catch (EngagementRefused $e) {
        $refusal = $e;
    }

    expect($refusal?->reason)->toBe(EngagementRefused::TOO_FAST)->and(Favorite::query()->count())->toBe(5);

    $this->travel(61)->seconds();
    expect(favSet($groom, favTarget()))->toBeTrue();
});

it('M05: bookmarking today\'s daily match marks it as acted on', function (): void {
    $groom = groom();
    $bride = bride();
    DailyMatch::factory()->create(['profile_id' => $groom->profile->id, 'matched_profile_id' => $bride->profile->id, 'match_date' => DailyBatch::today()]);

    favSet($groom, $bride->profile);

    expect(DailyMatch::query()->where('profile_id', $groom->profile->id)->value('is_interacted'))->toBeTruthy();
});

// ---- Who may bookmark whom (security matrix) --------------------------------------------------

it('refuses with a 404 and writes nothing', function (string $case): void {
    $groom = groom();
    $bride = bride();

    $target = match ($case) {
        'own profile' => $groom->profile,
        'same gender' => groom('+919800000091')->profile,
        'target not ACTIVE' => tap($bride->profile, fn (Profile $p) => $p->forceFill(['status' => ProfileStatus::Suspended])->save()),
        'member blocked the target' => tap($bride->profile, fn (Profile $p) => Block::factory()->create(['blocker_profile_id' => $groom->profile->id, 'blocked_profile_id' => $p->id])),
        'target blocked the member' => tap($bride->profile, fn (Profile $p) => Block::factory()->create(['blocker_profile_id' => $p->id, 'blocked_profile_id' => $groom->profile->id])),
        'suspended member' => tap($bride->profile, fn () => $groom->forceFill(['status' => App\Enums\UserStatus::Suspended])->save()),
    };

    expect(fn () => favSet($groom->refresh(), $target))->toThrow(NotFoundHttpException::class)
        ->and(Favorite::query()->count())->toBe(0)->and(favUsed($groom))->toBe(0);
})->with(['own profile', 'same gender', 'target not ACTIVE', 'member blocked the target', 'target blocked the member', 'suspended member']);

it('a member whose own profile is not approved yet cannot bookmark', function (): void {
    $pending = memberWithPhone('+919800000090', ProfileStatus::PendingReview);
    $pending->profile->forceFill(['gender' => App\Enums\Gender::Male])->save();

    expect(fn () => favSet($pending->refresh(), bride()->profile))->toThrow(EngagementRefused::class)
        ->and(Favorite::query()->count())->toBe(0);
});

it('P1.7b: an admin support session changes nothing in the member\'s favourites', function (): void {
    $groom = groom();
    $bride = bride();
    Favorite::factory()->between($groom->profile, $bride->profile)->create(['note' => 'Met the family']);
    session([Impersonation::SESSION_KEY => 'imp-1']);

    expect(fn () => favSet($groom, favTarget()))->toThrow(ImpersonationRestricted::class)
        ->and(fn () => favSet($groom, $bride->profile, false))->toThrow(ImpersonationRestricted::class)
        ->and(fn () => app(SaveFavoriteNote::class)->handle($groom, $bride->profile->code, 'changed'))->toThrow(ImpersonationRestricted::class)
        ->and(fn () => app(RemoveFavorites::class)->handle($groom, [$bride->profile->code]))->toThrow(ImpersonationRestricted::class)
        ->and(Favorite::query()->sole()->note)->toBe('Met the family');
});

it('a favourite can still be removed after the other profile was hidden or blocked — and the slot comes back', function (): void {
    $groom = groom();
    $bride = bride();
    favSet($groom, $bride->profile);
    Block::factory()->create(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $groom->profile->id]);

    expect(favSet($groom, $bride->profile, false))->toBeFalse()
        ->and(Favorite::query()->count())->toBe(0)->and(favUsed($groom))->toBe(0);
});

// ---- The private note -------------------------------------------------------------------------

it('M06: the private note is saved on the member\'s own favourite, trimmed; empty clears it', function (): void {
    $groom = groom();
    $bride = bride();
    favSet($groom, $bride->profile);
    $save = app(SaveFavoriteNote::class);

    expect($save->handle($groom, $bride->profile->code, '  Met family on 12 Oct  '))->toBeTrue()
        ->and(Favorite::query()->sole()->note)->toBe('Met family on 12 Oct');

    expect($save->handle($groom, $bride->profile->code, '   '))->toBeTrue()
        ->and(Favorite::query()->sole()->note)->toBeNull();
});

it('a note over 200 characters is refused and nothing is written', function (): void {
    $groom = groom();
    $bride = bride();
    favSet($groom, $bride->profile);

    expect(fn () => app(SaveFavoriteNote::class)->handle($groom, $bride->profile->code, str_repeat('a', 201)))->toThrow(ValidationException::class)
        ->and(Favorite::query()->sole()->note)->toBeNull()
        ->and(app(SaveFavoriteNote::class)->handle($groom, $bride->profile->code, str_repeat('a', 200)))->toBeTrue();
});

it('IDOR: a note can only be written on the member\'s OWN favourite', function (string $code): void {
    $groom = groom();
    $other = groom('+919800000089');
    $bride = bride();
    Favorite::factory()->between($other->profile, $bride->profile)->create(['note' => 'theirs']);
    $code = $code === 'someone else\'s favourite' ? $bride->profile->code : $code;

    expect(app(SaveFavoriteNote::class)->handle($groom, $code, 'mine now'))->toBeFalse()
        ->and(Favorite::query()->sole()->note)->toBe('theirs');
})->with(['someone else\'s favourite', 'OPM99999999', 'not-a-code', "OPM1' OR '1'='1"]);

// ---- Removing several ---------------------------------------------------------------------------

it('M06 multi-select: removes only the member\'s own ticked favourites and gives those slots back', function (): void {
    $groom = groom();
    $other = groom('+919800000088');
    [$a, $b, $c] = [favTarget(), favTarget(), favTarget()];
    foreach ([$a, $b, $c] as $target) {
        favSet($groom, $target);
    }
    favSet($other, $a);

    $removed = app(RemoveFavorites::class)->handle($groom, [$a->code, $b->code, 'OPM99999999', 'junk', ['array'], $a->code]);

    expect($removed)->toBe(2)
        ->and(Favorite::query()->where('profile_id', $groom->profile->id)->pluck('favorite_profile_id')->all())->toBe([$c->id])
        ->and(favUsed($groom))->toBe(1)
        ->and(Favorite::query()->where('profile_id', $other->profile->id)->count())->toBe(1)
        ->and(favUsed($other))->toBe(1);
});

it('removes at most 50 at a time', function (): void {
    expect(RemoveFavorites::MAX)->toBe(50);

    $groom = groom();
    $targets = collect(range(1, 3))->map(fn (): Profile => favTarget());
    $targets->each(fn (Profile $t) => Favorite::factory()->between($groom->profile, $t)->create());
    $codes = [...array_map(fn (int $i): string => 'OPM9'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), range(1, 50)), ...$targets->pluck('code')->all()];

    expect(app(RemoveFavorites::class)->handle($groom, $codes))->toBe(0)->and(Favorite::query()->count())->toBe(3);
});

// ---- The list -----------------------------------------------------------------------------------

it('/favorites list: the member\'s own, newest first, with notes — blocked and suspended profiles never listed', function (): void {
    $me = groom();
    $other = groom('+919800000087');
    [$old, $new, $blocked, $suspended] = [favTarget(), favTarget(), favTarget(), favTarget()];
    Favorite::factory()->between($me->profile, $old)->create(['created_at' => now()->subDays(2), 'note' => 'first']);
    Favorite::factory()->between($me->profile, $new)->create(['created_at' => now()->subDay()]);
    Favorite::factory()->between($me->profile, $blocked)->create();
    Favorite::factory()->between($me->profile, $suspended)->create();
    Favorite::factory()->between($other->profile, favTarget())->create();
    Block::factory()->create(['blocker_profile_id' => $blocked->id, 'blocked_profile_id' => $me->profile->id]);
    $suspended->forceFill(['status' => ProfileStatus::Suspended])->save();

    $rows = app(EngagementLists::class)->favorites($me->profile)->get();

    expect($rows->pluck('code')->all())->toBe([$new->code, $old->code])
        ->and($rows->last()->getAttribute('favorite_note'))->toBe('first');
});
