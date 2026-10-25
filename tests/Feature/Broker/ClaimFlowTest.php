<?php

declare(strict_types=1);

use App\Actions\Auth\RegisterMember;
use App\Actions\Broker\ClaimManagedProfile;
use App\Actions\Broker\ManagedProfiles\ExportManagedProfile;
use App\Actions\Engagement\SendInterest;
use App\Actions\Profile\SaveBasicDetails;
use App\Domain\Chat\ChatAccess;
use App\Enums\BrokerExportType;
use App\Enums\BureauRole;
use App\Enums\ChatSendState;
use App\Enums\OtpPurpose;
use App\Enums\PlanCode;
use App\Enums\ProfileOwnerType;
use App\Enums\ProfileStatus;
use App\Enums\ReferralSource;
use App\Events\Broker\ProfileClaimed;
use App\Exceptions\Broker\ClaimUnavailable;
use App\Livewire\Member\Auth\ClaimProfile;
use App\Livewire\Member\Auth\VerifyOtp;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\BrokerProfileExport;
use App\Models\BrokerReferral;
use App\Models\Conversation;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Broker\ManagedProfileClaimed;
use App\ValueObjects\PhoneNumber;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| ★ ClaimFlowTest (PRD §11A B.6 claim flow, B.19, B.22) — P7.4. A client who registers with the
| SAME mobile a bureau stored for them, and proves it by OTP, is asked "Is this you?"; only an
| explicit "Yes" links the profile (R-M13-15), and from then on the bureau has no access at all —
| no page, no export (R-M13-16), including an export racing the claim (B.19).
*/

const CLAIM_PHONE = '+919876512345';

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    $this->sms = fakeSms();
});

/** The bureau's client stored with CLAIM_PHONE: [Owner login, the client profile, the assigned Telecaller]. */
function bureauClient(array $attributes = []): array
{
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $telecaller = bureauLogin(BureauRole::Telecaller, broker: $broker);
    $client = managedProfile($broker, ['client_phone' => CLAIM_PHONE, 'first_name' => 'Anjali', 'last_name' => 'Menon'] + $attributes, staffOf($telecaller));
    BrokerReferral::factory()->create(['broker_id' => $broker->id, 'profile_id' => $client->id, 'source' => ReferralSource::Managed]);

    return [$owner, $client, $telecaller];
}

/** Register with $e164 and enter the right OTP on /verify-otp, as the visitor would. */
function registerAndVerify(object $test, string $e164 = CLAIM_PHONE): \Livewire\Features\SupportTesting\Testable
{
    $phone = PhoneNumber::fromE164($e164);
    $result = app(RegisterMember::class)->handle(registrationData(['phone' => $phone, 'email' => null]), '10.1.1.1');
    VerifyOtp::startPending($phone, $result->user?->id);

    return Livewire::test(VerifyOtp::class)->set('code', (string) $test->sms->lastCodeFor($e164, OtpPurpose::Register))->call('verify');
}

function claimant(string $e164 = CLAIM_PHONE): User
{
    return User::query()->where('phone', $e164)->sole();
}

// ---- The offer -------------------------------------------------------------------------------------

it('★ R-M13-15: registering with the stored mobile + OTP asks "Is this you?" — first name + initial and the code only', function (): void {
    [, $client] = bureauClient();

    registerAndVerify($this)->assertRedirect(route('register.claim'));

    Livewire::actingAs(claimant())->test(ClaimProfile::class)
        ->assertOk()
        ->assertSee('Is this you?')
        ->assertSee('Anjali M.')
        ->assertSee($client->code)
        ->assertDontSee('Menon')
        ->assertSeeHtml('wire:loading');

    // Nothing is linked by the offer itself: never a silent link.
    expect($client->refresh()->user_id)->toBeNull()->and($client->owner_type)->toBe(ProfileOwnerType::BrokerManaged);
});

it('★ R-M13-15: a different mobile, a deleted or an already claimed client — no offer, the wizard as usual', function (string $case): void {
    [, $client] = bureauClient();
    match ($case) {
        'different mobile' => null,
        'deleted by the bureau' => $client->delete(),
        'already claimed' => $client->forceFill(['claimed_at' => now(), 'owner_type' => ProfileOwnerType::Self, 'user_id' => User::factory()->create()->id])->save(),
    };

    registerAndVerify($this, $case === 'different mobile' ? '+919876500000' : CLAIM_PHONE)
        ->assertRedirect(route('member.onboarding', ['step' => 1]));
})->with(['different mobile', 'deleted by the bureau', 'already claimed']);

it('★ R-M13-15: the claim page can’t be opened without the OTP-time offer, nor once the wizard was started', function (): void {
    [, $client] = bureauClient();
    registerAndVerify($this);
    $user = claimant();

    // The wizard's first save: the registration draft is no longer untouched.
    app(SaveBasicDetails::class)->handle($user, $user->profile, basicData());
    Livewire::actingAs($user->refresh())->test(ClaimProfile::class)->assertRedirect(route('member.onboarding', ['step' => 1]));
    expect(app(ClaimManagedProfile::class)->candidateFor($user))->toBeNull();

    // A member who simply logs in later (no offer flag in the session) is never offered it.
    session()->forget(ClaimProfile::OFFER_SESSION_KEY);
    Livewire::actingAs(memberWithPhone('+919811122233'))->test(ClaimProfile::class)->assertRedirect();
    expect($client->refresh()->user_id)->toBeNull();
});

// ---- "Yes, this is me" -----------------------------------------------------------------------------

it('★ R-M13-15 / R-M13-16: "Yes" flips ownership in one go — SELF, claimed, bureau link, staff and stored mobile cleared, placeholder gone', function (): void {
    Event::fake([ProfileClaimed::class]);
    Notification::fake();
    [$owner, $client, $telecaller] = bureauClient();
    $brokerId = $client->managed_by_broker_id;
    registerAndVerify($this);
    $user = claimant();
    $placeholderId = $user->profile->id;

    Livewire::actingAs($user)->test(ClaimProfile::class)->call('confirm')->assertRedirect();

    $client->refresh();
    expect($client->user_id)->toBe($user->id)
        ->and($client->owner_type)->toBe(ProfileOwnerType::Self)
        ->and($client->claimed_at)->not->toBeNull()
        ->and($client->managed_by_broker_id)->toBeNull()
        ->and($client->assigned_staff_id)->toBeNull()
        ->and($client->client_phone)->toBeNull()
        ->and($client->broker_id)->toBe($brokerId)                     // commission still flows (B.6)
        ->and($client->status)->toBe(ProfileStatus::Active)
        ->and(Profile::withTrashed()->find($placeholderId))->toBeNull()
        ->and($user->refresh()->profile->id)->toBe($client->id)
        ->and(BrokerReferral::query()->where('profile_id', $client->id)->value('source'))->toBe(ReferralSource::Managed);

    $audit = AuditLog::query()->where('action', 'broker.profile_claimed')->sole();
    expect($audit->before['managed_by_broker_id'])->toBe($brokerId)
        ->and($audit->before['client_phone'])->not->toContain('12345')   // masked
        ->and($audit->actor_id)->toBe($user->id);

    Event::assertDispatched(ProfileClaimed::class, fn (ProfileClaimed $e): bool => $e->brokerId === $brokerId
        && $e->broadcastWith() === ['profile' => $client->code] && $e->broadcastAs() === 'profile.claimed');
    Notification::assertSentTo([$owner, $telecaller], ManagedProfileClaimed::class, fn (ManagedProfileClaimed $n): bool => $n->profileCode === $client->code);
});

it('★ R-M13-16 / B.19: once claimed, the old bureau gets 404 on every page and 403 + a logged row on export', function (): void {
    [$owner, $client] = bureauClient();
    registerAndVerify($this);
    app(ClaimManagedProfile::class)->claim(claimant());
    actingAsBureau($owner);

    foreach (['', '/edit', '/notes', '/inbox'] as $page) {
        $this->get(brokerUrl('/profiles/'.$client->code.$page))->assertNotFound();
    }
    $this->get(brokerUrl('/profiles/'.$client->code.'/export/biodata'))->assertForbidden()
        ->assertHeader('X-Error-Code', ExportManagedProfile::NOT_MANAGED);

    expect(BrokerProfileExport::query()->sole()->allowed)->toBeFalse();
});

it('★ B.19 export racing a claim: an export started from a page loaded before the claim is refused once the claim commits', function (): void {
    [$owner, $client] = bureauClient();
    registerAndVerify($this);
    $stale = Profile::query()->findOrFail($client->id);   // what the bureau page had loaded

    // Before the claim: allowed.
    expect(app(ExportManagedProfile::class)->handle($owner, $stale->code, BrokerExportType::BiodataPdf, null)->profileCode)->toBe($client->code);

    app(ClaimManagedProfile::class)->claim(claimant());

    // The bureau's next request — same code, the stale model in memory: ownership is re-read under the row lock.
    expect($stale->managed_by_broker_id)->not->toBeNull();
    expect(fn () => app(ExportManagedProfile::class)->handle($owner, $stale->code, BrokerExportType::PhotoPackage, null))
        ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(403));

    expect(BrokerProfileExport::query()->orderBy('created_at')->orderBy('id')->pluck('allowed')->all())->toBe([true, false]);
});

it('B.19: a claim that loses the race to the bureau deleting the client is refused cleanly; the placeholder stays', function (): void {
    [, $client] = bureauClient();
    registerAndVerify($this);
    $user = claimant();
    $client->delete();   // the bureau deleted it between the offer and the "Yes"

    expect(fn () => app(ClaimManagedProfile::class)->claim($user))->toThrow(ClaimUnavailable::class);
    expect($user->refresh()->profile)->not->toBeNull()->and($client->refresh()->user_id)->toBeNull();

    session()->put(ClaimProfile::OFFER_SESSION_KEY, true);
    Livewire::actingAs($user)->test(ClaimProfile::class)->assertRedirect(route('member.onboarding', ['step' => 1]));
    expect(session(ClaimProfile::OFFER_SESSION_KEY))->toBeNull();
});

it('a second "Yes" (another tab) claims nothing more', function (): void {
    bureauClient();
    registerAndVerify($this);
    $user = claimant();

    app(ClaimManagedProfile::class)->claim($user);

    expect(fn () => app(ClaimManagedProfile::class)->claim($user->refresh()))->toThrow(ClaimUnavailable::class);
});

// ---- "No" ------------------------------------------------------------------------------------------

it('★ R-M13-15: "No" leaves both profiles untouched and continues to the wizard', function (): void {
    [, $client] = bureauClient();
    registerAndVerify($this);
    $user = claimant();
    $own = $user->profile;

    Livewire::actingAs($user)->test(ClaimProfile::class)->call('decline')->assertRedirect(route('member.onboarding', ['step' => 1]));

    expect($client->refresh()->user_id)->toBeNull()
        ->and($client->owner_type)->toBe(ProfileOwnerType::BrokerManaged)
        ->and($client->client_phone)->toBe(CLAIM_PHONE)
        ->and($user->refresh()->profile->id)->toBe($own->id)
        ->and(session(ClaimProfile::OFFER_SESSION_KEY))->toBeNull();

    Livewire::actingAs($user)->test(ClaimProfile::class)->assertRedirect();   // not offered again
});

// ---- Chat after the claim ----------------------------------------------------------------------------

it('R-M13-34: chat with the client is closed (with the notice) until they claim, then opens on the plan rules', function (): void {
    [$owner, $client] = bureauClient();
    $groom = chatOnPlan(groom(), PlanCode::Gold);
    app(SendInterest::class)->handle($groom, $client, 'T01');
    app(App\Actions\Broker\ManagedProfiles\RespondToInterestForClient::class)->handle($owner, $client->code, $groom->profile->code, true);
    $thread = Conversation::query()->sole();

    $state = app(ChatAccess::class)->sendState($groom->profile, $client->refresh(), $thread);
    expect($state)->toBe(ChatSendState::Managed)
        ->and($state->message())->toContain('managed by a registered bureau');
    expect(fn () => app(App\Actions\Chat\SendMessage::class)->handle($groom, $client, 'Hi', (string) Illuminate\Support\Str::uuid()))
        ->toThrow(App\Exceptions\Chat\ChatRefused::class);

    registerAndVerify($this);
    app(ClaimManagedProfile::class)->claim(claimant());

    expect(app(ChatAccess::class)->sendState($groom->profile, $client->refresh(), $thread))->toBe(ChatSendState::CanSend);
    $message = app(App\Actions\Chat\SendMessage::class)->handle($groom, $client, 'Hello Anjali', (string) Illuminate\Support\Str::uuid());
    expect($message->body)->toBe('Hello Anjali');
});

it('the bureau Owner’s dashboard counts the claimed client', function (): void {
    [$owner] = bureauClient();
    registerAndVerify($this);
    app(ClaimManagedProfile::class)->claim(claimant());

    expect(app(App\Queries\Broker\ManagedProfileList::class)->counts(staffOf($owner))['claimed'])->toBe(1);
});

it('never offers a profile to a bureau login or a suspended member', function (): void {
    bureauClient();
    $user = memberWithPhone(CLAIM_PHONE, ProfileStatus::Draft);
    $user->profile->forceFill(['completeness' => 0])->save();
    $user->forceFill(['status' => App\Enums\UserStatus::Suspended])->save();

    expect(app(ClaimManagedProfile::class)->candidateFor($user->refresh()))->toBeNull();

    $user->forceFill(['status' => App\Enums\UserStatus::Active, 'role' => App\Enums\UserRole::Broker])->save();
    expect(app(ClaimManagedProfile::class)->candidateFor($user->refresh()))->toBeNull();
});

it('B.15: ProfileClaimed carries the code only', function (): void {
    $event = new ProfileClaimed('01HBROKERULIDXXXXXXXXXXXXX', 'OPM12345');

    expect($event->broadcastWith())->toBe(['profile' => 'OPM12345'])
        ->and($event->broadcastOn()[0]->name)->toBe('private-broker.01HBROKERULIDXXXXXXXXXXXXX')
        ->and($event->queue)->toBe('broadcasts');
    expect(Broker::query()->count())->toBe(0);
});
