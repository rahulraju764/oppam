<?php

declare(strict_types=1);

use App\Actions\Admin\Comms\ApproveCampaign;
use App\Actions\Admin\Comms\CancelCampaign;
use App\Actions\Admin\Comms\ResendFailedMessage;
use App\Actions\Admin\Comms\SaveCampaign;
use App\Actions\Admin\Comms\SendTestCampaign;
use App\Actions\Admin\Comms\SubmitCampaign;
use App\Actions\Admin\Members\PurgeDeletedMember;
use App\Contracts\SmsGateway;
use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\CommsChannel;
use App\Enums\OutboundStatus;
use App\Enums\SettingKey;
use App\Exceptions\Admin\CommsRefused;
use App\Jobs\Comms\SendCampaignBatch;
use App\Jobs\Comms\StartDueCampaigns;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\OutboundMessage;
use App\Notifications\Admin\CampaignTestMessage;
use App\Notifications\Support\NotificationRouter;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeSmsGateway;

/*
| P8.3b1 — A14 campaigns: save (validated), submit (audience frozen), second approval above the A15
| threshold by a DIFFERENT admin, scheduled start, throttled batches, cancel in flight, test send,
| never resent, purge. The security matrix: wrong permission, tampered state, invalid input, rate limit.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    Queue::fake([SendCampaignBatch::class]);
});

it('A14 save: an invalid draft shows field errors and writes nothing', function (array $overrides, string $field): void {
    expect(fn () => draftCampaign(adminWithRole(), $overrides))->toThrow(ValidationException::class);

    try {
        draftCampaign(adminWithRole(), $overrides);
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
    expect(Campaign::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ' '], 'name'],
    'email without subject' => [['subject' => ''], 'subject'],
    'in-app over 500' => [['channel' => CommsChannel::InApp, 'message' => str_repeat('a', 501)], 'message'],
    'SMS without DLT id' => [['channel' => CommsChannel::Sms, 'dltTemplateId' => 'abc'], 'dltTemplateId'],
    'unknown placeholder' => [['message' => 'Hi {phone}'], 'message'],
    'javascript: link' => [['actionUrl' => 'javascript:alert(1)'], 'actionUrl'],
    'http (not https) link' => [['actionUrl' => 'http://evil.example'], 'actionUrl'],
    'button text without link' => [['actionUrl' => '', 'actionText' => 'Go'], 'actionText'],
    'throttle above A15 max' => [['throttlePerMinute' => 1_000_001], 'throttlePerMinute'],
    'throttle zero' => [['throttlePerMinute' => 0], 'throttlePerMinute'],
    'start in the past' => [['scheduledAt' => CarbonImmutable::now()->subMinute()], 'scheduledAt'],
]);

it('A14 save: a draft gets a CMP code, keeps typed filters, drops SMS-only/irrelevant fields, and is audited without the text', function (): void {
    $campaign = draftCampaign(adminWithRole(), ['segment' => App\Data\Comms\CampaignSegment::fromArray([
        'gender' => 'FEMALE', 'plan' => 'GOLD', 'has_photo' => true, 'religion_id' => 'x', 'evil' => 'drop me',
    ])]);

    expect($campaign->code)->toMatch('/^CMP[A-Z0-9]{8}$/')
        ->and($campaign->status)->toBe(CampaignStatus::Draft)
        ->and($campaign->segment_filters)->toBe(['plan' => 'GOLD', 'gender' => 'FEMALE', 'has_photo' => true]);

    $audit = AuditLog::query()->where('action', 'comms.campaign_created')->sole();
    expect(json_encode($audit->after))->not->toContain('20 % off');
});

it('A14 security: every campaign write needs its permission (campaigns.view alone can do nothing)', function (Closure $call): void {
    $viewer = adminWithRole('content_editor');   // campaigns.view, templates.edit
    $campaign = draftCampaign();
    campaignMember();

    expect(fn () => $call($viewer, $campaign))->toThrow(AuthorizationException::class);
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Draft)
        ->and(CampaignRecipient::query()->count())->toBe(0);
})->with([
    'save' => [fn ($admin) => app(SaveCampaign::class)->handle($admin, campaignData())],
    'submit' => [fn ($admin, $c) => app(SubmitCampaign::class)->handle($admin, $c)],
    'approve' => [fn ($admin, $c) => app(ApproveCampaign::class)->handle($admin, $c)],
    'cancel' => [fn ($admin, $c) => app(CancelCampaign::class)->handle($admin, $c)],
    'test send' => [fn ($admin, $c) => app(SendTestCampaign::class)->handle($admin, $c)],
]);

it('A14 submit: nobody in the segment → refused and nothing is kept; only a draft can be submitted or edited', function (): void {
    $admin = adminWithRole();
    $campaign = draftCampaign($admin);

    expect(fn () => app(SubmitCampaign::class)->handle($admin, $campaign))->toThrow(CommsRefused::class);
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Draft)->and(CampaignRecipient::query()->count())->toBe(0);

    campaignMember();
    app(SubmitCampaign::class)->handle($admin, $campaign);

    expect(fn () => app(SubmitCampaign::class)->handle($admin, $campaign))->toThrow(CommsRefused::class)
        ->and(fn () => app(SaveCampaign::class)->handle($admin, campaignData(['name' => 'Changed']), $campaign))->toThrow(CommsRefused::class)
        ->and(CampaignRecipient::query()->count())->toBe(1);
});

it('A14 approval: above the A15 threshold it waits; the creator / submitter can never approve it (super admins included); a different admin starts it', function (): void {
    settingIs(SettingKey::CampaignsApprovalThreshold, 2);
    $creator = adminWithRole();
    $submitter = adminWithRole();
    $approver = adminWithRole();
    campaignMember();
    campaignMember();
    campaignMember();

    $campaign = draftCampaign($creator);
    $campaign = app(SubmitCampaign::class)->handle($submitter, $campaign);
    expect($campaign->status)->toBe(CampaignStatus::PendingApproval)->and($campaign->recipient_count)->toBe(3);
    Queue::assertNothingPushed();

    foreach ([$creator, $submitter] as $own) {
        expect(fn () => app(ApproveCampaign::class)->handle($own, $campaign))->toThrow(CommsRefused::class);
    }
    expect($campaign->refresh()->status)->toBe(CampaignStatus::PendingApproval);

    $campaign = app(ApproveCampaign::class)->handle($approver, $campaign);
    expect($campaign->status)->toBe(CampaignStatus::Sending)->and($campaign->approved_by)->toBe($approver->id);
    Queue::assertPushed(SendCampaignBatch::class, 1);

    expect(fn () => app(ApproveCampaign::class)->handle($approver, $campaign))->toThrow(CommsRefused::class);
    expect(AuditLog::query()->where('action', 'comms.campaign_approved')->count())->toBe(1);
});

it('A14 approval: at or below the threshold it starts at once; with a future start it is SCHEDULED and StartDueCampaigns starts it when due', function (): void {
    $admin = adminWithRole();
    campaignMember();

    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin, ['scheduledAt' => CarbonImmutable::now()->addHour()]));
    expect($campaign->status)->toBe(CampaignStatus::Scheduled);

    (new StartDueCampaigns)->handle(app(App\Domain\Comms\CampaignStarter::class));
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Scheduled);
    Queue::assertNothingPushed();

    $this->travel(61)->minutes();
    (new StartDueCampaigns)->handle(app(App\Domain\Comms\CampaignStarter::class));
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Sending);
    Queue::assertPushed(SendCampaignBatch::class, 1);
});

it('A14 throttle: one batch sends at most throttle_per_minute, then queues the next a minute later; it completes when nobody is left', function (): void {
    $admin = adminWithRole();
    foreach (range(1, 5) as $i) {
        campaignMember();
    }
    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin, ['throttlePerMinute' => 2]));

    runCampaignBatch($campaign);
    expect(OutboundMessage::query()->where('campaign_id', $campaign->id)->count())->toBe(2)
        ->and($campaign->status)->toBe(CampaignStatus::Sending);
    Queue::assertPushed(SendCampaignBatch::class, fn (SendCampaignBatch $job): bool => $job->delay !== null);

    runCampaignBatch($campaign);
    runCampaignBatch($campaign);
    expect(OutboundMessage::query()->where('campaign_id', $campaign->id)->count())->toBe(5)
        ->and($campaign->refresh()->status)->toBe(CampaignStatus::Completed)
        ->and($campaign->completed_at)->not->toBeNull();
});

it('A14 never twice: a duplicate or late batch sends nobody again; a finished campaign is not restarted', function (): void {
    $admin = adminWithRole();
    campaignMember();
    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin));

    runCampaignBatch($campaign);
    runCampaignBatch($campaign);
    CampaignRecipient::query()->update(['status' => CampaignRecipientStatus::Pending->value]);   // even a forged PENDING after completion
    runCampaignBatch($campaign);

    expect(OutboundMessage::query()->where('campaign_id', $campaign->id)->count())->toBe(1);
});

it('A14 cancel in flight: members not reached yet are skipped (cancelled), the next batch sends nothing, and it is final', function (): void {
    $admin = adminWithRole();
    foreach (range(1, 3) as $i) {
        campaignMember();
    }
    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin, ['throttlePerMinute' => 1]));
    runCampaignBatch($campaign);

    app(CancelCampaign::class)->handle($admin, $campaign);
    runCampaignBatch($campaign);

    expect(OutboundMessage::query()->where('campaign_id', $campaign->id)->count())->toBe(1)
        ->and(CampaignRecipient::query()->where('skip_reason', CampaignSkipReason::Cancelled->value)->count())->toBe(2)
        ->and($campaign->refresh()->status)->toBe(CampaignStatus::Cancelled)
        ->and($campaign->cancelled_by)->toBe($admin->id)
        ->and(fn () => app(CancelCampaign::class)->handle($admin, $campaign))->toThrow(CommsRefused::class);
});

it('A14 decline: an approver without campaigns.send may cancel a campaign waiting for approval — nothing else', function (): void {
    $role = Spatie\Permission\Models\Role::findOrCreate('approver_only', 'admin');
    $role->syncPermissions(['campaigns.view', 'campaigns.approve']);
    $approver = adminWithRole('approver_only');
    settingIs(SettingKey::CampaignsApprovalThreshold, 0);
    campaignMember();
    $admin = adminWithRole();

    $draft = draftCampaign($admin);
    expect(fn () => app(CancelCampaign::class)->handle($approver, $draft))->toThrow(AuthorizationException::class);

    $waiting = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin));
    expect(app(CancelCampaign::class)->handle($approver, $waiting)->status)->toBe(CampaignStatus::Cancelled);
});

it('A14 stalled send: a SENDING campaign with no batch for 10 minutes is restarted once (not every minute)', function (): void {
    $admin = adminWithRole();
    campaignMember();
    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin));
    Queue::fake([SendCampaignBatch::class]);   // the first batch was "lost"

    $this->travel(StartDueCampaigns::STALL_MINUTES + 1)->minutes();
    $start = fn () => (new StartDueCampaigns)->handle(app(App\Domain\Comms\CampaignStarter::class));
    $start();
    $start();

    Queue::assertPushed(SendCampaignBatch::class, 1);
});

it('A14 test send: email to the signed-in admin only (sample name, untracked); SMS only to COMMS_TEST_PHONES; in-app has none; 10 an hour', function (): void {
    Notification::fake();
    $admin = adminWithRole();
    $email = draftCampaign($admin);

    expect(app(SendTestCampaign::class)->handle($admin, $email))->toBe(1);
    Notification::assertSentOnDemand(CampaignTestMessage::class, function (CampaignTestMessage $n, array $channels, AnonymousNotifiable $to) use ($admin): bool {
        $mail = $n->toMail($to);

        return $to->routes['mail'] === $admin->email && str_contains($mail->subject, 'Onam offer for Anjali')
            && ! str_contains((string) $mail->actionUrl, '/c/');
    });

    $sms = draftCampaign($admin, ['channel' => CommsChannel::Sms, 'dltTemplateId' => '1107000000000000001', 'message' => 'Hi {first_name}', 'subject' => null, 'actionUrl' => null, 'actionText' => null]);
    config(['oppam.comms.test_phones' => []]);
    expect(fn () => app(SendTestCampaign::class)->handle($admin, $sms))->toThrow(CommsRefused::class);

    $gateway = new FakeSmsGateway;
    app()->instance(SmsGateway::class, $gateway);
    config(['oppam.comms.test_phones' => ['+919000011111']]);
    expect(app(SendTestCampaign::class)->handle($admin, $sms))->toBe(1)
        ->and($gateway->notices[0])->toMatchArray(['to' => '+919000011111', 'template_id' => '1107000000000000001', 'variables' => ['Anjali']]);

    expect(app(SendTestCampaign::class)->handle($admin, draftCampaign($admin, ['channel' => CommsChannel::InApp])))->toBe(0);

    foreach (range(3, 10) as $i) {
        app(SendTestCampaign::class)->handle($admin, $email);
    }
    expect(fn () => app(SendTestCampaign::class)->handle($admin, $email))->toThrow(CommsRefused::class);
    expect(CampaignRecipient::query()->count())->toBe(0);   // a test never touches an audience
});

it('A14 never resent: a failed campaign email has no stored copy, so the delivery log refuses to resend it', function (): void {
    $admin = adminWithRole();
    campaignMember();
    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin));
    runCampaignBatch($campaign);

    $row = OutboundMessage::query()->where('campaign_id', $campaign->id)->sole();
    $row->forceFill(['status' => OutboundStatus::Failed])->save();

    expect($row->payload)->toBeNull()
        ->and(fn () => app(ResendFailedMessage::class)->handle($admin, $row))->toThrow(CommsRefused::class);
});

it('A14 purge: a purged member leaves no campaign audience rows', function (): void {
    $admin = adminWithRole();
    $member = campaignMember();
    app(SubmitCampaign::class)->handle($admin, draftCampaign($admin));
    expect(CampaignRecipient::query()->where('user_id', $member->id)->exists())->toBeTrue();

    $member->delete();
    $this->travel(App\Support\Facades\Settings::int(SettingKey::MembersPurgeAfterDays) + 1)->days();
    expect(app(PurgeDeletedMember::class)->handle(App\Models\User::withTrashed()->findOrFail($member->id)))->toBeTrue();

    expect(CampaignRecipient::query()->where('user_id', $member->id)->exists())->toBeFalse();
});

it('A14 review fix (Blocker): a member who closes their account after the audience was frozen is skipped — everyone after them still gets it', function (): void {
    $admin = adminWithRole();
    $closes = campaignMember();
    $after = campaignMember();
    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin));

    $closes->delete();   // CloseAccount / DeleteMember soft-delete the user

    runCampaignBatch($campaign);

    $row = fn ($user) => CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('user_id', $user->id)->sole();
    expect($row($closes)->status)->toBe(CampaignRecipientStatus::Skipped)
        ->and($row($closes)->skip_reason)->toBe(CampaignSkipReason::Inactive)
        ->and($row($after)->status)->toBe(CampaignRecipientStatus::Sent)
        ->and($campaign->refresh()->status)->toBe(CampaignStatus::Completed);
});

it('A14 review fix (Major): a restart replaces the batch chain — the "lost" batch, when it turns up, sends nothing; the throttle holds', function (): void {
    $admin = adminWithRole();
    foreach (range(1, 4) as $i) {
        campaignMember();
    }
    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin, ['throttlePerMinute' => 1]));
    $lostToken = (string) $campaign->refresh()->batch_token;

    $this->travel(StartDueCampaigns::STALL_MINUTES + 1)->minutes();
    (new StartDueCampaigns)->handle(app(App\Domain\Comms\CampaignStarter::class));
    expect($campaign->refresh()->batch_token)->not->toBe($lostToken);

    (new SendCampaignBatch($campaign->id, $lostToken))->handle(app(NotificationRouter::class));
    expect(OutboundMessage::query()->where('campaign_id', $campaign->id)->count())->toBe(0);

    runCampaignBatch($campaign);   // the one live chain: one member per batch
    expect(OutboundMessage::query()->where('campaign_id', $campaign->id)->count())->toBe(1);
});

it('A14 review fix (Major): an SMS batch held through quiet hours is due at the window\'s end — not "stalled" at 07:00, so no second chain', function (): void {
    app()->instance(SmsGateway::class, $sms = new FakeSmsGateway);
    $admin = adminWithRole();
    optInToOfferSms(campaignMember());
    optInToOfferSms(campaignMember());

    $this->travelTo(CarbonImmutable::parse('2026-11-09 22:30', 'Asia/Kolkata'));
    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin, [
        'channel' => CommsChannel::Sms, 'subject' => null, 'actionUrl' => null, 'actionText' => null,
        'dltTemplateId' => '1107000000000000099', 'message' => 'Offer -OPPAM', 'throttlePerMinute' => 1,
    ]));
    runCampaignBatch($campaign);   // held: the next batch is due 07:00 IST
    $heldToken = (string) $campaign->refresh()->batch_token;
    expect($campaign->next_batch_at->setTimezone('Asia/Kolkata')->format('H:i'))->toBe('07:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-10 07:01', 'Asia/Kolkata'));
    (new StartDueCampaigns)->handle(app(App\Domain\Comms\CampaignStarter::class));
    expect($campaign->refresh()->batch_token)->toBe($heldToken);   // no restart, one chain

    runCampaignBatch($campaign);
    expect($sms->notices)->toHaveCount(1);
});

it('A14 review fix (Major, round 2): an SMS batch that runs into quiet hours stops texting there and resumes at the window\'s end (M08)', function (): void {
    app()->instance(SmsGateway::class, $sms = new FakeSmsGateway);
    $admin = adminWithRole();
    foreach (range(1, 3) as $_) {
        optInToOfferSms(campaignMember());
    }

    $this->travelTo(CarbonImmutable::parse('2026-11-09 21:59', 'Asia/Kolkata'));
    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin, [
        'channel' => CommsChannel::Sms, 'subject' => null, 'actionUrl' => null, 'actionText' => null,
        'dltTemplateId' => '1107000000000000099', 'message' => 'Offer -OPPAM', 'throttlePerMinute' => 10,
    ]));
    // The first text goes at 21:59; the provider is slow, so the clock passes 22:00 during the batch.
    $sms->afterNotice = fn () => $this->travelTo(CarbonImmutable::parse('2026-11-09 22:00:30', 'Asia/Kolkata'));

    runCampaignBatch($campaign);

    expect($sms->notices)->toHaveCount(1)
        ->and(CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', CampaignRecipientStatus::Pending->value)->count())->toBe(2)
        ->and($campaign->refresh()->status)->toBe(CampaignStatus::Sending)
        ->and($campaign->next_batch_at->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'))->toBe('2026-11-10 07:00');

    $sms->afterNotice = null;
    $this->travelTo(CarbonImmutable::parse('2026-11-10 07:01', 'Asia/Kolkata'));
    runCampaignBatch($campaign);

    expect($sms->notices)->toHaveCount(3)
        ->and($campaign->refresh()->status)->toBe(CampaignStatus::Completed);
});

it('A14 review fix (Minor): a member whose email was removed after submit is skipped as unreachable, not as opted out', function (): void {
    $admin = adminWithRole();
    $loses = campaignMember();
    $keeps = campaignMember();
    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin));

    $loses->forceFill(['email' => null, 'email_verified_at' => null])->save();
    runCampaignBatch($campaign);

    $row = fn ($user) => CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('user_id', $user->id)->sole();
    expect($row($loses)->status)->toBe(CampaignRecipientStatus::Skipped)
        ->and($row($loses)->skip_reason)->toBe(CampaignSkipReason::Unreachable)
        ->and($row($keeps)->status)->toBe(CampaignRecipientStatus::Sent);
});

it('A14 review fix (Minor): a long batch keeps its chain "due now" at every re-check, so StartDueCampaigns never takes it for stalled', function (): void {
    $admin = adminWithRole();
    foreach (range(1, 51) as $_) {
        campaignMember();
    }
    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin, [
        'channel' => CommsChannel::InApp, 'throttlePerMinute' => 100,
    ]));
    $campaign->refresh()->forceFill(['next_batch_at' => now()->subHour()])->save();

    $seen = [];
    Illuminate\Support\Facades\Event::listen(Illuminate\Notifications\Events\NotificationSent::class, function () use ($campaign, &$seen): void {
        $seen[] = Campaign::query()->findOrFail($campaign->id)->next_batch_at;
    });
    runCampaignBatch($campaign);

    expect($seen)->toHaveCount(51)
        ->and($seen[49]->lessThan(now()->subMinutes(StartDueCampaigns::STALL_MINUTES)))->toBeTrue()   // before the re-check
        ->and($seen[50]->greaterThanOrEqualTo(now()->subMinute()))->toBeTrue();                          // refreshed at member 51
});

it('A14 review fix (Minor): a failed member counts once in the stats (thrown email + logged row; logged-and-swallowed SMS)', function (): void {
    $campaign = Campaign::factory()->status(CampaignStatus::Completed)->create();
    $emailFail = campaignMember();
    $smsFail = campaignMember();
    CampaignRecipient::factory()->create(['campaign_id' => $campaign->id, 'user_id' => $emailFail->id, 'status' => CampaignRecipientStatus::Failed]);
    CampaignRecipient::factory()->create(['campaign_id' => $campaign->id, 'user_id' => $smsFail->id, 'status' => CampaignRecipientStatus::Sent]);
    OutboundMessage::factory()->create(['campaign_id' => $campaign->id, 'user_id' => $emailFail->id, 'status' => OutboundStatus::Failed]);
    OutboundMessage::factory()->create(['campaign_id' => $campaign->id, 'user_id' => $smsFail->id, 'status' => OutboundStatus::Failed]);

    expect(app(App\Queries\Admin\Comms\CampaignStats::class)->one($campaign)->failed)->toBe(2);
});

it('A14 review fix (Minor): an admin who edited someone else\'s draft can\'t approve it either', function (): void {
    settingIs(SettingKey::CampaignsApprovalThreshold, 0);
    campaignMember();
    $creator = adminWithRole();
    $editor = adminWithRole();
    $campaign = draftCampaign($creator);
    app(SaveCampaign::class)->handle($editor, campaignData(['name' => 'Edited']), $campaign);
    $campaign = app(SubmitCampaign::class)->handle($creator, $campaign);

    expect(fn () => app(ApproveCampaign::class)->handle($editor, $campaign))->toThrow(CommsRefused::class)
        ->and(app(ApproveCampaign::class)->handle(adminWithRole(), $campaign)->status)->toBe(CampaignStatus::Sending);
});

it('A14 review fix (Minor): an approver-only admin can\'t cancel a campaign approved in the meantime', function (): void {
    $role = Spatie\Permission\Models\Role::findOrCreate('approver_only', 'admin');
    $role->syncPermissions(['campaigns.view', 'campaigns.approve']);
    $approverOnly = adminWithRole('approver_only');
    settingIs(SettingKey::CampaignsApprovalThreshold, 0);
    campaignMember();
    $admin = adminWithRole();
    $waiting = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin));
    $stale = Campaign::query()->findOrFail($waiting->id);   // what the approver-only screen had loaded

    app(ApproveCampaign::class)->handle(adminWithRole(), $waiting);

    expect(fn () => app(CancelCampaign::class)->handle($approverOnly, $stale))->toThrow(AuthorizationException::class)
        ->and($waiting->refresh()->status)->toBe(CampaignStatus::Sending);
});
