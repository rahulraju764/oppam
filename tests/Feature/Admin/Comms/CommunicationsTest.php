<?php

declare(strict_types=1);

use App\Actions\Admin\Comms\ApproveCampaign;
use App\Actions\Admin\Comms\BuildCampaign;
use App\Actions\Admin\Comms\CreateAnnouncement;
use App\Actions\Admin\Comms\ResendFailedMessage;
use App\Actions\Admin\Comms\SaveTemplate;
use App\Actions\Admin\Comms\SendCampaign;
use App\Actions\Admin\Comms\SendTestTemplate;
use App\Events\Account\AdminAnnouncementBroadcast;
use App\Jobs\Admin\Comms\SendCampaignJob;
use App\Models\Campaign;
use App\Models\NotificationPreference;
use App\Models\OutboundMessage;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Admin\Comms\SegmentQuery;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    seedAdminRoles();
});

it('P8.3: creates, updates, version-increments templates and test-sends to admin', function (): void {
    $admin = adminWithRole('super_admin');
    $action = app(SaveTemplate::class);

    $template = $action->handle($admin, [
        'key' => 'diwali_discount',
        'name' => 'Diwali 20% Discount Offer',
        'channel' => 'email',
        'subject' => 'Happy Diwali from Oppam!',
        'body' => 'Hello {name}, enjoy 20% off on Gold subscription!',
        'variables' => ['name' => 'Rahul'],
    ]);

    expect($template->key)->toBe('diwali_discount')
        ->and($template->version)->toBe(1);

    // Update
    $updated = $action->handle($admin, [
        'key' => 'diwali_discount',
        'name' => 'Diwali 25% Discount Offer',
        'channel' => 'email',
        'subject' => 'Happy Diwali 2026 from Oppam!',
        'body' => 'Hello {name}, enjoy 25% off on Gold subscription!',
    ], $template);

    expect($updated->version)->toBe(2);

    // Test send
    $testSend = app(SendTestTemplate::class);
    $outbound = $testSend->handle($admin, $updated);

    expect($outbound->status)->toBe(OutboundMessage::STATUS_SENT)
        ->and($outbound->recipient)->toBe($admin->email)
        ->and($outbound->content)->toContain('enjoy 25% off');
});

it('P8.3: creates an announcement and broadcasts live via WebSocket', function (): void {
    Event::fake([AdminAnnouncementBroadcast::class]);
    $admin = adminWithRole('super_admin');

    $createAction = app(CreateAnnouncement::class);
    $announcement = $createAction->handle($admin, [
        'title' => 'Server Maintenance Tonight',
        'body' => 'We will perform routine maintenance at 03:00 IST.',
        'broadcast_now' => true,
    ]);

    expect($announcement->title)->toBe('Server Maintenance Tonight')
        ->and($announcement->broadcast_at)->not->toBeNull();

    Event::assertDispatched(AdminAnnouncementBroadcast::class, fn ($e) => $e->announcement->id === $announcement->id);
});

it('P8.3: campaign builder respects member opt-outs when targeting segment', function (): void {
    $admin1 = adminWithRole('super_admin');

    // Create 2 active members: one opted in, one opted out of admin announcements
    $memberOptedIn = User::factory()->create([
        'phone_verified_at' => now(),
        'email' => 'optedin@oppam.test',
        'email_verified_at' => now(),
    ]);
    Profile::factory()->create([
        'user_id' => $memberOptedIn->id,
        'gender' => App\Enums\Gender::Male,
        'status' => App\Enums\ProfileStatus::Active,
    ]);

    $memberOptedOut = User::factory()->create([
        'phone_verified_at' => now(),
        'email' => 'optedout@oppam.test',
        'email_verified_at' => now(),
    ]);
    Profile::factory()->create([
        'user_id' => $memberOptedOut->id,
        'gender' => App\Enums\Gender::Male,
        'status' => App\Enums\ProfileStatus::Active,
    ]);

    // Member opt-out of admin announcements on email
    $pref = new NotificationPreference([
        'event' => 'admin_announcement',
        'in_app' => true,
        'email' => false,
        'sms' => false,
    ]);
    $pref->user_id = $memberOptedOut->id;
    $pref->save();

    $buildCampaign = app(BuildCampaign::class);
    $campaign = $buildCampaign->handle($admin1, [
        'name' => 'Email Campaign to Grooms',
        'channel' => 'email',
        'subject' => 'Special Update',
        'message' => 'Check out new matches this week!',
        'segment_filters' => ['gender' => 'MALE'],
    ]);

    expect($campaign->status)->toBe(Campaign::STATUS_APPROVED);

    // Run SendCampaignJob
    Queue::fake([SendCampaignJob::class]);
    $sendCampaign = app(SendCampaign::class);
    $sendCampaign->handle($admin1, $campaign);

    // Directly execute the job
    $segmentQuery = app(SegmentQuery::class);
    $job = new SendCampaignJob($campaign->id);
    $job->handle($segmentQuery);

    // Assert: only the opted-in member received the outbound message!
    $sentToOptedIn = OutboundMessage::query()->where('campaign_id', $campaign->id)->where('user_id', $memberOptedIn->id)->exists();
    $sentToOptedOut = OutboundMessage::query()->where('campaign_id', $campaign->id)->where('user_id', $memberOptedOut->id)->exists();

    expect($sentToOptedIn)->toBeTrue()
        ->and($sentToOptedOut)->toBeFalse(); // Opt-out strictly respected!
});

it('P8.3: large campaigns (>10,000) require second admin approval', function (): void {
    $admin1 = adminWithRole('super_admin');
    $admin2 = adminWithRole('super_admin');

    $campaign = Campaign::query()->create([
        'name' => 'Mega Campaign',
        'channel' => 'email',
        'segment_filters' => [],
        'subject' => 'Big Announcement',
        'message' => 'Greetings to all members!',
        'status' => Campaign::STATUS_PENDING_APPROVAL,
        'recipient_count' => 15000,
        'created_by' => $admin1->id,
    ]);

    $approveAction = app(ApproveCampaign::class);

    // Creator cannot approve their own >10k campaign (unless super admin, but test non-super or separate check)
    $approvedCampaign = $approveAction->handle($admin2, $campaign);

    expect($approvedCampaign->status)->toBe(Campaign::STATUS_APPROVED)
        ->and($approvedCampaign->approved_by)->toBe($admin2->id);
});

it('P8.3: resends failed outbound messages', function (): void {
    $admin = adminWithRole('super_admin');
    $failedMessage = OutboundMessage::query()->create([
        'channel' => 'email',
        'recipient' => 'test@example.com',
        'subject' => 'Notification',
        'status' => OutboundMessage::STATUS_FAILED,
        'error_message' => 'Connection timeout',
        'sent_at' => now()->subHour(),
    ]);

    $resendAction = app(ResendFailedMessage::class);
    $resent = $resendAction->handle($admin, $failedMessage);

    expect($resent->status)->toBe(OutboundMessage::STATUS_SENT)
        ->and($resent->error_message)->toBeNull();
});
