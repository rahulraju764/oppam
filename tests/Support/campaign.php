<?php

declare(strict_types=1);

use App\Data\Comms\CampaignData;
use App\Data\Comms\CampaignSegment;
use App\Enums\CommsChannel;
use App\Enums\NotificationEvent;
use App\Jobs\Comms\SendCampaignBatch;
use App\Models\AdminUser;
use App\Models\Campaign;
use App\Models\NotificationPreference;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Support\NotificationRouter;

/*
| A14 campaigns (P8.3b1) test helpers.
*/

/** An ACTIVE member with a verified email and phone (unless told otherwise). */
function campaignMember(array $user = [], array $profile = [], bool $female = true): User
{
    $member = User::factory()->create(['email_verified_at' => now(), ...$user]);
    $factory = Profile::factory()->for($member)->active();
    $factory = $female ? $factory->female() : $factory->male();
    $factory->create($profile);

    return $member->refresh();
}

/** The member switched "Offers and announcements" off on one channel ('email' | 'sms' | 'in_app'). */
function optOutOfOffers(User $member, string $channel): void
{
    $event = NotificationEvent::AdminAnnouncement;
    (new NotificationPreference)->forceFill([
        'user_id' => $member->id, 'event' => $event->value,
        'in_app' => true, 'email' => true, 'sms' => false, 'push' => false,
        $channel => false,
    ])->save();
}

/** SMS offers are opt-in (NotificationEvent::smsIsOptIn): the member switched them on in /settings. */
function optInToOfferSms(User $member): void
{
    (new NotificationPreference)->forceFill([
        'user_id' => $member->id, 'event' => NotificationEvent::AdminAnnouncement->value,
        'in_app' => true, 'email' => true, 'sms' => true, 'push' => false,
    ])->save();
}

function campaignData(array $overrides = []): CampaignData
{
    return new CampaignData(...[
        'name' => 'Onam offer',
        'channel' => CommsChannel::Email,
        'segment' => new CampaignSegment,
        'subject' => 'Onam offer for {first_name}',
        'message' => "Hello {first_name},\n\n20 % off **Gold** this week.",
        'actionUrl' => '/plans',
        'actionText' => 'See plans',
        'dltTemplateId' => null,
        'scheduledAt' => null,
        'throttlePerMinute' => 300,
        ...$overrides,
    ]);
}

/** A draft saved through the Action (so it is valid). */
function draftCampaign(?AdminUser $admin = null, array $overrides = []): Campaign
{
    return app(App\Actions\Admin\Comms\SaveCampaign::class)->handle($admin ?? adminWithRole(), campaignData($overrides));
}

/** Runs the campaign's current batch now (the real job body, holding the chain's token; follow-up batches go to the faked queue). */
function runCampaignBatch(Campaign $campaign): Campaign
{
    $campaign->refresh();
    (new SendCampaignBatch($campaign->id, (string) $campaign->batch_token))->handle(app(NotificationRouter::class));

    return $campaign->refresh();
}
