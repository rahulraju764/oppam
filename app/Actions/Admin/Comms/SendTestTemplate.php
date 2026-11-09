<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Contracts\SmsGateway;
use App\Domain\Comms\TemplateCopy;
use App\Enums\CommsChannel;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\CommunicationTemplate;
use App\Models\CommunicationTemplateVersion;
use App\Notifications\Admin\TemplateTestMessage;
use App\Services\Audit\AuditLogger;
use App\Services\Comms\DeliveryRecorder;
use App\ValueObjects\PhoneNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * A14 "test-send to self" (P8.3a, `templates.edit`), with sample values:
 *  - email → really mailed to the signed-in admin's own address (logged like any mail);
 *  - SMS → only to the numbers in `oppam.comms.test_phones` (never a number typed here — a test
 *    must not text a stranger); none set → refused, the editor shows the preview;
 *  - in-app → nothing to send; the editor's preview is the test.
 * 10 per hour per admin. Audited with the channel and version, never the text. Returns how many
 * messages went out.
 */
final class SendTestTemplate
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
    public function handle(AdminUser $admin, CommunicationTemplate $template): int
    {
        Gate::forUser($admin)->authorize('templates.edit');

        $version = CommunicationTemplateVersion::query()
            ->where('template_id', $template->id)->where('version', $template->current_version)->first()
            ?? throw CommsRefused::noVersion();

        if ($template->channel === CommsChannel::InApp) {
            return 0;
        }

        $phones = $template->channel === CommsChannel::Sms ? $this->testPhones() : [];
        if ($template->channel === CommsChannel::Sms && $phones === []) {
            throw CommsRefused::noTestPhone();
        }

        $key = 'comms-test:'.$admin->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('oppam.comms.test_sends_per_hour'))) {
            throw CommsRefused::tooManyTests();
        }
        RateLimiter::hit($key, 3600);

        $sent = 0;
        if ($template->channel === CommsChannel::Email) {
            Notification::route('mail', $admin->email)->notifyNow(new TemplateTestMessage(
                TemplateCopy::sample((string) $version->subject),
                TemplateCopy::sample($version->body),
                $version->version,
            ));
            $sent = 1;
        } else {
            foreach ($phones as $phone) {
                try {
                    $requestId = $this->sms->sendNotification($phone, $template->event, ['Anjali', 'OPM12345'], $version->dlt_template_id);
                    $this->deliveries->directSms(null, $phone, 'template_test', $requestId);
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                    $this->deliveries->directSms(null, $phone, 'template_test', null, $e);
                }
            }
        }

        $this->audit->record('comms.template_test_sent', $template,
            after: ['channel' => $template->channel->value, 'version' => $version->version, 'sent' => $sent], actor: $admin, subjectLabel: $template->key);

        return $sent;
    }

    /** @return list<PhoneNumber> */
    private function testPhones(): array
    {
        return array_values(array_filter(array_map(
            fn (string $number): ?PhoneNumber => PhoneNumber::tryParse($number),
            (array) config('oppam.comms.test_phones', []),
        )));
    }
}
