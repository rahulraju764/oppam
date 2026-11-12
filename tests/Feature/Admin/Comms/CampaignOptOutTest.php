<?php

declare(strict_types=1);

use App\Actions\Admin\Comms\SubmitCampaign;
use App\Contracts\SmsGateway;
use App\Data\Comms\CampaignSegment;
use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\CommsChannel;
use App\Enums\Gender;
use App\Enums\UserStatus;
use App\Jobs\Comms\SendCampaignBatch;
use App\Models\CampaignRecipient;
use App\Models\OutboundMessage;
use App\Queries\Admin\Comms\CampaignStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSmsGateway;

/*
| P8.3b1 Done when: "a campaign to a test segment respects opt-outs". A campaign is an
| `admin_announcement` ("Offers and announcements") notification on ONE channel: a member who
| switched that channel off is never in the frozen audience, and one who switches it off after the
| campaign was sent is skipped when their turn comes (NotificationRouter at send).
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    Queue::fake([SendCampaignBatch::class]);
});

it('A14 CampaignOptOutTest: an email campaign to a test segment reaches only members who can and want to receive it', function (): void {
    $admin = adminWithRole();
    $wants = campaignMember();
    $optedOut = campaignMember();
    optOutOfOffers($optedOut, 'email');
    $optedOutOfSmsOnly = campaignMember();
    optOutOfOffers($optedOutOfSmsOnly, 'sms');          // another channel: still gets the email
    $unverifiedEmail = campaignMember(['email_verified_at' => null]);
    $outsideSegment = campaignMember(female: false);
    $suspended = campaignMember(['status' => UserStatus::Suspended]);
    $draftProfile = memberWithPhone('+919800000077', App\Enums\ProfileStatus::Draft);

    $campaign = draftCampaign($admin, ['segment' => new CampaignSegment(gender: Gender::Female)]);
    $campaign = app(SubmitCampaign::class)->handle($admin, $campaign);

    expect($campaign->status)->toBe(CampaignStatus::Sending)
        ->and($campaign->recipient_count)->toBe(2)
        ->and(CampaignRecipient::query()->where('campaign_id', $campaign->id)->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$wants->id, $optedOutOfSmsOnly->id])->sort()->values()->all());
    Queue::assertPushed(SendCampaignBatch::class, fn (SendCampaignBatch $job): bool => $job->campaignId === $campaign->id);

    runCampaignBatch($campaign);

    $logged = OutboundMessage::query()->where('campaign_id', $campaign->id)->get();
    expect($logged)->toHaveCount(2)
        ->and($logged->pluck('user_id')->sort()->values()->all())->toBe(collect([$wants->id, $optedOutOfSmsOnly->id])->sort()->values()->all())
        ->and($logged->every(fn (OutboundMessage $m): bool => $m->event === 'admin_announcement' && $m->payload === null))->toBeTrue()
        ->and($campaign->refresh()->status)->toBe(CampaignStatus::Completed);

    foreach ([$optedOut, $unverifiedEmail, $outsideSegment, $suspended, $draftProfile] as $excluded) {
        expect(OutboundMessage::query()->where('user_id', $excluded->id)->exists())->toBeFalse();
    }
});

it('A14 CampaignOptOutTest: an opt-out made AFTER the audience was frozen still wins at send (skipped, not sent)', function (): void {
    $admin = adminWithRole();
    $stays = campaignMember();
    $changesMind = campaignMember();
    $closes = campaignMember();

    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin));
    expect($campaign->recipient_count)->toBe(3);

    optOutOfOffers($changesMind, 'email');
    $closes->forceFill(['status' => UserStatus::Deleted])->save();

    runCampaignBatch($campaign);

    $status = fn ($user) => CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('user_id', $user->id)->sole();
    expect($status($stays)->status)->toBe(CampaignRecipientStatus::Sent)
        ->and($status($changesMind)->status)->toBe(CampaignRecipientStatus::Skipped)
        ->and($status($changesMind)->skip_reason)->toBe(CampaignSkipReason::OptedOut)
        ->and($status($closes)->skip_reason)->toBe(CampaignSkipReason::Inactive)
        ->and(OutboundMessage::query()->where('campaign_id', $campaign->id)->pluck('user_id')->all())->toBe([$stays->id]);

    $stats = app(CampaignStats::class)->one($campaign);
    expect($stats->audience)->toBe(3)->and($stats->sent)->toBe(1)->and($stats->skipped)->toBe(2)->and($stats->pending)->toBe(0);
});

it('A14 CampaignOptOutTest: an in-app campaign writes bell rows only for members who keep in-app offers on', function (): void {
    $admin = adminWithRole();
    $wants = campaignMember();
    $optedOut = campaignMember();
    optOutOfOffers($optedOut, 'in_app');
    $noEmail = campaignMember(['email' => null, 'email_verified_at' => null]);   // in-app needs no email

    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin, [
        'channel' => CommsChannel::InApp, 'subject' => 'Hi {first_name}', 'message' => '20 % off Gold.',
    ]));
    runCampaignBatch($campaign);

    $rows = Illuminate\Notifications\DatabaseNotification::query()->where('type', 'admin_announcement')->get();
    expect($rows->pluck('notifiable_id')->sort()->values()->all())->toBe(collect([$wants->id, $noEmail->id])->sort()->values()->all())
        ->and($rows->first()->data['title'])->toStartWith('Hi ')
        ->and($rows->first()->data['url'])->toContain('/c/'.$campaign->code.'/click/')
        ->and(app(CampaignStats::class)->one($campaign)->delivered)->toBe(2);
});

it('A14 CampaignOptOutTest: SMS offers are opt-in — only members who switched them on (with a verified phone) are texted, never in quiet hours', function (): void {
    $sms = new FakeSmsGateway;
    app()->instance(SmsGateway::class, $sms);
    $admin = adminWithRole();
    $wants = campaignMember();
    optInToOfferSms($wants);
    $switchedOff = campaignMember();
    optOutOfOffers($switchedOff, 'sms');
    campaignMember();                                       // never chose: SMS offers stay off
    $unverified = campaignMember(['phone_verified_at' => null]);
    optInToOfferSms($unverified);

    $this->travelTo(CarbonImmutable::parse('2026-11-09 23:30', 'Asia/Kolkata'));
    $campaign = app(SubmitCampaign::class)->handle($admin, draftCampaign($admin, [
        'channel' => CommsChannel::Sms, 'subject' => null, 'actionUrl' => null, 'actionText' => null,
        'dltTemplateId' => '1107000000000000099', 'message' => 'Oppam: {first_name}, 20% off Gold this week. -OPPAM',
    ]));
    expect($campaign->recipient_count)->toBe(1);

    runCampaignBatch($campaign);   // 23:30 IST: inside quiet hours
    expect($sms->notices)->toBe([])
        ->and(CampaignRecipient::query()->where('campaign_id', $campaign->id)->sole()->status)->toBe(CampaignRecipientStatus::Pending);
    Queue::assertPushed(SendCampaignBatch::class, fn (SendCampaignBatch $job): bool => $job->delay instanceof DateTimeInterface
        && CarbonImmutable::instance($job->delay)->setTimezone('Asia/Kolkata')->format('Y-m-d H:i') === '2026-11-10 07:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-10 07:05', 'Asia/Kolkata'));
    runCampaignBatch($campaign);

    expect($sms->notices)->toHaveCount(1)
        ->and($sms->notices[0]['to'])->toBe($wants->phone)
        ->and($sms->notices[0]['template_id'])->toBe('1107000000000000099')
        ->and($sms->notices[0]['variables'])->toBe([$wants->profile->first_name])
        ->and(OutboundMessage::query()->where('campaign_id', $campaign->id)->sole()->provider_id)->toBe('fake-1');
});
