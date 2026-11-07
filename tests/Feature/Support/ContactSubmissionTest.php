<?php

declare(strict_types=1);

use App\Actions\Admin\Contact\ManageContactSubmission;
use App\Actions\Support\SubmitContact;
use App\Contracts\CaptchaVerifier;
use App\Data\Support\ContactRequestData;
use App\Enums\ContactStatus;
use App\Enums\SettingKey;
use App\Enums\TicketSource;
use App\Exceptions\Admin\ContactRefused;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\SafetyRefused;
use App\Jobs\Support\PruneContactSubmissions;
use App\Livewire\Public\ContactForm;
use App\Models\AuditLog;
use App\Models\ContactReply;
use App\Models\ContactSubmission;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Notifications\Support\ContactReceived;
use App\Notifications\Support\ContactReplied;
use App\Services\Captcha\TurnstileVerifier;
use App\Services\Settings\SettingsRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| M12 contact form → A08 contact inbox (P8.1b, owner 2026-11-02 "inbox first"): honeypot, Turnstile,
| rate limit, spam tab, support emailed; the team assigns, replies, converts to a ticket, marks spam.
*/

beforeEach(function (): void {
    Notification::fake();
    RateLimiter::clear('contact:ip:10.9.9.9');
    Setting::query()->where('key', SettingKey::SiteSupportEmail->value)->delete();
    Setting::factory()->keyed(SettingKey::SiteSupportEmail, 'help@oppam.test')->create();
    app(SettingsRepository::class)->flush();
});

function contactFormData(array $overrides = []): ContactRequestData
{
    return new ContactRequestData(...[...['name' => 'Asha Nair', 'email' => 'Asha@Example.com', 'category' => 'OTHER',
        'message' => 'Do you have a branch in Thrissur?', 'honeypot' => '', 'captchaToken' => 'token'], ...$overrides]);
}

function submitContact(array $overrides = [], ?App\Models\User $member = null): ?ContactSubmission
{
    return app(SubmitContact::class)->handle($member, contactFormData($overrides), '10.9.9.9');
}

/** A captcha that always says no (the provider rejected the token). */
function captchaRefuses(): void
{
    app()->instance(CaptchaVerifier::class, new class implements CaptchaVerifier
    {
        public function enabled(): bool
        {
            return true;
        }

        public function siteKey(): string
        {
            return 'site';
        }

        public function verify(?string $token, string $ip): bool
        {
            return false;
        }
    });
}

it('stores the message in the inbox, hashes the IP and emails support (M12)', function (): void {
    $submission = submitContact();

    expect($submission->status)->toBe(ContactStatus::New)
        ->and($submission->email)->toBe('asha@example.com')
        ->and($submission->ip_hash)->not->toBe('10.9.9.9')->toHaveLength(64)
        ->and($submission->code)->toStartWith('CNT');
    Notification::assertSentOnDemand(ContactReceived::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'help@oppam.test');
});

it('stores nothing for a bot that fills the honeypot, and shows it the same thank-you (M12)', function (): void {
    expect(submitContact(['honeypot' => 'http://spam.example']))->toBeNull()
        ->and(ContactSubmission::query()->count())->toBe(0);

    Livewire::test(ContactForm::class)
        ->set('firstName', 'Bot')->set('email', 'bot@example.com')->set('category', 'OTHER')
        ->set('message', 'Buy cheap things now please')->set('website', 'x')
        ->call('send')->assertSet('sentCode', '—');
    expect(ContactSubmission::query()->count())->toBe(0);
});

it('refuses a message the robot check rejected, and stores nothing (M12 Turnstile)', function (): void {
    captchaRefuses();

    expect(fn () => submitContact())->toThrow(ValidationException::class);
    expect(ContactSubmission::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('verifies the token with Cloudflare and fails closed when it is down (M12 Turnstile)', function (): void {
    $verifier = new TurnstileVerifier('site', 'secret');

    Http::fake([TurnstileVerifier::VERIFY_URL => Http::sequence()
        ->push(['success' => true])
        ->push(['success' => false])
        ->push('', 500)]);

    expect($verifier->verify('good-token', '10.0.0.1'))->toBeTrue();
    Http::assertSent(fn ($request) => $request['secret'] === 'secret' && $request['response'] === 'good-token');
    expect($verifier->verify('bad', '10.0.0.1'))->toBeFalse();
    expect($verifier->verify('any', '10.0.0.1'))->toBeFalse()
        ->and($verifier->verify(null, '10.0.0.1'))->toBeFalse();
});

it('rate-limits a visitor per IP (rule 10)', function (): void {
    Setting::factory()->keyed(SettingKey::ContactMaxPerHour, 2)->create();
    app(SettingsRepository::class)->flush();

    submitContact(['message' => 'First message here.']);
    submitContact(['message' => 'Second message here.']);

    expect(fn () => submitContact(['message' => 'Third message here.']))->toThrow(SafetyRefused::class);
    expect(ContactSubmission::query()->count())->toBe(2);
});

it('validates the form and stores nothing when it is wrong (M12)', function (array $overrides): void {
    expect(fn () => submitContact($overrides))->toThrow(ValidationException::class);
    expect(ContactSubmission::query()->count())->toBe(0);
})->with([
    'no email' => [['email' => '']],
    'bad email' => [['email' => 'not-an-email']],
    'short message' => [['message' => 'Hi']],
    'unknown topic' => [['category' => 'LOTTERY']],
    'one-letter name' => [['name' => 'A']],
]);

it('puts likely spam in the spam tab without emailing anyone (M12)', function (): void {
    $links = submitContact(['message' => 'Visit https://a.example https://b.example https://c.example now']);
    $first = submitContact(['message' => 'The same text every time.']);
    $repeat = submitContact(['message' => 'The same text every time.']);

    expect($links->status)->toBe(ContactStatus::Spam)->and($links->spam_reason)->toBe('links')
        ->and($first->status)->toBe(ContactStatus::New)
        ->and($repeat->status)->toBe(ContactStatus::Spam)->and($repeat->spam_reason)->toBe('repeat');
    Notification::assertSentOnDemandTimes(ContactReceived::class, 1);
});

it('refuses a signed-in member’s message during an admin support session (A01)', function (): void {
    $member = memberWithPhone();
    chatImpersonate($member);

    expect(fn () => submitContact([], $member))->toThrow(ImpersonationRestricted::class);
});

it('links the message to the member who was signed in (M12)', function (): void {
    $member = memberWithPhone();

    expect(submitContact([], $member)->user_id)->toBe($member->id);
});

it('lets support reply by email; the audit log keeps no text or address (A08 inbox)', function (): void {
    seedAdminRoles();
    $admin = contentAdmin('support.view', 'support.respond');
    $submission = submitContact();

    app(ManageContactSubmission::class)->reply($admin, $submission, 'Yes, on MG Road.');

    expect($submission->refresh()->status)->toBe(ContactStatus::Replied)
        ->and($submission->assigned_admin_id)->toBe($admin->id)
        ->and(ContactReply::query()->where('contact_submission_id', $submission->id)->value('body'))->toBe('Yes, on MG Road.');
    Notification::assertSentOnDemand(ContactReplied::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'asha@example.com');
    $audit = AuditLog::query()->where('action', 'contact.replied')->sole();
    expect(json_encode([$audit->before, $audit->after]))->not->toContain('MG Road')->not->toContain('asha@');
});

it('converts a message into a support ticket once (A08 inbox → A10)', function (): void {
    seedAdminRoles();
    $admin = contentAdmin('support.view', 'support.respond');
    $submission = submitContact();

    $ticket = app(ManageContactSubmission::class)->convert($admin, $submission);

    expect($ticket->source)->toBe(TicketSource::ContactForm)->and($ticket->email)->toBe('asha@example.com')
        ->and($submission->refresh()->status)->toBe(ContactStatus::Converted)
        ->and($submission->support_ticket_id)->toBe($ticket->id);
    expect(fn () => app(ManageContactSubmission::class)->convert($admin, $submission->refresh()))->toThrow(ContactRefused::class);
    expect(SupportTicket::query()->count())->toBe(1);
});

it('moves a message to spam and back, and refuses replies while it is spam (A08 spam tab)', function (): void {
    seedAdminRoles();
    $admin = contentAdmin('support.view', 'support.respond');
    $manage = app(ManageContactSubmission::class);
    $submission = submitContact();

    $manage->markSpam($admin, $submission, true);
    expect($submission->refresh()->status)->toBe(ContactStatus::Spam);
    expect(fn () => $manage->reply($admin, $submission->refresh(), 'Hello there'))->toThrow(ContactRefused::class);

    $manage->markSpam($admin, $submission->refresh(), false);
    expect($submission->refresh()->status)->toBe(ContactStatus::New);
});

it('needs support.respond for every change, and refuses a stale screen (A08, security matrix)', function (): void {
    seedAdminRoles();
    $viewer = contentAdmin('support.view');
    $responder = contentAdmin('support.view', 'support.respond');
    $submission = submitContact();
    $manage = app(ManageContactSubmission::class);

    expect(fn () => $manage->reply($viewer, $submission, 'Hello there'))->toThrow(AuthorizationException::class);
    expect(fn () => $manage->markSpam($viewer, $submission, true))->toThrow(AuthorizationException::class);
    expect(fn () => $manage->assign($responder, $submission, $viewer))->toThrow(ContactRefused::class);
    expect(fn () => $manage->reply($responder, $submission, 'Hello there', $submission->lock_version + 5))->toThrow(ContactRefused::class);
    expect($submission->refresh()->status)->toBe(ContactStatus::New);
});

it('deletes spam after 30 days and everything after a year (P8.1b retention)', function (): void {
    $oldSpam = ContactSubmission::factory()->spam()->create(['created_at' => now()->subDays(31)]);
    $newSpam = ContactSubmission::factory()->spam()->create(['created_at' => now()->subDays(5)]);
    $oldMessage = ContactSubmission::factory()->create(['created_at' => now()->subDays(366)]);
    $recent = ContactSubmission::factory()->create(['created_at' => now()->subDays(100)]);

    app()->call([new PruneContactSubmissions, 'handle']);

    expect(ContactSubmission::query()->pluck('id')->all())->toEqualCanonicalizing([$newSpam->id, $recent->id]);
    expect(ContactSubmission::query()->whereKey([$oldSpam->id, $oldMessage->id])->exists())->toBeFalse();
});

it('erases a purged member’s contact messages and clears the IP on their acceptances (DPDP, P8.1b)', function (): void {
    $member = memberWithPhone();
    submitContact([], $member);
    $member->delete();
    $this->travel(40)->days();

    app(App\Actions\Admin\Members\PurgeDeletedMember::class)->handle($member->fresh() ?? App\Models\User::withTrashed()->find($member->id));

    expect(ContactSubmission::query()->where('user_id', $member->id)->exists())->toBeFalse();
});
