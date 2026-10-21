<?php

declare(strict_types=1);

use App\Actions\Admin\Chat\EndBreakGlass;
use App\Actions\Admin\Chat\OpenBreakGlass;
use App\Actions\Admin\Chat\ReadBreakGlassTranscript;
use App\Actions\Chat\SendMessage;
use App\Enums\BreakGlassReference;
use App\Enums\PlanCode;
use App\Exceptions\Chat\BreakGlassEnded;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Models\MessageFlag;
use App\Queries\Chat\BreakGlassTranscript;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P4.3b — A13 break-glass read (PRD A13 "super admin / compliance only, mandatory reason + linked case
| or legal request, time-limited (30 min), watermarked viewer, audited, member-visible in data-access
| log on request"). A13 acceptance: every break-glass read writes an audit row with reason.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    fakeMediaDisks();
});

const BREAK_GLASS_REASON = 'Police request about a reported marriage fraud';

function breakGlassOpen(AdminUser $admin, Conversation $thread, string $type = 'LEGAL', string $reference = 'KL/POL/2026/123', string $reason = BREAK_GLASS_REASON): BreakGlassGrant
{
    return app(OpenBreakGlass::class)->handle($admin, $thread, $reason, $type, $reference);
}

it('§8.4: chat.read_conversation is a super admin key — no other seeded role (not even read_only) holds it', function (): void {
    expect(adminWithRole('super_admin')->can('chat.read_conversation'))->toBeTrue();
    foreach (['moderator', 'verification_officer', 'support', 'finance', 'content_editor', 'read_only'] as $role) {
        expect(adminWithRole($role)->can('chat.read_conversation'))->toBeFalse();
    }
});

it('A13: opening needs the permission, a 20+ character reason and a reference — a flag of THIS thread or a legal request number', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    [$otherGroom, $otherBride] = chatPairOf(groom('+919800000801'), bride('+919800000802'));
    $foreignFlag = MessageFlag::query()->where('message_id', chatSend($otherGroom, $otherBride, 'you are a bastard')->id)->sole();
    $admin = adminWithRole('super_admin');

    expect(fn () => breakGlassOpen(adminWithRole('moderator'), $thread))->toThrow(AuthorizationException::class)
        ->and(fn () => breakGlassOpen($admin, $thread, reason: 'too short'))->toThrow(ValidationException::class)
        ->and(fn () => breakGlassOpen($admin, $thread, 'LEGAL', '<script>'))->toThrow(ValidationException::class)
        ->and(fn () => breakGlassOpen($admin, $thread, 'CASE', 'X1'))->toThrow(ValidationException::class)
        ->and(fn () => breakGlassOpen($admin, $thread, 'FLAG', $foreignFlag->id))->toThrow(ValidationException::class)
        ->and(BreakGlassGrant::query()->count())->toBe(0);

    $ownFlag = MessageFlag::query()->where('message_id', chatSend($groom, $bride, 'you are a bastard')->id)->sole();
    $grant = breakGlassOpen($admin, $thread, 'FLAG', strtoupper($ownFlag->id));

    expect($grant->reference_type)->toBe(BreakGlassReference::Flag)->and($grant->reference)->toBe($ownFlag->id)
        ->and($grant->expires_at->diffInMinutes(now()->addMinutes(30), true))->toBeLessThan(1);
    $log = AuditLog::query()->where('action', 'chat.break_glass.opened')->sole();
    expect($log->reason)->toBe(BREAK_GLASS_REASON)->and($log->subject_id)->toBe($thread->id)
        ->and($log->subject_label)->toContain($groom->profile->code)->toContain($bride->profile->code)
        ->and($log->after)->toMatchArray(['grant_id' => $grant->id, 'reference_type' => 'FLAG']);
});

it('opening again while a grant runs returns the same grant — no second clock, no second audit row', function (): void {
    [, , $thread] = chatConnected();
    $admin = adminWithRole('super_admin');

    $first = breakGlassOpen($admin, $thread);
    $again = breakGlassOpen($admin, $thread, reason: 'Another reason, also long enough');

    expect($again->id)->toBe($first->id)->and(AuditLog::query()->where('action', 'chat.break_glass.opened')->count())->toBe(1);
});

it('A13 acceptance: every page read writes an audit row with the reason and the message range; "load earlier" pages back by id', function (): void {
    [$groom, $bride, $thread] = chatConnected(PlanCode::Gold, PlanCode::Gold);
    foreach (range(1, BreakGlassTranscript::PAGE + 5) as $i) {
        app(SendMessage::class)->handle($i % 2 ? $groom : $bride, ($i % 2 ? $bride : $groom)->profile, 'Message number '.$i, (string) Str::uuid());
    }
    $admin = adminWithRole('super_admin');
    $grant = breakGlassOpen($admin, $thread);
    $read = app(ReadBreakGlassTranscript::class);

    $newest = $read->handle($admin, $grant);
    $earlier = $read->handle($admin, $grant, $newest->oldestId);

    expect($newest->messages)->toHaveCount(BreakGlassTranscript::PAGE)->and($newest->hasEarlier)->toBeTrue()
        ->and($newest->messages[BreakGlassTranscript::PAGE - 1]->text)->toBe('Message number '.(BreakGlassTranscript::PAGE + 5))
        ->and($earlier->hasEarlier)->toBeFalse()
        ->and($earlier->messages[0]->senderLabel)->toBe('Oppam');   // "You are now connected"
    $reads = AuditLog::query()->where('action', 'chat.break_glass.read')->orderBy('id')->get();
    expect($reads)->toHaveCount(2)
        ->and($reads->every(fn (AuditLog $log): bool => $log->reason === BREAK_GLASS_REASON))->toBeTrue()
        ->and($reads[0]->after)->toMatchArray(['grant_id' => $grant->id, 'messages' => BreakGlassTranscript::PAGE, 'to_message_id' => $newest->newestId]);
});

it('security: another admin\'s grant is 404; an expired or ended grant reads nothing; losing the permission stops the read', function (): void {
    [, , $thread] = chatConnected();
    $admin = adminWithRole('super_admin');
    $grant = breakGlassOpen($admin, $thread);
    $read = app(ReadBreakGlassTranscript::class);

    expect(fn () => $read->handle(adminWithRole('super_admin'), $grant))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(EndBreakGlass::class)->handle(adminWithRole('super_admin'), $grant))->toThrow(NotFoundHttpException::class);

    $this->travel(31)->minutes();
    expect(fn () => $read->handle($admin, $grant->refresh()))->toThrow(BreakGlassEnded::class);
    $this->travelBack();

    $second = breakGlassOpen($admin, $thread);
    expect(app(EndBreakGlass::class)->handle($admin, $second))->toBeTrue()
        ->and(app(EndBreakGlass::class)->handle($admin, $second->refresh()))->toBeFalse()
        ->and(fn () => $read->handle($admin, $second->refresh()))->toThrow(BreakGlassEnded::class)
        ->and(AuditLog::query()->where('action', 'chat.break_glass.ended')->count())->toBe(1);

    $third = breakGlassOpen($admin, $thread);
    $admin->syncRoles(['moderator']);
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    expect(fn () => $read->handle($admin->refresh(), $third))->toThrow(AuthorizationException::class);
});

it('the photo route serves only a photo of the grant\'s thread, to the grant\'s admin, while it runs', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    $photo = app(SendMessage::class)->image($groom, $bride->profile, gradientPhoto(), (string) Str::uuid());
    [$otherGroom, $otherBride] = chatPairOf(groom('+919800000811'), bride('+919800000812'));
    $foreign = app(SendMessage::class)->image($otherGroom, $otherBride->profile, gradientPhoto(), (string) Str::uuid());
    $admin = adminWithRole('super_admin');
    $grant = breakGlassOpen($admin, $thread);
    $url = fn (string $message, ?BreakGlassGrant $g = null): string => adminUrl('/chat/break-glass/'.($g ?? $grant)->id.'/media/'.$message);

    signInAdmin($this, $admin);
    $this->get($url($photo->id))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->get($url($foreign->id))->assertNotFound();

    signInAdmin($this, adminWithRole('super_admin'));
    $this->get($url($photo->id))->assertNotFound();

    signInAdmin($this, adminWithRole('moderator'));
    $this->get($url($photo->id))->assertForbidden();

    signInAdmin($this, $admin);
    $grant->forceFill(['expires_at' => now()->subSecond()])->save();   // time up (travelling 31 min would end the admin session too)
    $this->get($url($photo->id))->assertNotFound();
});

it('DPDP: chat:data-access-log lists every break-glass read of a member\'s conversations', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    $admin = adminWithRole('super_admin');
    breakGlassOpen($admin, $thread);

    expect(Illuminate\Support\Facades\Artisan::call('chat:data-access-log', ['code' => $bride->profile->code]))->toBe(0)
        ->and(Illuminate\Support\Facades\Artisan::output())->toContain($groom->profile->code)->toContain(BREAK_GLASS_REASON)
        ->toContain('KL/POL/2026/123')->toContain($admin->email);

    $this->artisan('chat:data-access-log', ['code' => groom('+919800000821')->profile->code])
        ->expectsOutputToContain('has been read by staff')->assertSuccessful();
    $this->artisan('chat:data-access-log', ['code' => 'OPM0'])->assertFailed();
});

it('purge: a grant goes with its conversation; the audit rows stay', function (): void {
    [, , $thread] = chatConnected();
    breakGlassOpen(adminWithRole('super_admin'), $thread);

    $thread->delete();

    expect(BreakGlassGrant::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'chat.break_glass.opened')->count())->toBe(1);
});
