<?php

declare(strict_types=1);

use App\Actions\Matching\BuildDailyMatches;
use App\Actions\Moderation\ApproveProfile;
use App\Domain\Matching\DailyBatch;
use App\Enums\SettingKey;
use App\Jobs\Matching\GenerateDailyMatches;
use App\Jobs\Matching\GenerateDailyMatchesChunk;
use App\Models\Block;
use App\Models\DailyMatch;
use App\Models\Ignore;
use App\Models\NotificationPreference;
use App\Models\PartnerPreference;
use App\Models\PrivacySetting;
use App\Models\Profile;
use App\Models\ProfileView;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Member\DailyMatchesReady;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

/*
| P2.4 — daily matches (M05 / F06): candidates = the member's "All matches" minus viewed and
| recently shown profiles; the best by relevance, scored; at most 3 per district; the top
| `matching.daily_match_count`; idempotent per date; "matches ready" email by preference; fan-out
| in chunks on the `matching` queue; generated on approval; old batches pruned.
*/

beforeEach(function (): void {
    seedMasters();
    Notification::fake();
});

/** A groom with no partner preferences (so every live bride is a candidate). */
function dailyGroom(string $phone = '+919833300001'): User
{
    $groom = groom($phone);
    PartnerPreference::query()->where('profile_id', $groom->profile->id)->delete();

    return $groom->refresh();
}

function dailyBride(array $state = []): Profile
{
    return Profile::factory()->female()->active()->create($state);
}

function buildFor(User $member, ?string $date = null): int
{
    return app(BuildDailyMatches::class)->handle($member->profile->load('user'), $date);
}

it('F06: stores the top daily_match_count, best score first, and emails "matches ready"', function (): void {
    Setting::factory()->keyed(SettingKey::DailyMatchCount, 5)->create();
    $groom = dailyGroom();
    foreach (range(1, 8) as $i) {
        dailyBride(['district_id' => null]);
    }

    expect(buildFor($groom))->toBe(5);

    $scores = DailyMatch::query()->where('profile_id', $groom->profile->id)->orderByDesc('score')->pluck('score')->all();
    expect($scores)->toHaveCount(5)
        ->and(DailyMatch::query()->where('match_date', DailyBatch::today())->count())->toBe(5);
    Notification::assertSentTo($groom, DailyMatchesReady::class, fn (DailyMatchesReady $n): bool => $n->count === 5);
});

it('F06 diversity: at most 3 from one district', function (): void {
    $groom = dailyGroom();
    foreach (range(1, 6) as $i) {
        dailyBride(['district_id' => keralaDistrictId('KANNUR')]);
    }
    foreach (range(1, 2) as $i) {
        dailyBride(['district_id' => keralaDistrictId('KOLLAM')]);
    }

    expect(buildFor($groom))->toBe(5);
    $kannur = DailyMatch::query()->join('profiles', 'profiles.id', '=', 'daily_matches.matched_profile_id')
        ->where('profiles.district_id', keralaDistrictId('KANNUR'))->count();
    expect($kannur)->toBe(3);
});

it('F06 exclusions: blocked (both ways), ignored, incognito, already viewed and shown in the last 30 days', function (): void {
    $groom = dailyGroom();
    $me = $groom->profile;
    $blocked = dailyBride();
    $blocker = dailyBride();
    $ignored = dailyBride();
    $incognito = dailyBride();
    $viewed = dailyBride();
    $shownLastWeek = dailyBride();
    $shownLongAgo = dailyBride();
    $fresh = dailyBride();
    Block::factory()->create(['blocker_profile_id' => $me->id, 'blocked_profile_id' => $blocked->id]);
    Block::factory()->create(['blocker_profile_id' => $blocker->id, 'blocked_profile_id' => $me->id]);
    Ignore::factory()->create(['ignorer_profile_id' => $me->id, 'ignored_profile_id' => $ignored->id]);
    (PrivacySetting::query()->whereKey($incognito->id)->first() ?? new PrivacySetting)->forceFill(['profile_id' => $incognito->id, 'incognito' => true])->save();
    ProfileView::factory()->create(['viewer_profile_id' => $me->id, 'viewed_profile_id' => $viewed->id]);
    DailyMatch::factory()->create(['profile_id' => $me->id, 'matched_profile_id' => $shownLastWeek->id, 'match_date' => now('Asia/Kolkata')->subDays(7)->toDateString()]);
    DailyMatch::factory()->create(['profile_id' => $me->id, 'matched_profile_id' => $shownLongAgo->id, 'match_date' => now('Asia/Kolkata')->subDays(40)->toDateString()]);

    buildFor($groom);

    $today = DailyMatch::query()->where('profile_id', $me->id)->where('match_date', DailyBatch::today())->pluck('matched_profile_id')->sort()->values()->all();
    expect($today)->toBe(collect([$fresh->id, $shownLongAgo->id])->sort()->values()->all());
});

it('F06: the member\'s partner preferences decide the candidates', function (): void {
    $groom = dailyGroom();
    $pref = PartnerPreference::factory()->make(['age_min' => 24, 'age_max' => 28, 'religion_ids' => null, 'marital_statuses' => null,
        'caste_ids' => null, 'mother_tongue_ids' => null, 'district_ids' => null]);
    $pref->profile_id = $groom->profile->id;
    $pref->save();
    $fits = dailyBride(['dob' => now()->subYears(26)->subMonth()]);
    dailyBride(['dob' => now()->subYears(35)]);

    buildFor($groom->refresh());

    expect(DailyMatch::query()->pluck('matched_profile_id')->all())->toBe([$fits->id]);
});

it('idempotent: a second run on the same date adds nothing and sends no second email', function (): void {
    $groom = dailyGroom();
    dailyBride();
    buildFor($groom);
    dailyBride();

    expect(buildFor($groom))->toBe(0)
        ->and(DailyMatch::query()->count())->toBe(1);
    Notification::assertSentToTimes($groom, DailyMatchesReady::class, 1);
});

it('nothing for a member who is not live, and no email when there is nothing to show', function (): void {
    $groom = dailyGroom();
    $groom->profile->forceFill(['status' => App\Enums\ProfileStatus::Suspended])->save();
    dailyBride();
    expect(buildFor($groom->refresh()))->toBe(0);

    $lonely = dailyGroom('+919833300002');
    Profile::query()->where('gender', 'FEMALE')->update(['status' => App\Enums\ProfileStatus::Hidden->value]);
    expect(buildFor($lonely))->toBe(0);
    Notification::assertNothingSent();
});

it('M08: no email when the member turned daily_matches_ready emails off; the batch is still made', function (): void {
    $groom = dailyGroom();
    $pref = new NotificationPreference(['event' => DailyMatchesReady::EVENT, 'email' => false]);
    $pref->user_id = $groom->id;
    $pref->save();
    dailyBride();

    expect(buildFor($groom))->toBe(1);
    Notification::assertNothingSent();
});

it('F06 fan-out: one chunk job per 1,000 live members on the matching queue; old batches pruned', function (): void {
    Bus::fake([GenerateDailyMatchesChunk::class]);
    $groom = dailyGroom();
    dailyBride();
    $old = DailyMatch::factory()->create(['profile_id' => $groom->profile->id, 'match_date' => now('Asia/Kolkata')->subDays(40)->toDateString()]);
    $recent = DailyMatch::factory()->create(['profile_id' => $groom->profile->id, 'match_date' => now('Asia/Kolkata')->subDays(10)->toDateString()]);

    (new GenerateDailyMatches)->handle();

    Bus::assertDispatched(GenerateDailyMatchesChunk::class, fn (GenerateDailyMatchesChunk $job): bool => in_array($groom->profile->id, $job->profileIds, true)
        && count($job->profileIds) <= GenerateDailyMatches::CHUNK && $job->queue === 'matching');
    expect(DailyMatch::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(DailyMatch::query()->whereKey($recent->id)->exists())->toBeTrue();
});

it('the chunk job builds every member in it', function (): void {
    $a = dailyGroom();
    $b = dailyGroom('+919833300003');
    dailyBride();

    (new GenerateDailyMatchesChunk([$a->profile->id, $b->profile->id]))->handle(app(BuildDailyMatches::class));

    expect(DailyMatch::query()->distinct()->count('profile_id'))->toBe(2);
});

it('A04: approving a profile queues its first daily matches', function (): void {
    Bus::fake([GenerateDailyMatchesChunk::class]);
    seedAdminRoles();
    $member = memberWithPhone('+919833300004', App\Enums\ProfileStatus::PendingReview);
    $item = App\Models\ModerationItem::factory()->create(['profile_id' => $member->profile->id, 'type' => App\Enums\ModerationItemType::ProfileNew]);

    app(ApproveProfile::class)->handle(adminWithRole(), $item);

    Bus::assertDispatched(GenerateDailyMatchesChunk::class, fn (GenerateDailyMatchesChunk $job): bool => $job->profileIds === [$member->profile->id]);
});

it('M05 acceptance: the 05:00 IST run (23:30 UTC the day before) builds every live member\'s batch for the IST date shown on /matches/daily', function (): void {
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-10-04 23:30:00', 'UTC'));   // 05:00 IST on 5 October
    $members = [dailyGroom('+919833300011'), dailyGroom('+919833300012'), dailyGroom('+919833300013')];
    foreach (range(1, 4) as $i) {
        dailyBride();
    }

    (new GenerateDailyMatches)->handle();   // sync queue: the chunk jobs run here, end to end

    expect(DailyBatch::today())->toBe('2026-10-05');
    foreach ($members as $member) {
        expect(DailyMatch::query()->where('profile_id', $member->profile->id)->where('match_date', '2026-10-05')->count())->toBe(4);
    }
    expect(DailyMatch::query()->where('match_date', '!=', '2026-10-05')->count())->toBe(0);
});

it('F06: GenerateDailyMatches is scheduled daily at 05:00 Asia/Kolkata', function (): void {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e): bool => str_contains((string) $e->description, GenerateDailyMatches::class));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 5 * * *')
        ->and($event->timezone)->toBe('Asia/Kolkata');
});

it('P2.4 review: a queued job is never re-reserved while it may still run (retry_after > every job timeout)', function (): void {
    $timeouts = collect([GenerateDailyMatches::class, GenerateDailyMatchesChunk::class, App\Jobs\Search\SendSavedSearchAlerts::class, App\Jobs\Members\PurgeDeletedMembers::class])
        ->map(fn (string $job): int => (int) (new ReflectionClass($job))->getProperty('timeout')->getDefaultValue());

    foreach (['database', 'redis'] as $connection) {
        expect((int) config("queue.connections.{$connection}.retry_after"))->toBeGreaterThan($timeouts->max());
    }
});

it('P2.4 review: a second build of the same member and date waits on the lock and does nothing', function (): void {
    $groom = dailyGroom();
    dailyBride();
    $lock = Illuminate\Support\Facades\Cache::lock('daily-matches:'.$groom->profile->id.':'.DailyBatch::today(), 60);
    $lock->get();

    expect(buildFor($groom))->toBe(0)->and(DailyMatch::query()->count())->toBe(0);
    $lock->release();
    expect(buildFor($groom))->toBe(1);
});
