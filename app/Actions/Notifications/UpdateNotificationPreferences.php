<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Data\Notifications\NotificationChoiceData;
use App\Data\Notifications\NotificationSettingsData;
use App\Enums\NotificationEvent;
use App\Enums\SettingKey;
use App\Enums\UserRole;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The /settings Notifications tab (PRD M14 / M08): the member's events × channels matrix (SMS quiet
 * hours are one window for everyone — A15, owner 2026-11-06 / 11-07). The server decides what can change, whatever the browser sends:
 *  - only member events (NotificationEvent::forMemberSettings); bureau events are never written;
 *  - mandatory events (R-M08-3) are skipped — their channels are fixed;
 *  - a channel the event doesn't use (defaultEmail / defaultSms false) is stored off — a member
 *    can switch channels off, never add one (decisions 2026-11-07); push stays off (v1.5);
 *  - rule 10: at most `members.settings_changes_per_hour` saves that switch something ON — a save
 *    that only switches channels off (an opt-out) is never refused; never in a support session.
 */
final class UpdateNotificationPreferences
{
    public function __construct(private readonly Impersonation $impersonation) {}

    /**
     * @throws ImpersonationRestricted
     * @throws SettingsRefused
     * @throws NotFoundHttpException
     */
    public function handle(User $member, NotificationSettingsData $wanted): void
    {
        $this->impersonation->assertAllowed();

        if (! $member->isActive() || $member->role !== UserRole::Member) {
            throw new NotFoundHttpException;
        }

        $editable = NotificationEvent::forMemberSettings();

        if ($this->switchesSomethingOn($member, $wanted, $editable)) {
            $key = 'notification-settings:'.$member->id;
            if (RateLimiter::tooManyAttempts($key, Settings::int(SettingKey::MembersSettingsChangesPerHour))) {
                throw SettingsRefused::tooManySaves();
            }
            RateLimiter::hit($key, 3600);
        }

        DB::transaction(function () use ($member, $wanted, $editable): void {
            $seen = [];
            foreach ($wanted->choices as $choice) {
                if (isset($seen[$choice->event->value]) || $choice->event->isMandatory() || ! in_array($choice->event, $editable, true)) {
                    continue;
                }
                $seen[$choice->event->value] = true;

                $this->store($member, $choice);
            }
        });
    }

    /**
     * Would this save turn on a channel that is off now (stored row, else the event's default)?
     *
     * @param  list<NotificationEvent>  $editable
     */
    private function switchesSomethingOn(User $member, NotificationSettingsData $wanted, array $editable): bool
    {
        $rows = NotificationPreference::query()->where('user_id', $member->id)->get()->keyBy('event');

        foreach ($wanted->choices as $choice) {
            $event = $choice->event;
            if ($event->isMandatory() || ! in_array($event, $editable, true)) {
                continue;
            }
            $row = $rows->get($event->value);
            foreach (['in_app' => $choice->inApp, 'email' => $choice->email, 'sms' => $choice->sms] as $channel => $on) {
                $now = $event->usesChannel($channel) && ($row !== null ? (bool) $row->{$channel} : true);
                if ($on && $event->usesChannel($channel) && ! $now) {
                    return true;
                }
            }
        }

        return false;
    }

    private function store(User $member, NotificationChoiceData $choice): void
    {
        $event = $choice->event;

        // user_id is never mass-assignable: set by the Action only.
        $row = NotificationPreference::query()->where('user_id', $member->id)->where('event', $event->value)->first()
            ?? (new NotificationPreference)->forceFill(['user_id' => $member->id, 'event' => $event->value]);

        $row->forceFill([
            'in_app' => $choice->inApp,
            'email' => $choice->email && $event->usesChannel('email'),
            'sms' => $choice->sms && $event->usesChannel('sms'),
            'push' => false,
        ])->save();
    }
}
