<?php

declare(strict_types=1);

use App\Actions\Activity\RecordMemberActivity;
use App\Actions\Admin\Members\PurgeDeletedMember;
use App\Actions\Auth\RegisterMember;
use App\Actions\Search\SearchProfiles;
use App\Data\Search\SearchCriteria;
use App\Domain\Reports\SignupAttribution;
use App\Enums\SettingKey;
use App\Enums\SignupSource;
use App\Http\Middleware\CaptureSignupSource;
use App\Jobs\Reports\PruneReportCapture;
use App\Models\Broker;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| P9.1a — A09 data capture (owner 2026-11-12: capture the cheap missing data from now on):
| signup source / utm (first touch, stamped once at registration — rule 5: registration path),
| one activity row per member per IST day, an anonymous search log (filter keys only), retention.
*/

beforeEach(function (): void {
    fakeSms();
    seedMasters();
    Cache::flush();
});

function registerFrom(?string $sourceCookie = null, ?string $referral = null, string $mobile = '9876543210'): User
{
    $user = app(RegisterMember::class)->handle(registrationData([
        'phone' => PhoneNumber::fromParts('91', $mobile), 'email' => null,
        'referralCode' => $referral, 'sourceCookie' => $sourceCookie,
    ]), '10.0.0.1')->user;
    expect($user)->not->toBeNull();

    return $user->refresh();
}

it('A09 signup source: organic by default; a marketing link (utm cookie) → UTM with the cleaned values; a resolved bureau code wins', function (): void {
    expect(registerFrom()->signup_source)->toBe(SignupSource::Organic);

    $utm = registerFrom(SignupAttribution::encode(['source' => 'facebook', 'medium' => 'cpc', 'campaign' => 'onam25']), mobile: '9876543211');
    expect($utm->signup_source)->toBe(SignupSource::Utm)
        ->and($utm->signup_utm)->toBe(['source' => 'facebook', 'medium' => 'cpc', 'campaign' => 'onam25']);

    $broker = Broker::factory()->create();
    $both = registerFrom(SignupAttribution::encode(['source' => 'google']), $broker->code, '9876543212');
    expect($both->signup_source)->toBe(SignupSource::Broker);
});

it('A09 signup source (rule 5): registration never fails on a garbage source cookie — it is simply organic', function (string $cookie): void {
    $user = registerFrom($cookie);

    expect($user->signup_source)->toBe(SignupSource::Organic)->and($user->signup_utm)->toBeNull();
})->with([
    'empty parts' => ['||'],
    'only symbols' => ['<>|"\'|;--'],
    'huge' => [str_repeat('|', 5000)],
    'not utf-8' => ["\xff\xfe|\x00"],
]);

it('A09 signup source: the first-touch cookie keeps only [a-z0-9_-], 40 characters each, and is never overwritten', function (): void {
    $this->get(memberUrl('/?utm_source=Face<script>Book&utm_medium='.str_repeat('x', 80).'&utm_campaign=Onam%2025'))
        ->assertCookie(CaptureSignupSource::COOKIE, 'facescriptbook|'.str_repeat('x', 40).'|onam25');

    $cookie = collect($this->get(memberUrl('/?utm_source=Face<script>Book&utm_medium=cpc'))->headers->getCookies())
        ->first(fn ($c) => $c->getName() === CaptureSignupSource::COOKIE);
    expect(SignupAttribution::fromQuery(['utm_source' => 'Face<script>Book', 'utm_medium' => str_repeat('x', 80), 'utm_campaign' => 'Onam 25']))
        ->toBe(['source' => 'facescriptbook', 'medium' => str_repeat('x', 40), 'campaign' => 'onam25']);

    // The cookie jar is one object for the whole test (a real request gets a fresh one): empty what the requests above queued.
    app('cookie')->flushQueuedCookies();
    $this->withCookie(CaptureSignupSource::COOKIE, 'google||')->get(memberUrl('/?utm_source=bing'))->assertCookieMissing(CaptureSignupSource::COOKIE);
    $this->get(memberUrl('/?ref=x'))->assertCookieMissing(CaptureSignupSource::COOKIE);   // no utm → no cookie
    expect($cookie)->not->toBeNull()
        ->and(SignupAttribution::fromQuery(['utm_source' => '<>!!']))->toBeNull()
        ->and(SignupAttribution::decode('a|b|c|d|e'))->toBe(['source' => 'a', 'medium' => 'b', 'campaign' => 'c']);
});

it('A09 daily activity: one row per member per IST day, however often they come back', function (): void {
    $member = campaignMember();
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-11-14 23:50', 'Asia/Kolkata'));
    app(RecordMemberActivity::class)->handle($member);
    $this->travel(6)->minutes();   // 23:56 — debounce passed, same IST day
    app(RecordMemberActivity::class)->handle($member);
    $this->travel(10)->minutes();  // 00:06 the next IST day
    app(RecordMemberActivity::class)->handle($member);

    expect(DB::table('member_activity_days')->where('user_id', $member->id)->orderBy('day')->pluck('day')->all())
        ->toBe(['2026-11-14', '2026-11-15']);
});

it('A09 search log: a new search records only the filter KEYS used, the result count and the sort — never values or who searched; "load more" adds nothing', function (): void {
    $member = campaignMember();
    $criteria = SearchCriteria::fromInput(['religion' => '3', 'age_min' => '25', 'photo' => '1']);

    $result = app(SearchProfiles::class)->handle($member, $criteria);
    app(SearchProfiles::class)->handle($member, $criteria, $result->page->nextCursor ?? 'x', withCount: false);

    $log = DB::table('search_logs')->sole();
    expect(json_decode((string) $log->filters, true))->toBe(['age_min', 'photo', 'religion'])
        ->and((int) $log->result_count)->toBe($result->total)
        ->and(array_keys((array) $log))->not->toContain('user_id')
        ->and(json_encode($log))->not->toContain($member->id)->not->toContain('"3"');
});

it('A09 capture retention: old activity days and search logs are pruned per the A15 settings; purging a member removes their days', function (): void {
    $member = campaignMember();
    $today = now('Asia/Kolkata');
    DB::table('member_activity_days')->insert([
        ['user_id' => $member->id, 'day' => $today->copy()->subDays(App\Support\Facades\Settings::int(SettingKey::ReportsActivityRetentionDays) + 1)->toDateString()],
        ['user_id' => $member->id, 'day' => $today->copy()->subDays(10)->toDateString()],
    ]);
    DB::table('search_logs')->insert([
        ['day' => $today->copy()->subDays(91)->toDateString(), 'filters' => '[]', 'result_count' => 1, 'sort' => null, 'created_at' => now()],
        ['day' => $today->copy()->subDays(5)->toDateString(), 'filters' => '[]', 'result_count' => 1, 'sort' => null, 'created_at' => now()],
    ]);

    app()->call([new PruneReportCapture, 'handle']);
    expect(DB::table('member_activity_days')->count())->toBe(1)->and(DB::table('search_logs')->count())->toBe(1);

    $member->delete();
    $this->travel(App\Support\Facades\Settings::int(SettingKey::MembersPurgeAfterDays) + 1)->days();
    app(PurgeDeletedMember::class)->handle(User::withTrashed()->findOrFail($member->id));
    expect(DB::table('member_activity_days')->where('user_id', $member->id)->exists())->toBeFalse();
});
