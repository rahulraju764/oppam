<?php

declare(strict_types=1);

use App\Actions\Admin\Comms\RevertTemplate;
use App\Actions\Admin\Comms\SaveTemplate;
use App\Actions\Admin\Comms\SendTestTemplate;
use App\Enums\CommsChannel;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AuditLog;
use App\Models\CommunicationTemplate;
use App\Models\CommunicationTemplateVersion;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Profile\ProfileApproved;
use App\Notifications\Support\InAppChannel;
use App\Notifications\Support\SmsChannel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/*
| P8.3a — A14 template manager: "email (Markdown), SMS (DLT template id), in-app copy per event
| type; versioned; test-send to self". Proved where it matters: a saved template changes what a
| real notification sends on that channel, and the code's copy is used when there is none.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    $this->sms = fakeSms();
});

/** A member who gets email (verified) and SMS. */
function tmMember(): User
{
    $member = memberWithPhone('+919811500001');
    $member->forceFill(['email' => 'anjali.tm@example.com', 'email_verified_at' => now()])->save();

    return $member->refresh();
}

/** @return list<Symfony\Component\Mime\Email> */
function tmMails(): array
{
    return array_map(fn ($sent) => $sent->getOriginalMessage(), iterator_to_array(app('mailer')->getSymfonyTransport()->messages()));
}

it('A14: every save is a new version and goes live; versions are append-only; revert copies an old one forward', function (): void {
    $admin = adminWithRole('content_editor');
    $save = app(SaveTemplate::class);

    $save->handle($admin, 'profile_approved', CommsChannel::Email, 'Live now', 'Hello {first_name}, version one.', null);
    $template = $save->handle($admin, 'profile_approved', CommsChannel::Email, 'Live now!', 'Hello {first_name}, version two.', null);

    expect($template->key)->toBe('profile_approved.email')
        ->and($template->current_version)->toBe(2)
        ->and(CommunicationTemplateVersion::query()->where('template_id', $template->id)->count())->toBe(2);

    $old = CommunicationTemplateVersion::query()->where('template_id', $template->id)->where('version', 1)->sole();
    expect(fn () => $old->forceFill(['body' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $old->delete())->toThrow(LogicException::class);

    $reverted = app(RevertTemplate::class)->handle($admin, $template, 1);
    expect($reverted->current_version)->toBe(3)
        ->and($reverted->currentBody())->toBe('Hello {first_name}, version one.');

    $audit = AuditLog::query()->where('action', 'comms.template_updated')->latest('id')->first();
    expect(json_encode($audit?->after))->not->toContain('version one');   // never the text
});

it('A14 / R-M08: a live email template changes the real email; Markdown is escaped; the preferences link stays', function (): void {
    $member = tmMember();
    app(SaveTemplate::class)->handle(adminWithRole(), 'profile_approved', CommsChannel::Email,
        'Welcome, {first_name}!', "**You are live.**\n\nOpen it: {url}\n\n<script>alert(1)</script>", null);

    app(MailChannel::class)->send($member, new ProfileApproved($member->profile->code));

    $mail = collect(tmMails())->last();
    $html = (string) $mail->getHtmlBody();
    expect($mail->getSubject())->toBe('Welcome, '.$member->profile->first_name.'!')
        ->and($html)->toMatch('#<strong[^>]*>You are live\.</strong>#')   // the mail theme inlines a style on it
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;')
        ->and($html)->toContain('notification settings');
});

it('P2.2 kept: a template for the saved-search alert still carries its own unsubscribe link and List-Unsubscribe header', function (): void {
    $member = tmMember();
    $search = App\Models\SavedSearch::factory()->create(['profile_id' => $member->profile->id]);
    app(SaveTemplate::class)->handle(adminWithRole(), 'saved_search_alert', CommsChannel::Email, 'New for you', 'Fresh profiles: {url}', null);

    app(MailChannel::class)->send($member, new App\Notifications\Member\SavedSearchMatchesNotification($search, 3));

    $mail = collect(tmMails())->last();
    $unsubscribe = route('saved-searches.unsubscribe', ['token' => $search->alert_token]);
    expect($mail->getSubject())->toBe('New for you')
        ->and((string) $mail->getHtmlBody())->toContain(e($unsubscribe))
        ->and($mail->getHeaders()->get('List-Unsubscribe')?->getBodyAsString())->toBe('<'.$unsubscribe.'>')
        ->and($mail->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString())->toBe('List-Unsubscribe=One-Click');
});

it('A14: images in template Markdown become plain text — a template never loads a remote image', function (): void {
    $html = (string) App\Domain\Comms\TemplateCopy::markdown('Hello ![tracker](https://evil.example/p.gif) **there**');

    expect($html)->not->toContain('<img')->not->toContain('evil.example')->toContain('tracker')->toContain('<strong>there</strong>');

    // Reference-style images too (review round 2).
    $reference = (string) App\Domain\Comms\TemplateCopy::markdown("Hi ![pixel][p]\n\n[p]: https://evil.test/pixel.png");
    expect($reference)->not->toContain('<img')->not->toContain('evil.test')->toContain('pixel');
});

it('A14: with no template (or an inactive one) the code\'s own email copy is sent', function (): void {
    $member = tmMember();

    app(MailChannel::class)->send($member, new ProfileApproved($member->profile->code));
    expect(collect(tmMails())->last()->getSubject())->toBe('Your Oppam Matrimony profile is live');

    $template = app(SaveTemplate::class)->handle(adminWithRole(), 'profile_approved', CommsChannel::Email, 'Custom', 'Custom body', null);
    $template->forceFill(['is_active' => false])->save();
    Cache::flush();
    app(MailChannel::class)->send($member, new ProfileApproved($member->profile->code));
    expect(collect(tmMails())->last()->getSubject())->toBe('Your Oppam Matrimony profile is live');
});

it('A14: in-app copy — the first line is the title, the rest the text, placeholders filled', function (): void {
    $member = tmMember();
    app(SaveTemplate::class)->handle(adminWithRole(), 'profile_approved', CommsChannel::InApp, null, "Hi {first_name}!\n{default_body}", null);

    $row = (new ProfileApproved($member->profile->code))->toArray($member);

    expect($row['title'])->toBe('Hi '.$member->profile->first_name.'!')
        ->and($row['body'])->toContain($member->profile->code)   // {default_body} = the standard text
        ->and($row['type'])->toBe('profile_approved');
});

it('A14: an SMS template sends with its own DLT template id; without one the configured id is used', function (): void {
    $member = tmMember();

    app(SmsChannel::class)->send($member, new ProfileApproved($member->profile->code));
    expect($this->sms->notices[0]['template_id'])->toBeNull();

    app(SaveTemplate::class)->handle(adminWithRole(), 'profile_approved', CommsChannel::Sms, null, 'Your Oppam profile {#var#} is live.', '1107123456789');
    app(SmsChannel::class)->send($member, new ProfileApproved($member->profile->code));
    expect($this->sms->notices[1]['template_id'])->toBe('1107123456789');
});

it('A14 validation: channel rules, unknown placeholders and events that never use the channel are refused, nothing saved', function (string $case): void {
    $admin = adminWithRole();
    $call = match ($case) {
        'email without subject' => fn () => app(SaveTemplate::class)->handle($admin, 'profile_approved', CommsChannel::Email, '', 'Body', null),
        'sms without DLT id' => fn () => app(SaveTemplate::class)->handle($admin, 'profile_approved', CommsChannel::Sms, null, 'Body', ''),
        'sms too long' => fn () => app(SaveTemplate::class)->handle($admin, 'profile_approved', CommsChannel::Sms, null, str_repeat('a', 321), '1107123456789'),
        'unknown placeholder' => fn () => app(SaveTemplate::class)->handle($admin, 'profile_approved', CommsChannel::Email, 'Hi', 'Your phone {phone}', null),
        'event without SMS' => fn () => app(SaveTemplate::class)->handle($admin, 'profile_liked', CommsChannel::Sms, null, 'x', '1107123456789'),
        'unknown event' => fn () => app(SaveTemplate::class)->handle($admin, 'made_up', CommsChannel::Email, 'Hi', 'x', null),
        'bad campaign key' => fn () => app(SaveTemplate::class)->handle($admin, 'campaign', CommsChannel::Email, 'Hi', 'x', null, 'Onam', 'Bad Key!'),
    };

    expect($call)->toThrow(ValidationException::class);
    expect(CommunicationTemplate::query()->count())->toBe(0);
})->with(['email without subject', 'sms without DLT id', 'sms too long', 'unknown placeholder', 'event without SMS', 'unknown event', 'bad campaign key']);

it('A14: campaign texts get their own key and live next to notification copy', function (): void {
    $template = app(SaveTemplate::class)->handle(adminWithRole(), 'campaign', CommsChannel::Email, 'Onam offer', 'Hello {first_name}', null, 'Onam offer', 'onam_2026');

    expect($template->key)->toBe('campaign.onam_2026')->and($template->currentBody())->toBe('Hello {first_name}');
});

it('A14 test-send: a real email to the admin\'s own address with sample values, logged; in-app has nothing to send', function (): void {
    $admin = adminWithRole();
    $email = app(SaveTemplate::class)->handle($admin, 'profile_approved', CommsChannel::Email, 'Hi {first_name}', 'Live at {url}', null);

    expect(app(SendTestTemplate::class)->handle($admin, $email))->toBe(1);
    $mail = collect(tmMails())->last();
    expect($mail->getTo()[0]->getAddress())->toBe($admin->email)
        ->and($mail->getSubject())->toBe('[TEST v1] Hi Anjali')
        ->and(OutboundMessage::query()->where('event', 'template_test_message')->count())->toBe(1);

    $inApp = app(SaveTemplate::class)->handle($admin, 'profile_approved', CommsChannel::InApp, null, 'Title', null);
    expect(app(SendTestTemplate::class)->handle($admin, $inApp))->toBe(0);
});

it('A14 test-send SMS: only to the configured test numbers — none set → refused', function (): void {
    $admin = adminWithRole();
    $sms = app(SaveTemplate::class)->handle($admin, 'profile_approved', CommsChannel::Sms, null, 'Live {#var#}', '1107123456789');

    config(['oppam.comms.test_phones' => []]);
    expect(fn () => app(SendTestTemplate::class)->handle($admin, $sms))->toThrow(CommsRefused::class);
    expect($this->sms->notices)->toBe([]);

    config(['oppam.comms.test_phones' => ['+919811599999']]);
    expect(app(SendTestTemplate::class)->handle($admin, $sms))->toBe(1)
        ->and($this->sms->notices[0]['to'])->toBe('+919811599999')
        ->and($this->sms->notices[0]['template_id'])->toBe('1107123456789');
});

it('rule 10: test-sends are limited per admin per hour', function (): void {
    config(['oppam.comms.test_sends_per_hour' => 2]);
    $admin = adminWithRole();
    $email = app(SaveTemplate::class)->handle($admin, 'profile_approved', CommsChannel::Email, 'Hi', 'Body', null);

    app(SendTestTemplate::class)->handle($admin, $email);
    app(SendTestTemplate::class)->handle($admin, $email);
    expect(fn () => app(SendTestTemplate::class)->handle($admin, $email))->toThrow(CommsRefused::class);
});

it('templates.edit is required to save, revert or test-send (403)', function (): void {
    $support = adminWithRole('support');   // no templates.edit
    $template = app(SaveTemplate::class)->handle(adminWithRole(), 'profile_approved', CommsChannel::Email, 'Hi', 'Body', null);

    expect(fn () => app(SaveTemplate::class)->handle($support, 'profile_approved', CommsChannel::Email, 'Hi', 'Other', null))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RevertTemplate::class)->handle($support, $template, 1))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SendTestTemplate::class)->handle($support, $template))->toThrow(AuthorizationException::class);
});

it('the in-app router still decides the channels: a template never adds one', function (): void {
    $member = tmMember();
    app(SaveTemplate::class)->handle(adminWithRole(), 'profile_approved', CommsChannel::InApp, null, 'Hello', null);

    expect((new ProfileApproved($member->profile->code))->via($member))->toContain(InAppChannel::class);
});
