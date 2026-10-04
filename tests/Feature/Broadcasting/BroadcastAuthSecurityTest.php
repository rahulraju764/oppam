<?php

declare(strict_types=1);

use App\Actions\Admin\Members\SuspendMember;
use App\Actions\Auth\SignInMember;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Database\Seeders\PlansSeeder;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Exceptions;

/*
| P3.1 review — the security matrix at the /broadcasting/auth endpoint: presence rosters never
| carry a user ULID; an unverified or suspended member is refused; a support session never joins
| presence, is not audited per subscription, and its tab is marked; a WebSocket outage never
| breaks a suspension.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    pusherBroadcasting();
});

function authChannel(string $channel): Illuminate\Testing\TestResponse
{
    return test()->post(memberUrl('/broadcasting/auth'), ['socket_id' => '1234.5678', 'channel_name' => $channel]);
}

/** @return array<string, mixed> */
function presenceData(Illuminate\Testing\TestResponse $response): array
{
    return json_decode((string) $response->json('channel_data'), true);
}

/** Sign this test client in as $member inside a running admin support session. */
function supportSessionFor(User $member): ImpersonationSession
{
    $admin = adminWithRole();
    $row = ImpersonationSession::factory()->create(['admin_user_id' => $admin->id, 'admin_label' => $admin->email, 'user_id' => $member->id]);
    test()->withSession([
        auth()->guard('web')->getName() => $member->id,
        SignInMember::EPOCH_SESSION_KEY => $member->session_epoch,
        Impersonation::SESSION_KEY => $row->id,
    ]);

    return $row;
}

it('P3.1 review Blocker: presence member lists never carry the user ULID — the profile code instead', function (): void {
    $groom = groom();
    $bride = bride();
    $thread = Conversation::factory()->between($groom->profile, $bride->profile)->create();
    $this->actingAs($groom, 'web');

    foreach (['presence-presence.online', 'presence-presence-chat.'.$thread->id] as $channel) {
        $data = presenceData(authChannel($channel)->assertOk());
        expect($data['user_id'])->toBe($groom->profile->code)->not->toBe((string) $groom->id)
            ->and(json_encode($data))->not->toContain((string) $groom->id);
    }
});

it('an account without a profile is presented by an opaque hash, never its ULID', function (): void {
    $user = User::factory()->create();

    expect($user->getAuthIdentifierForBroadcasting())->toStartWith('u-')->not->toContain((string) $user->id);
});

it('security matrix: an unverified-phone member and a suspended member are refused at the endpoint', function (): void {
    $unverified = groom();
    $unverified->forceFill(['phone_verified_at' => null])->save();
    $this->actingAs($unverified->refresh(), 'web');
    authChannel('private-App.Models.User.'.$unverified->id)->assertRedirect();

    $suspended = bride();
    $suspended->forceFill(['status' => UserStatus::Suspended])->save();
    $this->actingAs($suspended->refresh(), 'web');
    expect(authChannel('private-App.Models.User.'.$suspended->id)->status())->not->toBe(200);
});

it('P1.7b: a support session never joins presence, is not audited per subscription, and its tab is marked', function (): void {
    $member = groom();
    supportSessionFor($member);

    authChannel('presence-presence.online')->assertForbidden();
    authChannel('private-App.Models.User.'.$member->id)->assertOk();   // forced logout still reaches the support tab
    expect(AuditLog::query()->where('action', 'impersonation.action')->count())->toBe(0);

    $this->get(memberUrl('/me'))->assertOk()
        ->assertSee('<meta name="oppam-impersonating" content="on">', false)
        ->assertSee('<meta name="oppam-presence" content="off">', false);
});

it('P3.1 review: a WebSocket outage never fails a suspension (the live signal is best-effort, reported)', function (): void {
    Exceptions::fake();
    app(BroadcastManager::class)->extend('down', fn () => new class extends Broadcaster
    {
        public function auth($request): mixed
        {
            return null;
        }

        public function validAuthenticationResponse($request, $result): mixed
        {
            return null;
        }

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new Illuminate\Broadcasting\BroadcastException('Reverb is down');
        }
    });
    config(['broadcasting.connections.down' => ['driver' => 'down'], 'broadcasting.default' => 'down']);
    app(BroadcastManager::class)->purge();
    $member = groom();

    app(SuspendMember::class)->handle(adminWithRole(), $member, 'Abuse investigation.');

    expect($member->refresh()->status)->toBe(UserStatus::Suspended);
    Exceptions::assertReported(Illuminate\Broadcasting\BroadcastException::class);
});
