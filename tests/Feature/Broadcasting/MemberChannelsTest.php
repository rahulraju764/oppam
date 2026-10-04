<?php

declare(strict_types=1);

use App\Broadcasting\ChatChannel;
use App\Broadcasting\InboxChannel;
use App\Broadcasting\OnlinePresenceChannel;
use App\Broadcasting\PresenceChatChannel;
use App\Broadcasting\UserChannel;
use App\Enums\ConversationStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserStatus;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\PrivacySetting;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Support\Facades\Cache;

/*
| P3.1 — member channel authorisation (PRD §9.3): every callback re-checks the database. Owner /
| participant yes, anyone else no; a blocked pair, a closed thread, a suspended account no;
| presence only for visible members (codes only), never during an admin support session.
*/

beforeEach(function (): void {
    seedMasters();
});

/** @return array{0: User, 1: User, 2: Conversation} */
function chatPair(): array
{
    $groom = groom();
    $bride = bride();
    $thread = Conversation::factory()->between($groom->profile, $bride->profile)->create();

    return [$groom, $bride, $thread];
}

function setPrivacy(User $member, array $values): void
{
    (PrivacySetting::query()->whereKey($member->profile->id)->first() ?? (new PrivacySetting)->forceFill(['profile_id' => $member->profile->id]))
        ->forceFill($values)->save();
}

it('App.Models.User.{id}: only that member, only while active', function (): void {
    seedAdminRoles();
    $me = groom();
    $other = bride();
    $channel = app(UserChannel::class);

    expect($channel->join($me, (string) $me->id))->toBeTrue()
        ->and($channel->join($other, (string) $me->id))->toBeFalse()
        ->and($channel->join(adminWithRole(), (string) $me->id))->toBeFalse();

    $me->forceFill(['status' => UserStatus::Suspended])->save();
    expect($channel->join($me->refresh(), (string) $me->id))->toBeFalse();
});

it('inbox.{profile}: only the profile\'s owner', function (): void {
    $me = groom();
    $other = bride();

    expect(app(InboxChannel::class)->join($me, $me->profile->id))->toBeTrue()
        ->and(app(InboxChannel::class)->join($other, $me->profile->id))->toBeFalse()
        ->and(app(InboxChannel::class)->join($me, '01JUNKJUNKJUNKJUNKJUNKJUNK'))->toBeFalse();
});

it('chat.{conversation}: participants yes, anyone else no', function (): void {
    [$groom, $bride, $thread] = chatPair();
    $outsider = groom('+919844400001');

    expect(app(ChatChannel::class)->join($groom, $thread->id))->toBeTrue()
        ->and(app(ChatChannel::class)->join($bride, $thread->id))->toBeTrue()
        ->and(app(ChatChannel::class)->join($outsider, $thread->id))->toBeFalse()
        ->and(app(ChatChannel::class)->join($groom, '01JUNKJUNKJUNKJUNKJUNKJUNK'))->toBeFalse();
});

it('R-M03-1: chat.{conversation} refuses a blocked pair, in both directions', function (string $who): void {
    [$groom, $bride, $thread] = chatPair();
    $who === 'bride blocked'
        ? Block::factory()->create(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $groom->profile->id])
        : Block::factory()->create(['blocker_profile_id' => $groom->profile->id, 'blocked_profile_id' => $bride->profile->id]);

    expect(app(ChatChannel::class)->join($groom, $thread->id))->toBeFalse()
        ->and(app(ChatChannel::class)->join($bride, $thread->id))->toBeFalse()
        ->and(app(PresenceChatChannel::class)->join($groom, $thread->id))->toBeFalse();
})->with(['bride blocked', 'groom blocked']);

it('chat.{conversation}: a CLOSED thread can\'t be followed; a FROZEN one can (read-only)', function (): void {
    [$groom, , $thread] = chatPair();

    $thread->forceFill(['status' => ConversationStatus::Frozen])->save();
    expect(app(ChatChannel::class)->join($groom, $thread->id))->toBeTrue();

    $thread->forceFill(['status' => ConversationStatus::Closed])->save();
    expect(app(ChatChannel::class)->join($groom, $thread->id))->toBeFalse();
});

it('chat.{conversation}: a suspended participant is refused', function (): void {
    [$groom, , $thread] = chatPair();
    $groom->forceFill(['status' => UserStatus::Suspended])->save();

    expect(app(ChatChannel::class)->join($groom->refresh(), $thread->id))->toBeFalse();
});

it('presence-chat.{conversation}: participants are presented by profile code only', function (): void {
    [$groom, , $thread] = chatPair();

    expect(app(PresenceChatChannel::class)->join($groom, $thread->id))->toBe(['code' => $groom->profile->code])
        ->and(app(PresenceChatChannel::class)->join(groom('+919844400002'), $thread->id))->toBeFalse();
});

it('presence.online: a visible member joins with their code only, and it refreshes last seen', function (): void {
    $me = groom();
    Cache::flush();

    expect(app(OnlinePresenceChannel::class)->join($me))->toBe(['code' => $me->profile->code])
        ->and($me->refresh()->last_seen_at)->not->toBeNull()
        ->and($me->profile->refresh()->last_active_at)->not->toBeNull();
});

it('presence.online: hidden online status, incognito, a non-ACTIVE profile and a suspended account never join', function (string $case): void {
    $me = groom();
    match ($case) {
        'hides online status' => setPrivacy($me, ['show_online_status' => false]),
        'incognito' => setPrivacy($me, ['incognito' => true]),
        'profile hidden' => $me->profile->forceFill(['status' => ProfileStatus::Hidden])->save(),
        'account suspended' => $me->forceFill(['status' => UserStatus::Suspended])->save(),
    };

    expect(app(OnlinePresenceChannel::class)->join($me->refresh()))->toBeFalse();
})->with(['hides online status', 'incognito', 'profile hidden', 'account suspended']);

it('P1.7b: an admin support session never puts the member online', function (): void {
    $me = groom();
    $running = App\Models\ImpersonationSession::factory()->create(['user_id' => $me->id]);
    session([Impersonation::SESSION_KEY => $running->id]);

    expect(app(OnlinePresenceChannel::class)->join($me))->toBeFalse();
});

it('POST /broadcasting/auth (member site): signs the member\'s own channels, refuses others and guests', function (): void {
    pusherBroadcasting();
    $this->seed(Database\Seeders\PlansSeeder::class);   // the 403 page shows the member chrome
    [$groom, $bride, $thread] = chatPair();
    $auth = fn (string $channel) => $this->post(memberUrl('/broadcasting/auth'), ['socket_id' => '1234.5678', 'channel_name' => $channel]);

    $this->post(memberUrl('/broadcasting/auth'), ['socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.'.$groom->id])
        ->assertRedirect();   // guest → sign in

    $this->actingAs($groom, 'web');
    $auth('private-App.Models.User.'.$groom->id)->assertOk()->assertJsonStructure(['auth']);
    $auth('private-App.Models.User.'.$bride->id)->assertForbidden();
    $auth('private-chat.'.$thread->id)->assertOk();
    $auth('presence-presence.online')->assertOk()->assertJsonPath('channel_data', fn (string $data): bool => json_decode($data, true)['user_info'] === ['code' => $groom->profile->code]);
    $auth('private-admin.queues')->assertForbidden();
});

it('the admin panel has its own /broadcasting/auth behind the panel middleware (admin guard only)', function (): void {
    seedAdminRoles();
    pusherBroadcasting();
    $auth = fn (string $channel) => $this->post(adminUrl('/broadcasting/auth'), ['socket_id' => '1234.5678', 'channel_name' => $channel]);

    $auth('private-admin.queues')->assertRedirect();   // not signed in to the panel

    signInAdmin($this, adminWithRole('moderator'));
    $auth('private-admin.queues')->assertOk()->assertJsonStructure(['auth']);
    $auth('private-App.Models.User.'.groom()->id)->assertForbidden();   // member channels: web guard only

    $domains = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => $route->uri() === 'broadcasting/auth')
        ->map(fn ($route): ?string => $route->getDomain())->sort()->values()->all();
    expect($domains)->toBe(collect([config('oppam.admin_domain'), config('oppam.app_domain')])->sort()->values()->all());   // no host-less copy
});
