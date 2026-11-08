<?php

declare(strict_types=1);

use App\Actions\Content\ConfirmPartnerSuccessStory;
use App\Actions\Content\SubmitMemberSuccessStory;
use App\Actions\Profile\WithdrawMemberSuccessStory;
use App\Data\Content\MemberStoryData;
use App\Enums\ContentStatus;
use App\Enums\ProfileStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Models\AuditLog;
use App\Models\Block;
use App\Models\Interest;
use App\Models\SuccessStory;
use App\Models\User;
use App\Notifications\Account\SuccessStoryConfirmationRequest;
use App\Notifications\Account\SuccessStoryConfirmedNotification;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P8.2c — M16 "Found my match on Oppam": story with the partner's code, wedding date, photo, story
| → A08 as IN_REVIEW; the partner confirms (consent) or declines; published only with both
| consents; the submitter's profile is hidden at once (hidden, not deleted — owner 2026-11-07 (3)),
| the partner's when they confirm. The partner must be someone the member connected with.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Notification::fake();
});

/** A bride and groom with an accepted interest between them. */
function msCouple(): array
{
    $bride = bride();
    $groom = groom();
    Interest::factory()->accepted()->create(['from_profile_id' => $groom->profile->id, 'to_profile_id' => $bride->profile->id]);

    return [$bride, $groom];
}

function msData(string $partnerCode, array $overrides = []): MemberStoryData
{
    return new MemberStoryData(...[
        'partnerCode' => $partnerCode,
        'weddingDate' => '2026-10-12',
        'place' => 'Thrissur',
        'quote' => 'We met on Oppam and our families clicked at once.',
        'body' => str_repeat('Our story began with an interest on Oppam and a long call. ', 3),
        ...$overrides,
    ]);
}

it('R-M16 submit: an IN_REVIEW story with the submitter\'s consent; the submitter is hidden, not deleted; the partner is asked', function (): void {
    [$bride, $groom] = msCouple();

    $story = app(SubmitMemberSuccessStory::class)->handle($bride, msData(strtolower($groom->profile->code)));

    expect($story->status)->toBe(ContentStatus::InReview)
        ->and($story->consent_a_at)->not->toBeNull()
        ->and($story->consent_b_at)->toBeNull()
        ->and($story->profile_a_id)->toBe($bride->profile->id)
        ->and($story->profile_b_id)->toBe($groom->profile->id)
        ->and($story->publishBlocker())->not->toBeNull()                       // not publishable without the partner
        ->and(statusOf($bride))->toBe(ProfileStatus::Hidden)
        ->and($bride->profile->refresh()->trashed())->toBeFalse()
        ->and(statusOf($groom))->toBe(ProfileStatus::Active)
        ->and(SuccessStory::query()->live()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'content.story_submitted_by_member')->count())->toBe(1);

    Notification::assertSentTo($groom, SuccessStoryConfirmationRequest::class,
        fn (SuccessStoryConfirmationRequest $n): bool => $n->storyCode === $story->code && $n->submitterFirstName === $bride->profile->first_name);
});

it('R-M16 confirm: the partner\'s consent is recorded and their profile hidden too; the submitter is told', function (): void {
    [$bride, $groom] = msCouple();
    $story = app(SubmitMemberSuccessStory::class)->handle($bride, msData($groom->profile->code));

    app(ConfirmPartnerSuccessStory::class)->handle($groom, $story->code, true);

    $story->refresh();
    expect($story->consent_b_at)->not->toBeNull()
        ->and($story->hasBothConsents())->toBeTrue()         // A08 may now publish it (once it has an image)
        ->and((string) $story->publishBlocker())->not->toContain('Both partners must consent')
        ->and(statusOf($groom))->toBe(ProfileStatus::Hidden);
    Notification::assertSentTo($bride, SuccessStoryConfirmedNotification::class, fn ($n): bool => $n->confirmed);
});

it('R-M16 decline: the story is withdrawn for good; the partner stays visible', function (): void {
    [$bride, $groom] = msCouple();
    $story = app(SubmitMemberSuccessStory::class)->handle($bride, msData($groom->profile->code));

    app(ConfirmPartnerSuccessStory::class)->handle($groom, $story->code, false);

    $story->refresh();
    expect($story->withdrawn_at)->not->toBeNull()->and($story->consent_a_at)->toBeNull()
        ->and($story->publishBlocker())->not->toBeNull()
        ->and(statusOf($groom))->toBe(ProfileStatus::Active);
    Notification::assertSentTo($bride, SuccessStoryConfirmedNotification::class, fn ($n): bool => ! $n->confirmed);
});

it('only the named partner can answer, once: the submitter, a stranger or a second answer → 404', function (): void {
    [$bride, $groom] = msCouple();
    $story = app(SubmitMemberSuccessStory::class)->handle($bride, msData($groom->profile->code));

    expect(fn () => app(ConfirmPartnerSuccessStory::class)->handle($bride, $story->code, true))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(ConfirmPartnerSuccessStory::class)->handle(groom('+919800000077'), $story->code, true))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(ConfirmPartnerSuccessStory::class)->handle($groom, 'STYNOTREAL1', true))->toThrow(NotFoundHttpException::class);

    app(ConfirmPartnerSuccessStory::class)->handle($groom, $story->code, true);
    expect(fn () => app(ConfirmPartnerSuccessStory::class)->handle($groom, $story->code, false))->toThrow(NotFoundHttpException::class);
    expect($story->refresh()->withdrawn_at)->toBeNull();
});

it('refuses a partner the member can\'t name — one message for every reason', function (string $case): void {
    [$bride, $groom] = msCouple();
    $code = match ($case) {
        'own code' => $bride->profile->code,
        'unknown code' => 'OPM99999999',
        'same gender' => tap(bride('+919800000055'), fn (User $b) => Interest::factory()->accepted()->create(['from_profile_id' => $b->profile->id, 'to_profile_id' => $bride->profile->id]))->profile->code,
        'not connected' => groom('+919800000066')->profile->code,
        'blocked pair' => tap($groom->profile->code, fn () => Block::query()->forceCreate(['blocker_profile_id' => $groom->profile->id, 'blocked_profile_id' => $bride->profile->id])),
        'partner suspended' => tap($groom->profile->code, fn () => $groom->profile->forceFill(['status' => ProfileStatus::Suspended])->save()),
    };

    expect(fn () => app(SubmitMemberSuccessStory::class)->handle($bride, msData($code)))
        ->toThrow(ValidationException::class, 'We can’t add a story with this profile code');
    expect(SuccessStory::query()->count())->toBe(0)->and(statusOf($bride))->toBe(ProfileStatus::Active);
    Notification::assertNothingSent();
})->with(['own code', 'unknown code', 'same gender', 'not connected', 'blocked pair', 'partner suspended']);

it('rule 4: a block after the story was shared → the partner can\'t confirm (404) and the submitter hears nothing', function (): void {
    [$bride, $groom] = msCouple();
    $story = app(SubmitMemberSuccessStory::class)->handle($bride, msData($groom->profile->code));
    Block::query()->forceCreate(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $groom->profile->id]);
    Notification::fake();

    expect(fn () => app(ConfirmPartnerSuccessStory::class)->handle($groom, $story->code, true))->toThrow(NotFoundHttpException::class);
    expect($story->refresh()->consent_b_at)->toBeNull()->and(statusOf($groom))->toBe(ProfileStatus::Active);
    Notification::assertNothingSent();
});

it('withdraw / decline archive the story and delete the couple\'s photo, like the admin withdrawal', function (): void {
    Illuminate\Support\Facades\Storage::fake('public');
    [$bride, $groom] = msCouple();
    $story = app(SubmitMemberSuccessStory::class)->handle($bride, msData($groom->profile->code, [
        'photo' => Illuminate\Http\UploadedFile::fake()->image('us.jpg', 800, 600),
    ]));
    $photo = (string) $story->image_path;
    Illuminate\Support\Facades\Storage::disk('public')->assertExists($photo);

    app(ConfirmPartnerSuccessStory::class)->handle($groom, $story->code, false);

    $story->refresh();
    expect($story->status)->toBe(ContentStatus::Archived)->and($story->image_path)->toBeNull();
    Illuminate\Support\Facades\Storage::disk('public')->assertMissing($photo);
});

it('one open story per couple (either way round); a withdrawn one doesn\'t count', function (): void {
    [$bride, $groom] = msCouple();
    $story = app(SubmitMemberSuccessStory::class)->handle($bride, msData($groom->profile->code));

    expect(fn () => app(SubmitMemberSuccessStory::class)->handle($groom, msData($bride->profile->code)))->toThrow(ValidationException::class);

    app(WithdrawMemberSuccessStory::class)->handle($bride, $story->code);
    app(SubmitMemberSuccessStory::class)->handle($groom, msData($bride->profile->code));
    expect(SuccessStory::query()->whereNull('withdrawn_at')->count())->toBe(1);
});

it('withdraw: either partner, by code; it loses both consents (so it can\'t be or stay published); others → 404', function (): void {
    [$bride, $groom] = msCouple();
    $story = app(SubmitMemberSuccessStory::class)->handle($bride, msData($groom->profile->code));
    app(ConfirmPartnerSuccessStory::class)->handle($groom, $story->code, true);

    expect(fn () => app(WithdrawMemberSuccessStory::class)->handle(groom('+919800000044'), $story->code))->toThrow(NotFoundHttpException::class);

    app(WithdrawMemberSuccessStory::class)->handle($groom, $story->code);
    $story->refresh();
    expect($story->withdrawn_at)->not->toBeNull()->and($story->consent_a_at)->toBeNull()->and($story->consent_b_at)->toBeNull()
        ->and(SuccessStory::query()->live()->count())->toBe(0);
});

it('3 stories a day; refused in an admin support session; a member without a live / hidden profile → 404', function (): void {
    [$bride, $groom] = msCouple();
    for ($i = 0; $i < SubmitMemberSuccessStory::PER_DAY; $i++) {
        $story = app(SubmitMemberSuccessStory::class)->handle($bride->refresh(), msData($groom->profile->code));
        app(WithdrawMemberSuccessStory::class)->handle($bride, $story->code);
    }
    expect(fn () => app(SubmitMemberSuccessStory::class)->handle($bride->refresh(), msData($groom->profile->code)))->toThrow(SettingsRefused::class);

    $this->actingAs($groom, 'web');
    chatImpersonate($groom);
    expect(fn () => app(SubmitMemberSuccessStory::class)->handle($groom, msData($bride->profile->code)))->toThrow(ImpersonationRestricted::class);

    session()->flush();
    app(App\Services\Admin\Impersonation::class)->forget();
    $pending = memberWithPhone('+919800000033', ProfileStatus::PendingReview);
    expect(fn () => app(SubmitMemberSuccessStory::class)->handle($pending, msData($groom->profile->code)))->toThrow(NotFoundHttpException::class);
});
