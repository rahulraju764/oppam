<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Contracts\SmsGateway;
use App\Enums\CommsChannel;
use App\Enums\NotificationEvent;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\Campaign;
use App\Notifications\Admin\CampaignTestMessage;
use App\Services\Audit\AuditLogger;
use App\Services\Comms\DeliveryRecorder;
use App\ValueObjects\PhoneNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * A14 campaign "test send" (P8.3b1, `campaigns.send`) — the same rules as a template test
 * (SendTestTemplate, P8.3a), sharing its 10-an-hour limit per admin:
 *  - email → the signed-in admin's own address, sample name, untracked button;
 *  - SMS → only the `oppam.comms.test_phones` numbers with the campaign's DLT template (none set →
 *    refused); never a number typed on the screen;
 *  - in-app → nothing to send; the builder's preview is the test.
 * Any status: a test never touches the audience or the numbers. Audited (channel, count).
 */
final class SendTestCampaign
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SmsGateway $sms,
        private readonly DeliveryRecorder $deliveries,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws CommsRefused
     */
    public function handle(AdminUser $admin, Campaign $campaign): int
    {
        Gate::forUser($admin)->authorize('campaigns.send');

        if ($campaign->channel === CommsChannel::InApp) {
            return 0;
        }

        $phones = $campaign->channel === CommsChannel::Sms ? $this->testPhones() : [];
        if ($campaign->channel === CommsChannel::Sms && $phones === []) {
            throw CommsRefused::noTestPhone();
        }

        $key = 'comms-test:'.$admin->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('oppam.comms.test_sends_per_hour'))) {
            throw CommsRefused::tooManyTests();
        }
        RateLimiter::hit($key, 3600);

        $sent = 0;
        if ($campaign->channel === CommsChannel::Email) {
            Notification::route('mail', $admin->email)->notifyNow(new CampaignTestMessage($campaign));
            $sent = 1;
        } else {
            $variables = str_contains($campaign->message, '{first_name}') ? ['Anjali'] : [];
            foreach ($phones as $phone) {
                try {
                    $requestId = $this->sms->sendNotification($phone, NotificationEvent::AdminAnnouncement->value, $variables, $campaign->dlt_template_id);
                    $this->deliveries->directSms(null, $phone, 'campaign_test', $requestId);
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                    $this->deliveries->directSms(null, $phone, 'campaign_test', null, $e);
                }
            }
        }

        $this->audit->record('comms.campaign_test_sent', $campaign,
            after: ['channel' => $campaign->channel->value, 'sent' => $sent], actor: $admin, subjectLabel: $campaign->code);

        return $sent;
    }

    /** @return list<PhoneNumber> */
    private function testPhones(): array
    {
        /** @var list<string> $numbers */
        $numbers = (array) config('oppam.comms.test_phones');

        return array_values(array_filter(array_map(fn (string $n): ?PhoneNumber => PhoneNumber::tryParse($n), $numbers)));
    }
}
