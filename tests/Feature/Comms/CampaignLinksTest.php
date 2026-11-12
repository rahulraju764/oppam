<?php

declare(strict_types=1);

use App\Actions\Admin\Comms\SubmitCampaign;
use App\Contracts\MailBounceVerifier;
use App\Domain\Comms\CampaignLinks;
use App\Enums\NotificationEvent;
use App\Enums\OutboundStatus;
use App\Jobs\Comms\SendCampaignBatch;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\NotificationPreference;
use App\Models\OutboundMessage;
use App\Notifications\Comms\CampaignMessage;
use App\Queries\Admin\Comms\CampaignStats;
use App\Services\Mail\MailgunBounceVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/*
| P8.3b1 — the links in a campaign message: a signed click (counted, then the button's page) and a
| signed one-click unsubscribe (RFC 8058; switches off offer EMAIL in /settings). Neither needs a
| sign-in; a tampered or foreign link does nothing. Opens / deliveries come from Mailgun's signed
| events only (no tracking pixel).
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    Queue::fake([SendCampaignBatch::class]);
});

/** @return array{0: Campaign, 1: CampaignRecipient, 2: App\Models\User} a submitted email campaign to one member */
function linkedCampaign(array $overrides = []): array
{
    $admin = adminWithRole();
    $member = campaignMember();
    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin, $overrides));

    return [$campaign, CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('user_id', $member->id)->sole(), $member];
}

it('A14 click: the signed link counts the first click only and goes to the button page', function (): void {
    [$campaign, $recipient] = linkedCampaign();
    $url = app(CampaignLinks::class)->click($campaign, $recipient);

    $this->get($url)->assertRedirect(url('/plans'));
    $first = $recipient->refresh()->clicked_at;
    $this->travel(5)->minutes();
    $this->get($url)->assertRedirect(url('/plans'));

    expect($first)->not->toBeNull()->and($recipient->refresh()->clicked_at->equalTo($first))->toBeTrue()
        ->and(app(CampaignStats::class)->one($campaign)->clicked)->toBe(1);
});

it('A14 click: an external https button goes there (only the address the admin saved — the link carries no destination)', function (): void {
    [$campaign, $recipient] = linkedCampaign(['actionUrl' => 'https://example.org/onam']);

    $this->get(app(CampaignLinks::class)->click($campaign, $recipient))->assertRedirect('https://example.org/onam');
});

it('A14 links security: more than 60 hits a minute from one address → 429 (rate limit)', function (): void {
    [$campaign, $recipient] = linkedCampaign();
    $url = app(CampaignLinks::class)->click($campaign, $recipient);

    foreach (range(1, 60) as $_) {
        $this->get($url)->assertRedirect();
    }
    $this->get($url)->assertStatus(429);
});

it('A14 links security: unsigned, tampered, or another campaign\'s recipient → refused, nothing counted', function (Closure $url, int $status): void {
    [$campaign, $recipient] = linkedCampaign();
    [$other] = linkedCampaign();

    $this->get($url($campaign, $recipient, $other))->assertStatus($status);
    expect($recipient->refresh()->clicked_at)->toBeNull();
})->with([
    'unsigned' => [fn ($c, $r) => route('campaigns.click', ['campaign' => $c->code, 'recipient' => $r->id]), 403],
    'tampered recipient' => [fn ($c, $r) => str_replace('/click/'.$r->id.'?', '/click/'.($r->id + 1).'?', app(CampaignLinks::class)->click($c, $r)), 403],
    'recipient of another campaign (validly signed)' => [fn ($c, $r, $o) => Illuminate\Support\Facades\URL::signedRoute('campaigns.click', ['campaign' => $o->code, 'recipient' => $r->id]), 404],
    'unknown campaign (validly signed)' => [fn ($c, $r) => Illuminate\Support\Facades\URL::signedRoute('campaigns.click', ['campaign' => 'CMPZZZZZZZZ', 'recipient' => $r->id]), 404],
]);

it('A14 unsubscribe: GET only asks (mail scanners), POST switches offer EMAIL off — other channels untouched; later campaigns skip them', function (): void {
    [$campaign, $recipient, $member] = linkedCampaign();
    $url = app(CampaignLinks::class)->unsubscribe($campaign, $recipient);

    $this->get($url)->assertOk()->assertSee('Stop offer emails?');
    expect(NotificationPreference::query()->where('user_id', $member->id)->exists())->toBeFalse();

    $this->post($url)->assertOk()->assertSee('You’re unsubscribed', false);   // no CSRF token: RFC 8058 one-click
    $this->post($url)->assertOk();                                           // idempotent

    $pref = NotificationPreference::query()->where('user_id', $member->id)->where('event', NotificationEvent::AdminAnnouncement->value)->sole();
    expect($pref->email)->toBeFalse()->and($pref->in_app)->toBeTrue()->and($pref->sms)->toBeFalse()
        ->and(app(CampaignStats::class)->one($campaign)->unsubscribed)->toBe(1);

    $admin = adminWithRole();
    expect(fn () => app(SubmitCampaign::class)->handle($admin, draftCampaign($admin)))
        ->toThrow(App\Exceptions\Admin\CommsRefused::class);   // nobody left to email
});

it('A14 unsubscribe: an unsigned POST changes nothing', function (): void {
    [$campaign, $recipient, $member] = linkedCampaign();

    $this->post(route('campaigns.unsubscribe', ['campaign' => $campaign->code, 'recipient' => $recipient->id]))->assertForbidden();
    expect(NotificationPreference::query()->where('user_id', $member->id)->exists())->toBeFalse();
});

it('A14 email: the campaign mail carries the signed button, the unsubscribe link + RFC 8058 headers and the settings link; {first_name} filled', function (): void {
    [$campaign, $recipient, $member] = linkedCampaign();
    $mail = (new CampaignMessage($campaign, $recipient))->toMail($member);
    $rendered = (string) $mail->render();

    $symfony = new Symfony\Component\Mime\Email;
    foreach ($mail->callbacks as $callback) {
        $callback($symfony);
    }

    expect($mail->subject)->toBe('Onam offer for '.$member->profile->first_name)
        ->and($mail->actionUrl)->toContain('/c/'.$campaign->code.'/click/'.$recipient->id)->toContain('signature=')
        ->and($rendered)->toContain('/c/'.$campaign->code.'/unsubscribe/'.$recipient->id)
        ->and($rendered)->toContain('settings?tab=notifications')
        ->and($rendered)->toContain('Gold</strong>')
        ->and($symfony->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString())->toBe('List-Unsubscribe=One-Click')
        ->and($symfony->getHeaders()->get('List-Unsubscribe')?->getBodyAsString())->toContain('/unsubscribe/'.$recipient->id);
});

it('A14 email: an admin\'s A14 template for "admin_announcement" never rewords a campaign (its copy is frozen)', function (): void {
    [$campaign, $recipient, $member] = linkedCampaign();
    app(App\Actions\Admin\Comms\SaveTemplate::class)->handle(adminWithRole(), 'admin_announcement', App\Enums\CommsChannel::Email, 'Other', 'Other text', null);

    $message = new CampaignMessage($campaign, $recipient);
    expect($message->templatedMail($member))->toBeNull()
        ->and($message->toMail($member)->subject)->toStartWith('Onam offer for');
});

it('A14 stats: Mailgun signed "delivered" and "opened" events count on the campaign; a replayed event or a second open changes nothing', function (): void {
    app()->instance(MailBounceVerifier::class, new MailgunBounceVerifier('mg-key'));
    [$campaign] = linkedCampaign();
    runCampaignBatch($campaign);
    $row = OutboundMessage::query()->where('campaign_id', $campaign->id)->sole();
    $row->forceFill(['provider_id' => 'msg-1@mail.oppam.test'])->save();

    $event = function (string $kind, string $token) {
        $ts = (string) time();

        return ['signature' => ['timestamp' => $ts, 'token' => $token, 'signature' => hash_hmac('sha256', $ts.$token, 'mg-key')],
            'event-data' => ['event' => $kind, 'message' => ['headers' => ['message-id' => '<msg-1@mail.oppam.test>']]]];
    };

    $this->postJson(memberUrl('/webhooks/mail/bounce'), $event('delivered', 't1'))->assertOk()->assertJson(['updated' => 1]);
    $this->postJson(memberUrl('/webhooks/mail/bounce'), $event('opened', 't2'))->assertOk()->assertJson(['updated' => 1]);
    $this->postJson(memberUrl('/webhooks/mail/bounce'), $event('opened', 't2'))->assertForbidden();   // replayed token
    $this->postJson(memberUrl('/webhooks/mail/bounce'), $event('opened', 't3'))->assertJson(['updated' => 0]);   // counted once

    $stats = app(CampaignStats::class)->one($campaign);
    expect($row->refresh()->status)->toBe(OutboundStatus::Delivered)
        ->and($stats->delivered)->toBe(1)->and($stats->opened)->toBe(1);
});
