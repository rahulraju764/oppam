<?php

declare(strict_types=1);

use App\Domain\Reports\ReportRange;
use App\Enums\CommsChannel;
use App\Enums\ModerationItemType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModerationItem;
use App\Models\OutboundMessage;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Reports\LiveMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| P9.1a (review fix, Major) — the live A09 numbers proven with fixed data: the activation funnel,
| weekly cohorts (incl. weeks not over yet), conversation reply rate / messages / typical first
| reply, notifications by channel (a failed AND bounced row counts once), unique active members.
*/

beforeEach(function (): void {
    seedMasters();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'Asia/Kolkata'));
    $this->range = ReportRange::of('2026-11-02', '2026-11-19');
    $this->live = app(LiveMetrics::class);
});

/** A member registered at $ist (IST), with a draft profile. */
function funnelMember(string $ist, array $user = [], array $profile = []): User
{
    $at = CarbonImmutable::parse($ist, 'Asia/Kolkata')->utc();
    $member = User::factory()->create(['created_at' => $at, 'phone_verified_at' => null, ...$user]);
    Profile::factory()->for($member)->draft()->create(['completeness' => 10, ...$profile]);

    return $member->refresh();
}

it('A09 funnel: each step counts the period\'s registrations that reached it, as they are now', function (): void {
    funnelMember('2026-11-03 10:00');                                                     // registered only
    funnelMember('2026-11-03 11:00', ['phone_verified_at' => now()]);                      // + phone
    funnelMember('2026-11-04 10:00', ['phone_verified_at' => now()], ['completeness' => 60]);   // + half complete
    $photo = funnelMember('2026-11-05 10:00', ['phone_verified_at' => now()], ['completeness' => 70]);
    DB::table('media')->insert(['id' => (string) Str::ulid(), 'model_type' => (new Profile)->getMorphClass(), 'model_id' => $photo->profile->id, 'uuid' => (string) Str::uuid(),
        'collection_name' => Profile::PHOTOS, 'name' => 'p', 'file_name' => 'p.jpg', 'disk' => 'public', 'size' => 1, 'manipulations' => '[]',
        'custom_properties' => '[]', 'generated_conversions' => '[]', 'responsive_images' => '[]', 'moderation_status' => 'APPROVED', 'created_at' => now(), 'updated_at' => now()]);
    $submitted = funnelMember('2026-11-06 10:00', ['phone_verified_at' => now()], ['completeness' => 90]);
    ModerationItem::factory()->create(['profile_id' => $submitted->profile->id, 'type' => ModerationItemType::ProfileNew]);
    funnelMember('2026-11-07 10:00', ['phone_verified_at' => now()], ['completeness' => 100, 'published_at' => now()]);   // approved
    funnelMember('2026-10-20 10:00', ['phone_verified_at' => now()], ['published_at' => now()]);                         // before the period

    expect(array_values($this->live->funnel($this->range)))->toBe([6, 5, 4, 1, 2, 1]);
});

it('A09 cohorts: the share of a signup week active in each later week; weeks not over yet are empty', function (): void {
    $a = funnelMember('2026-11-03 10:00');   // week of Mon 02 Nov
    funnelMember('2026-11-04 10:00');
    DB::table('member_activity_days')->insert([
        ['user_id' => $a->id, 'day' => '2026-11-10'],   // week 1 (09–15 Nov)
        ['user_id' => $a->id, 'day' => '2026-11-17'],   // week 2 — not over yet on 20 Nov
    ]);

    $cohorts = $this->live->cohorts($this->range);

    expect($cohorts[0]['week'])->toBe('02 Nov')
        ->and($cohorts[0]['members'])->toBe(2)
        ->and($cohorts[0]['weeks'][0])->toBe(50)
        ->and($cohorts[0]['weeks'][1])->toBeNull()
        ->and(array_column($cohorts, 'week'))->toBe(['02 Nov', '09 Nov', '16 Nov']);
});

it('A09 conversations: reply rate, messages and the typical minutes to the first answer from the other member', function (): void {
    $a = Profile::factory()->active()->create();
    $b = Profile::factory()->active()->create();
    $t0 = CarbonImmutable::parse('2026-11-10 10:00', 'Asia/Kolkata')->utc();

    $replied = Conversation::factory()->create(['created_at' => $t0]);
    Message::factory()->create(['conversation_id' => $replied->id, 'sender_profile_id' => $a->id, 'created_at' => $t0]);
    Message::factory()->create(['conversation_id' => $replied->id, 'sender_profile_id' => $a->id, 'created_at' => $t0->addMinutes(5)]);
    Message::factory()->create(['conversation_id' => $replied->id, 'sender_profile_id' => $b->id, 'created_at' => $t0->addMinutes(15)]);
    $silent = Conversation::factory()->create(['created_at' => $t0]);
    Message::factory()->create(['conversation_id' => $silent->id, 'sender_profile_id' => $a->id, 'created_at' => $t0]);
    Conversation::factory()->create(['created_at' => $t0]);   // opened, nobody wrote

    expect($this->live->conversations($this->range))
        ->toBe(['started' => 3, 'replied' => 1, 'messages' => 4, 'median_reply_minutes' => 15]);
});

it('A09 notifications by channel: a failed AND bounced email counts once; in-app read rate from read_at', function (): void {
    $at = CarbonImmutable::parse('2026-11-10 10:00', 'Asia/Kolkata')->utc();
    OutboundMessage::factory()->create(['channel' => CommsChannel::Email, 'created_at' => $at, 'delivered_at' => $at, 'opened_at' => $at]);
    OutboundMessage::factory()->create(['channel' => CommsChannel::Email, 'created_at' => $at, 'failed_at' => $at, 'bounced_at' => $at]);
    OutboundMessage::factory()->create(['channel' => CommsChannel::Sms, 'created_at' => $at, 'delivered_at' => $at]);
    $member = campaignMember();
    foreach ([null, $at] as $read) {
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'profile_liked', 'notifiable_type' => $member->getMorphClass(),
            'notifiable_id' => $member->id, 'data' => '{}', 'read_at' => $read, 'created_at' => $at, 'updated_at' => $at]);
    }

    $n = $this->live->notifications($this->range);

    expect($n['email'])->toBe(['sent' => 2, 'delivered' => 1, 'opened' => 1, 'failed' => 1])
        ->and($n['sms'])->toBe(['sent' => 1, 'delivered' => 1, 'opened' => null, 'failed' => 0])
        ->and($n['in_app'])->toBe(['sent' => 2, 'delivered' => 2, 'opened' => 1, 'failed' => 0]);
});

it('A09 unique active members: distinct members over the period (a member active on many days counts once)', function (): void {
    $a = campaignMember();
    $b = campaignMember(female: false);
    DB::table('member_activity_days')->insert([
        ['user_id' => $a->id, 'day' => '2026-11-03'], ['user_id' => $a->id, 'day' => '2026-11-09'],
        ['user_id' => $b->id, 'day' => '2026-11-19'], ['user_id' => $b->id, 'day' => '2026-11-20'],   // the 20th is outside
    ]);

    expect($this->live->uniqueActive($this->range))->toBe(2)
        ->and($this->live->uniqueActive(ReportRange::of('2026-11-19', '2026-11-19')))->toBe(1);
});
