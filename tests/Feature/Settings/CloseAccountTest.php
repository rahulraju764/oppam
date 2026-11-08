<?php

declare(strict_types=1);

use App\Actions\Admin\Members\PurgeDeletedMember;
use App\Actions\Profile\CloseAccount;
use App\Actions\Profile\RequestDataExport;
use App\Enums\ClosureReason;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\UserStatus;
use App\Events\Account\ForceLogout;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Jobs\Members\PurgeDeletedMembers;
use App\Jobs\Profile\GenerateDataExportJob;
use App\Models\AuditLog;
use App\Models\DataExport;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\ProfileClosure;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P8.2c — /settings → Hide / delete: close my account with a reason (PRD M14). Stage one of the
| A03 two-stage deletion, started by the member: soft-deleted, signed out everywhere (live), plan
| paused, 30-day recovery (RestoreClosedAccountTest), then anonymised by the existing purge job
| (DPDP erasure) — which now also clears the closure feedback and any data export.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Notification::fake();
});

/** An active member with a running Gold plan (password = TEST_MEMBER_PASSWORD). */
function clMember(string $phone = '+919811200001'): User
{
    $member = memberWithPhone($phone);
    Subscription::factory()->create([
        'profile_id' => $member->profile->id,
        'plan_id' => Plan::query()->where('code', PlanCode::Gold->value)->value('id'),
        'starts_at' => now()->subDays(5), 'ends_at' => now()->addDays(25),
    ]);

    return $member->refresh();
}

it('R-M14 close: account + profile soft-deleted, signed out everywhere (live), plan paused, reason kept', function (ClosureReason $reason): void {
    Event::fake([ForceLogout::class]);
    $member = clMember();
    $epoch = $member->session_epoch;

    app(CloseAccount::class)->handle($member, $reason, 'Found someone through family.', TEST_MEMBER_PASSWORD);

    $member = User::withTrashed()->findOrFail($member->id);
    $profile = Profile::withTrashed()->where('user_id', $member->id)->firstOrFail();
    $closure = ProfileClosure::query()->where('user_id', $member->id)->sole();
    $audit = AuditLog::query()->where('action', 'account.closed_by_member')->sole();

    expect($member->trashed())->toBeTrue()
        ->and($member->status)->toBe(UserStatus::Deleted)
        ->and($member->session_epoch)->toBe($epoch + 1)
        ->and($profile->trashed())->toBeTrue()
        ->and($profile->status)->toBe(ProfileStatus::Deleted)
        ->and($profile->previous_status)->toBe(ProfileStatus::Active)
        ->and(Subscription::query()->where('profile_id', $profile->id)->sole()->paused_at)->not->toBeNull()
        ->and($closure->reason)->toBe($reason)
        ->and($closure->feedback)->toBe('Found someone through family.')
        ->and($closure->restored_at)->toBeNull()
        ->and(json_encode($audit->after))->not->toContain('Found someone');   // the member's words stay off the audit log

    Event::assertDispatched(ForceLogout::class);
})->with([ClosureReason::MarriedElsewhere, ClosureReason::NotInterested, ClosureReason::Other]);

it('R-M16 / owner 2026-11-07 (3): "Found my match on Oppam" never closes the account — it starts the story', function (): void {
    $member = clMember();

    expect(fn () => app(CloseAccount::class)->handle($member, ClosureReason::MarriedViaOppam, null, TEST_MEMBER_PASSWORD))
        ->toThrow(SettingsRefused::class);

    expect($member->refresh()->trashed())->toBeFalse()->and(ProfileClosure::query()->count())->toBe(0);
});

it('needs the right password (5 wrong tries → a wait), and a note for "another reason"; nothing is written', function (): void {
    $member = clMember();

    expect(fn () => app(CloseAccount::class)->handle($member, ClosureReason::Other, '  ', TEST_MEMBER_PASSWORD))
        ->toThrow(ValidationException::class);

    for ($i = 0; $i < (int) config('oppam.auth.password_attempts'); $i++) {
        expect(fn () => app(CloseAccount::class)->handle($member, ClosureReason::NotInterested, null, 'wrong-password'))
            ->toThrow(ValidationException::class);
    }
    // Locked out: even the right password waits now.
    expect(fn () => app(CloseAccount::class)->handle($member, ClosureReason::NotInterested, null, TEST_MEMBER_PASSWORD))
        ->toThrow(ValidationException::class, 'Too many attempts');

    expect($member->refresh()->trashed())->toBeFalse()->and(ProfileClosure::query()->count())->toBe(0);
});

it('refused in an admin support session; a suspended account gets a 404', function (string $case): void {
    $member = clMember();
    $this->actingAs($member, 'web');
    if ($case === 'support session') {
        chatImpersonate($member);
    } else {
        $member->forceFill(['status' => UserStatus::Suspended])->save();
    }

    expect(fn () => app(CloseAccount::class)->handle($member->refresh(), ClosureReason::NotInterested, null, TEST_MEMBER_PASSWORD))
        ->toThrow($case === 'support session' ? ImpersonationRestricted::class : NotFoundHttpException::class);
    expect($member->refresh()->trashed())->toBeFalse();
})->with(['support session', 'suspended']);

it('DPDP erasure: after the window the purge anonymises the member, clears the closure note and deletes data exports', function (): void {
    Storage::fake(GenerateDataExportJob::disk());
    $member = clMember();
    $export = app(RequestDataExport::class)->handle($member);   // the queue runs it at once in tests
    $export->refresh();
    expect($export->file_path)->not->toBeNull();
    Storage::disk(GenerateDataExportJob::disk())->assertExists((string) $export->file_path);

    app(CloseAccount::class)->handle($member->refresh(), ClosureReason::Other, 'Personal reasons, my sister’s number is 98…', TEST_MEMBER_PASSWORD);

    $this->travel(29)->days();
    (new PurgeDeletedMembers)->handle(app(PurgeDeletedMember::class));
    expect(User::withTrashed()->findOrFail($member->id)->anonymised_at)->toBeNull();   // still restorable

    $this->travel(2)->days();
    (new PurgeDeletedMembers)->handle(app(PurgeDeletedMember::class));

    $closure = ProfileClosure::query()->where('user_id', $member->id)->sole();
    expect(User::withTrashed()->findOrFail($member->id)->anonymised_at)->not->toBeNull()
        ->and($closure->feedback)->toBeNull()
        ->and($closure->reason)->toBe(ClosureReason::Other)          // the reason (no one named) stays
        ->and(DataExport::query()->where('user_id', $member->id)->count())->toBe(0);
    Storage::disk(GenerateDataExportJob::disk())->assertMissing((string) $export->file_path);
});
