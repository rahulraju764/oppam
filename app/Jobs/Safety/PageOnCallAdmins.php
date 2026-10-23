<?php

declare(strict_types=1);

namespace App\Jobs\Safety;

use App\Contracts\SmsGateway;
use App\Enums\AdminStatus;
use App\Enums\SettingKey;
use App\Exceptions\Sms\SmsDeliveryFailed;
use App\Models\AdminUser;
use App\Notifications\Admin\UrgentCasePaged;
use App\Support\Facades\Settings;
use App\ValueObjects\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Page on-call admins about a P0 case (PRD A10, decisions 2026-10-23): email to every ACTIVE admin
 * holding `safety.reports.act`, SMS (DLT template `safety_p0_case`, variable: the case code) to
 * each number in `safety.oncall_phones`. One failed SMS never stops the others; nothing about the
 * member is sent.
 */
final class PageOnCallAdmins implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $caseCode)
    {
        $this->onQueue('notifications');
    }

    public function handle(SmsGateway $sms): void
    {
        AdminUser::query()->where('status', AdminStatus::Active->value)->get()
            ->filter(fn (AdminUser $admin): bool => $admin->can('safety.reports.act'))
            ->each(fn (AdminUser $admin) => $admin->notify(new UrgentCasePaged($this->caseCode)));

        foreach ($this->oncallPhones() as $phone) {
            try {
                $sms->sendNotification($phone, 'safety_p0_case', [$this->caseCode]);
            } catch (SmsDeliveryFailed $e) {
                Log::warning('P0 page SMS failed', ['phone' => $phone->masked(), 'reason' => $e->reason]);
            }
        }
    }

    /** @return list<PhoneNumber> */
    private function oncallPhones(): array
    {
        $phones = [];
        foreach (explode(',', Settings::string(SettingKey::SafetyOncallPhones)) as $raw) {
            $phone = trim($raw) === '' ? null : PhoneNumber::tryParse(trim($raw));
            if ($phone !== null) {
                $phones[] = $phone;
            }
        }

        return $phones;
    }
}
