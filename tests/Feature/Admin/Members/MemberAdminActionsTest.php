<?php

declare(strict_types=1);

use App\Actions\Admin\Members\AddMemberNote;
use App\Actions\Admin\Members\BulkMemberAction;
use App\Actions\Admin\Members\GrantComplimentaryPlan;
use App\Actions\Admin\Members\SuspendMember;
use App\Actions\Admin\Members\UpdateMemberProfile;
use App\Data\Admin\MemberProfileEditData;
use App\Enums\MemberBulkAction;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\SubscriptionSource;
use App\Enums\UserStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Models\AuditLog;
use App\Models\Masters\Caste;
use App\Models\MemberNote;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Account\AdminMessage;
use App\Services\Entitlements\EntitlementService;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/*
| P1.7a — A03 admin actions on members: complimentary plan, profile edit with diff + reason,
| append-only notes, bulk actions (never delete) and the audited CSV export.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
});

function a03Verified(string $phone): User
{
    $member = memberWithPhone($phone);
    $member->forceFill(['email' => 'm'.substr($phone, -4).'@example.com', 'email_verified_at' => now()])->save();

    return $member->refresh();
}

/** @param  array<string, mixed>  $changes */
function a03Edit(User $member, array $changes): MemberProfileEditData
{
    $profile = $member->profile()->firstOrFail();

    return MemberProfileEditData::fromInput([
        'first_name' => $profile->first_name, 'last_name' => $profile->last_name, 'dob' => $profile->dob?->format('Y-m-d'),
        'height_cm' => $profile->height_cm, 'weight_kg' => $profile->weight_kg, 'marital_status' => $profile->marital_status?->value,
        'religion_id' => $profile->religion_id, 'caste_id' => $profile->caste_id, 'sub_caste' => $profile->sub_caste, 'about' => $profile->about,
        ...$changes,
    ]);
}

// ---- Complimentary plan --------------------------------------------------------------------------

it('A03 grant: a complimentary Gold plan for N days, the member becomes premium, audited', function (): void {
    $member = a03Verified('+919822200001');

    app(GrantComplimentaryPlan::class)->handle(adminWithRole(), $member, PlanCode::Gold, 30, 'Compensation for a billing error.');

    $subscription = Subscription::query()->where('profile_id', $member->profile->id)->sole();
    $profile = $member->profile()->firstOrFail();
    app(EntitlementService::class)->forget($profile);
    expect($subscription->source)->toBe(SubscriptionSource::Complimentary)
        ->and((int) round($subscription->starts_at->diffInDays($subscription->ends_at, true)))->toBe(30)
        ->and($profile->is_premium)->toBeTrue()
        ->and(app(EntitlementService::class)->plan($profile)->code)->toBe(PlanCode::Gold)
        ->and(AuditLog::query()->where('action', 'members.plan_granted')->count())->toBe(1);
});

it('A03 grant needs billing.force_activate and a paid plan for 1–365 days', function (): void {
    $member = a03Verified('+919822200002');

    expect(fn () => app(GrantComplimentaryPlan::class)->handle(adminWithRole('support'), $member, PlanCode::Gold, 30, 'Support cannot grant plans.'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(GrantComplimentaryPlan::class)->handle(adminWithRole(), $member, PlanCode::Free, 30, 'Free is not a plan to grant.'))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(GrantComplimentaryPlan::class)->handle(adminWithRole(), $member, PlanCode::Gold, 0, 'Zero days is not a grant.'))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(GrantComplimentaryPlan::class)->handle(adminWithRole(), $member, PlanCode::Gold, 366, 'More than a year is too long.'))
        ->toThrow(ValidationException::class);

    expect(Subscription::query()->count())->toBe(0);
});

it('P1.7a review Major: no complimentary plan for a never-verified registration (it must stay releasable)', function (): void {
    $member = a03Verified('+919822200030');
    $member->forceFill(['phone_verified_at' => null])->save();

    expect(fn () => app(GrantComplimentaryPlan::class)->handle(adminWithRole(), $member, PlanCode::Gold, 30, 'Granting to an unverified number.'))
        ->toThrow(MemberStateConflict::class);
    expect(Subscription::query()->count())->toBe(0);
});

it('A03 grant is refused for a suspended member', function (): void {
    $member = a03Verified('+919822200003');
    app(SuspendMember::class)->handle(adminWithRole(), $member, 'Abuse investigation.');

    expect(fn () => app(GrantComplimentaryPlan::class)->handle(adminWithRole(), $member, PlanCode::Silver, 10, 'Granting to a suspended member.'))
        ->toThrow(MemberStateConflict::class);
});

// ---- Profile edit with diff + reason -------------------------------------------------------------

it('A03 profile edit saves only the changed fields and audits exactly their before / after', function (): void {
    $member = memberThroughStep(6);
    $member->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();
    $oldName = $member->profile->first_name;

    $changed = app(UpdateMemberProfile::class)->handle(adminWithRole('support'), $member, a03Edit($member, ['first_name' => 'Lakshmi', 'height_cm' => 165]), 'Member sent a corrected name by email.');

    $audit = AuditLog::query()->where('action', 'members.profile_edited')->sole();
    expect($changed)->toEqualCanonicalizing(['first_name', 'height_cm'])
        ->and($member->profile()->firstOrFail()->first_name)->toBe('Lakshmi')
        ->and(array_keys($audit->after ?? []))->toEqualCanonicalizing(['first_name', 'height_cm'])
        ->and($audit->before['first_name'] ?? null)->toBe($oldName)
        ->and($audit->reason)->toBe('Member sent a corrected name by email.');
});

it('A03 profile edit uses the wizard rules: caste must belong to the religion, minimum marriage age', function (array $changes, string $field): void {
    $member = memberThroughStep(6);
    $member->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();
    $before = $member->profile()->firstOrFail()->getAttributes();
    if ($changes === ['caste_id' => 'OTHER_RELIGION']) {
        $changes = ['caste_id' => Caste::query()->where('religion_id', '!=', $member->profile->religion_id)->value('id')];
    }

    try {
        app(UpdateMemberProfile::class)->handle(adminWithRole(), $member, a03Edit($member, $changes), 'Correcting the profile.');
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    expect($member->profile()->firstOrFail()->getAttributes())->toEqual($before);
})->with([
    'caste of another religion' => [['caste_id' => 'OTHER_RELIGION'], 'caste_id'],
    'under 18 (bride)' => [fn () => ['dob' => now()->subYears(17)->format('Y-m-d')], 'dob'],
    'name with digits' => [['first_name' => 'Anjali99'], 'first_name'],
]);

it('A03 profile edit can never write anything outside the editable fields', function (): void {
    $member = memberThroughStep(6);
    $member->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();

    $data = MemberProfileEditData::fromInput([...a03Edit($member, ['first_name' => 'Meera'])->toArray(),
        'status' => 'SUSPENDED', 'is_premium' => '1', 'is_verified' => '1', 'gender' => 'MALE', 'code' => 'OPM1']);
    app(UpdateMemberProfile::class)->handle(adminWithRole(), $member, $data, 'Correcting the name only.');

    $profile = $member->profile()->firstOrFail();
    expect($profile->first_name)->toBe('Meera')
        ->and($profile->status)->toBe(ProfileStatus::Active)
        ->and($profile->is_premium)->toBeFalse()
        ->and($profile->is_verified)->toBeFalse()
        ->and($profile->gender->value)->toBe('FEMALE');
});

it('A03 profile edit: nothing changed is an error; needs members.edit', function (): void {
    $member = memberThroughStep(6);
    $member->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();

    expect(fn () => app(UpdateMemberProfile::class)->handle(adminWithRole(), $member, a03Edit($member, []), 'No real change here.'))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateMemberProfile::class)->handle(adminWithRole('moderator'), $member, a03Edit($member, ['first_name' => 'Meera']), 'Moderators cannot edit.'))
        ->toThrow(AuthorizationException::class);
});

// ---- Notes ---------------------------------------------------------------------------------------

it('A03 notes: added by an admin with members.edit, 1–2000 characters, audited without the text', function (): void {
    $member = a03Verified('+919822200004');

    app(AddMemberNote::class)->handle(adminWithRole('support'), $member, 'Called the member; they confirmed the photo is theirs.');

    expect(MemberNote::query()->where('user_id', $member->id)->sole()->admin_label)->toContain('@')
        ->and(AuditLog::query()->where('action', 'members.note_added')->sole()->after)->not->toHaveKey('body');

    expect(fn () => app(AddMemberNote::class)->handle(adminWithRole('support'), $member, '   '))->toThrow(ValidationException::class)
        ->and(fn () => app(AddMemberNote::class)->handle(adminWithRole('support'), $member, str_repeat('n', 2001)))->toThrow(ValidationException::class)
        ->and(fn () => app(AddMemberNote::class)->handle(adminWithRole('moderator'), $member, 'Moderators cannot add notes.'))->toThrow(AuthorizationException::class);
});

// ---- Bulk actions --------------------------------------------------------------------------------

it('A03 bulk: there is no bulk delete', function (): void {
    expect(MemberBulkAction::tryFrom('DELETE'))->toBeNull()
        ->and(array_map(fn (MemberBulkAction $a): string => $a->value, MemberBulkAction::cases()))->toBe(['SUSPEND', 'ACTIVATE', 'NOTIFY']);
});

it('A03 bulk suspend: each member through SuspendMember; wrong-state members are skipped; one summary audit row', function (): void {
    $a = a03Verified('+919822200005');
    $b = a03Verified('+919822200006');
    $c = a03Verified('+919822200007');
    app(SuspendMember::class)->handle(adminWithRole(), $c, 'Already suspended earlier.');

    $result = app(BulkMemberAction::class)->handle(adminWithRole(), MemberBulkAction::Suspend, [$a->id, $b->id, $c->id], 'Spam ring found by the safety team.');

    expect($result->done)->toBe(2)
        ->and($result->skipped)->toBe(1)
        ->and($a->refresh()->status)->toBe(UserStatus::Suspended)
        ->and($b->refresh()->status)->toBe(UserStatus::Suspended)
        ->and(AuditLog::query()->where('action', 'members.suspended')->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'members.bulk_suspend')->sole()->after['members'] ?? [])->toHaveCount(3);
});

it('A03 bulk: at most 200 members, a reason, and the action\'s own permission', function (): void {
    $member = a03Verified('+919822200008');

    expect(fn () => app(BulkMemberAction::class)->handle(adminWithRole(), MemberBulkAction::Suspend,
        array_map(fn (int $i): string => 'id'.$i, range(1, 201)), 'Too many at once.'))->toThrow(ValidationException::class)
        ->and(fn () => app(BulkMemberAction::class)->handle(adminWithRole(), MemberBulkAction::Suspend, [$member->id], ''))->toThrow(ValidationException::class)
        ->and(fn () => app(BulkMemberAction::class)->handle(adminWithRole('support'), MemberBulkAction::Suspend, [$member->id], 'Support cannot suspend.'))->toThrow(AuthorizationException::class);

    expect($member->refresh()->status)->toBe(UserStatus::Active);
});

it('A03 bulk message: emailed only to active members with a verified email', function (): void {
    Notification::fake();
    $verified = a03Verified('+919822200009');
    $unverified = memberWithPhone('+919822200010');
    $unverified->forceFill(['email' => 'x@example.com', 'email_verified_at' => null])->save();

    $result = app(BulkMemberAction::class)->handle(adminWithRole('support'), MemberBulkAction::Notify, [$verified->id, $unverified->id],
        'Festival offer announcement.', 'Onam greetings', 'Wishing you a happy Onam from the Oppam team.');

    expect($result->done)->toBe(1)->and($result->skipped)->toBe(1);
    Notification::assertSentTo($verified, AdminMessage::class);
    Notification::assertNotSentTo($unverified, AdminMessage::class);
});

// ---- CSV export ----------------------------------------------------------------------------------

/** What the member list does: filters under a single-use token for this admin, a signed link with only the token. */
function a03ExportUrl(App\Models\AdminUser $admin, array $filters = []): string
{
    $token = Illuminate\Support\Str::random(40);
    Illuminate\Support\Facades\Cache::put(App\Http\Controllers\Admin\MemberExportController::CACHE_PREFIX.$token, ['admin' => $admin->id, 'filters' => $filters], now()->addMinutes(5));

    return URL::temporarySignedRoute('admin.members.export', now()->addMinutes(5), ['token' => $token]);
}

it('A03 export: a signed link gives the CSV with the allowed columns only, audited', function (): void {
    $member = a03Verified('+919822200011');
    signInAdmin($this, $admin = adminWithRole());

    $response = $this->get(a03ExportUrl($admin));
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $csv = $response->streamedContent();

    expect($csv)->toContain('Code,"First name"')
        ->and($csv)->toContain($member->profile->code)
        ->and($csv)->not->toContain($member->phone)
        ->and($csv)->not->toContain('9822200011')
        ->and($csv)->not->toContain((string) $member->email)
        ->and(AuditLog::query()->where('action', 'members.exported')->count())->toBe(1);
});

it('A03 export neutralises spreadsheet formulas in cells', function (): void {
    $member = a03Verified('+919822200012');
    $member->profile->forceFill(['first_name' => '=HYPERLINK("http://evil.test")'])->save();
    signInAdmin($this, $admin = adminWithRole());

    $csv = $this->get(a03ExportUrl($admin))->streamedContent();

    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->not->toMatch('/(^|,)"?=HYPERLINK/m');
});

it('A03 export without members.export is a 403, logged and audited', function (): void {
    signInAdmin($this, $admin = adminWithRole('support'));

    $this->get(a03ExportUrl($admin))->assertForbidden();

    expect(AuditLog::query()->where('action', 'members.export_denied')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'members.exported')->count())->toBe(0);
});

it('A03 export needs a valid signature, a signed-in admin and that admin\'s own single-use token', function (): void {
    signInAdmin($this, $admin = adminWithRole());
    $this->get(adminUrl('/members/export'))->assertForbidden();   // unsigned

    $url = a03ExportUrl($admin);
    $this->get($url)->assertOk();
    $this->get($url)->assertNotFound();                          // single use

    $this->get(a03ExportUrl(adminWithRole()))->assertNotFound(); // another admin's token

    auth('admin')->logout();
    $this->get(a03ExportUrl($admin))->assertRedirect(route('admin.login'));
});

it('A03 export includes deleted members (with their codes) and loads relations in chunks, not per row', function (): void {
    $kept = a03Verified('+919822200013');
    $gone = a03Verified('+919822200014');
    app(App\Actions\Admin\Members\DeleteMember::class)->handle(adminWithRole(), $gone, $gone->profile->code, 'Member asked us to delete it.');
    foreach (range(15, 24) as $n) {
        a03Verified('+9198222000'.$n);
    }
    signInAdmin($this, $admin = adminWithRole());

    $csv = $this->get(a03ExportUrl($admin, ['status' => 'DELETED']))->streamedContent();
    expect($csv)->toContain($gone->profile->code)->not->toContain($kept->profile->code)
        ->and($csv)->toContain('Deleted');

    Illuminate\Support\Facades\DB::enableQueryLog();
    $this->get(a03ExportUrl($admin))->streamedContent();
    expect(count(Illuminate\Support\Facades\DB::getQueryLog()))->toBeLessThan(25);   // 12 members: a few queries per chunk, not 3 per row
});

it('A03 profile edit refreshes completeness and refuses a form loaded before someone else\'s edit', function (): void {
    $member = memberThroughStep(6);
    $member->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now(), 'completeness' => 0])->save();
    $profile = $member->profile()->firstOrFail();
    $seen = UpdateMemberProfile::fingerprint($profile);

    app(UpdateMemberProfile::class)->handle(adminWithRole(), $member, a03Edit($member, ['height_cm' => 170]), 'First admin edit.', $seen);
    expect($member->profile()->firstOrFail()->completeness)->toBeGreaterThan(0);

    // A second admin still holding the old form (same $seen) is refused; the first edit stands.
    expect(fn () => app(UpdateMemberProfile::class)->handle(adminWithRole(), $member, a03Edit($member, ['first_name' => 'Meera', 'height_cm' => 160]), 'Second admin edit.', $seen))
        ->toThrow(MemberStateConflict::class);
    expect($member->profile()->firstOrFail()->height_cm)->toBe(170);
});

it('A03 bulk message leaves an audit row on each member\'s own timeline', function (): void {
    Notification::fake();
    $member = a03Verified('+919822200031');

    app(BulkMemberAction::class)->handle(adminWithRole('support'), MemberBulkAction::Notify, [$member->id],
        'Festival offer announcement.', 'Onam greetings', 'Wishing you a happy Onam from the Oppam team.');

    expect(AuditLog::query()->where('action', 'members.message_sent')->where('subject_id', $member->id)->count())->toBe(1);
});
