<?php

declare(strict_types=1);

namespace App\Livewire\Member\Settings;

use App\Actions\Notifications\UpdateNotificationPreferences;
use App\Data\Notifications\NotificationChoiceData;
use App\Data\Notifications\NotificationSettingsData;
use App\Enums\NotificationEvent;
use App\Enums\SettingKey;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Facades\Settings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /settings → Notifications (PRD M14 / M08): events × channels. SMS quiet hours are one window for
 * everyone (A15 settings, owner 2026-11-06 / 11-07), shown here as information. Mandatory events (R-M08-3) and channels an event doesn't use are shown fixed; push arrives in v1.5. The
 * Action re-checks all of it — whatever this form posts, it only stores what may change.
 */
#[Lazy]
final class NotificationSettings extends Component
{
    /** @var array<string, array<string, mixed>> event value => channel => on; posted by the browser, so read defensively */
    public array $matrix = [];

    public function mount(): void
    {
        $this->load();
    }

    public function placeholder(): View
    {
        return view('livewire.member.settings.placeholder');
    }

    public function save(UpdateNotificationPreferences $update): void
    {
        $this->validate([
            'matrix' => ['array'],
            'matrix.*' => ['array'],
            'matrix.*.in_app' => ['boolean'],
            'matrix.*.email' => ['boolean'],
            'matrix.*.sms' => ['boolean'],
        ]);

        $choices = [];
        foreach ($this->matrix as $event => $channels) {
            $case = NotificationEvent::tryFrom((string) $event);
            if ($case !== null) {
                $choices[] = new NotificationChoiceData($case, (bool) ($channels['in_app'] ?? true), (bool) ($channels['email'] ?? false), (bool) ($channels['sms'] ?? false));
            }
        }

        try {
            $update->handle($this->member(), new NotificationSettingsData($choices));
        } catch (SettingsRefused|ImpersonationRestricted $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        } catch (NotFoundHttpException) {
            $this->dispatch('toast', type: 'danger', title: __('Your account isn’t available to change right now.'));

            return;
        }

        $this->load();
        $this->dispatch('toast', type: 'success', title: __('Your notification settings are saved.'));
    }

    public function render(): View
    {
        $member = $this->member();
        $groups = [];
        foreach (NotificationEvent::forMemberSettings() as $event) {
            $groups[$event->group()->label()][] = $event;
        }

        return view('livewire.member.settings.notification-settings', [
            'groups' => $groups,
            'emailReady' => $member->canReceiveAccountMail(),
            'smsReady' => $member->hasVerifiedPhone(),
            'quietFrom' => sprintf('%02d:00', Settings::int(SettingKey::NotificationsQuietFrom)),
            'quietUntil' => sprintf('%02d:00', Settings::int(SettingKey::NotificationsQuietUntil)),
        ]);
    }

    /** The member's rows, else each event's defaults (what NotificationRouter uses). */
    private function load(): void
    {
        $member = $this->member();
        $rows = NotificationPreference::query()->where('user_id', $member->id)->get()->keyBy('event');

        $this->matrix = [];
        foreach (NotificationEvent::forMemberSettings() as $event) {
            $row = $event->isMandatory() ? null : $rows->get($event->value);
            $this->matrix[$event->value] = [
                'in_app' => $row !== null ? $row->in_app : $event->defaultInApp(),
                'email' => $event->defaultEmail() && ($row !== null ? $row->email : true),
                'sms' => $event->defaultSms() && ($row !== null ? $row->sms : true),
            ];
        }
    }

    private function member(): User
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User, 404);

        return $user;
    }
}
