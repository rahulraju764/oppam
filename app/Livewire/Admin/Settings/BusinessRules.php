<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Settings;

use App\Actions\Admin\Settings\UpdateSetting;
use App\Enums\SettingKey;
use App\Models\AdminUser;
use App\Support\Facades\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Business rules editor (PRD §11 A15).
 * Validated against SettingKey specifications, audited with reason before updating.
 */
#[Layout('layouts::admin', ['title' => 'Business Rules'])]
final class BusinessRules extends Component
{
    public string $activeCategory = 'all';

    public string $search = '';

    public bool $showEditModal = false;

    #[Locked]
    public ?string $editingKey = null;

    public string $editingLabel = '';

    public string $editingDescription = '';

    public mixed $editingValue = null;

    public mixed $editingDefault = null;

    public string $editingRulesDescription = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->authorize('settings.view');
    }

    public function openEdit(string $keyStr): void
    {
        $key = SettingKey::tryFrom($keyStr);
        if ($key === null) {
            return;
        }

        $this->editingKey = $key->value;
        $this->editingLabel = $this->labelForKey($key);
        $this->editingDescription = $this->descriptionForKey($key);
        $this->editingValue = Settings::get($key);
        $this->editingDefault = $key->default();
        $this->editingRulesDescription = implode(', ', $key->rules());
        $this->reason = '';
        $this->resetErrorBag();
        $this->showEditModal = true;
    }

    public function closeEdit(): void
    {
        $this->showEditModal = false;
        $this->reset('editingKey', 'editingLabel', 'editingDescription', 'editingValue', 'editingDefault', 'editingRulesDescription', 'reason');
        $this->resetErrorBag();
    }

    public function save(UpdateSetting $action): void
    {
        $this->authorize('settings.edit');

        if ($this->editingKey === null) {
            return;
        }

        $key = SettingKey::from($this->editingKey);

        $this->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $action->handle($this->admin(), $key, $this->editingValue, $this->reason);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->showEditModal = false;
        session()->flash('status', __("Rule ':rule' updated successfully.", ['rule' => $this->editingLabel]));
    }

    public function render(): View
    {
        $allRules = $this->categorizedRules();

        $filtered = collect($allRules)
            ->when($this->activeCategory !== 'all', fn ($col) => $col->filter(fn ($cat) => $cat['id'] === $this->activeCategory))
            ->map(function ($cat) {
                if ($this->search !== '') {
                    $search = strtolower($this->search);
                    $cat['items'] = array_filter($cat['items'], function ($item) use ($search) {
                        return str_contains(strtolower($item['label']), $search)
                            || str_contains(strtolower($item['key']), $search)
                            || str_contains(strtolower($item['description']), $search);
                    });
                }

                return $cat;
            })
            ->filter(fn ($cat) => count($cat['items']) > 0)
            ->values()
            ->all();

        return view('livewire.admin.settings.business-rules', [
            'categories' => $filtered,
        ]);
    }

    /**
     * @return list<array{id: string, name: string, icon: string, items: list<array{key: string, label: string, description: string, current: mixed, default: mixed, unit: string}>}>
     */
    private function categorizedRules(): array
    {
        return [
            [
                'id' => 'profile',
                'name' => 'Profile & Members',
                'icon' => 'fa-user',
                'items' => [
                    $this->ruleItem(SettingKey::MinAgeFemale, 'Minimum Marriage Age (Female)', 'Legal age floor for brides in India (min 18)', 'years'),
                    $this->ruleItem(SettingKey::MinAgeMale, 'Minimum Marriage Age (Male)', 'Legal age floor for grooms in India (min 21)', 'years'),
                    $this->ruleItem(SettingKey::ProfileAutoHideInactiveDays, 'Inactivity Auto-Hide', 'Hides profile from search after N days of inactivity', 'days'),
                    $this->ruleItem(SettingKey::MembersPurgeAfterDays, 'Closed Account Purge', 'Permanently anonymises closed accounts after recovery window', 'days'),
                    $this->ruleItem(SettingKey::MembersSettingsChangesPerHour, 'Settings Changes Rate Limit', 'Max settings updates allowed per hour per member', 'per hour'),
                    $this->ruleItem(SettingKey::MembersEmailChangesPerDay, 'Email Changes Rate Limit', 'Max email change requests allowed per day per member', 'per day'),
                ],
            ],
            [
                'id' => 'matching',
                'name' => 'Matching & Discovery',
                'icon' => 'fa-heart',
                'items' => [
                    $this->ruleItem(SettingKey::InterestExpiryDays, 'Interest Expiry', 'Days before an unanswered interest expires', 'days'),
                    $this->ruleItem(SettingKey::InterestResendCooldownDays, 'Interest Resend Cooldown', 'Days before a declined interest can be resent', 'days'),
                    $this->ruleItem(SettingKey::DailyMatchCount, 'Daily Matches Count', 'Number of daily recommendations generated per member', 'matches'),
                    $this->ruleItem(SettingKey::FavoritesMaxPerMinute, 'Shortlist Rate Limit', 'Max profiles a member can favorite per minute', 'per min'),
                    $this->ruleItem(SettingKey::InterestsMaxPerMinute, 'Interest Rate Limit', 'Max interests a member can send per minute', 'per min'),
                    $this->ruleItem(SettingKey::SearchMaxPerMinute, 'Search Rate Limit', 'Max search requests per minute', 'per min'),
                    $this->ruleItem(SettingKey::ProfileViewsMaxPerMinute, 'Profile Views Rate Limit', 'Max profile views per minute before throttling', 'per min'),
                ],
            ],
            [
                'id' => 'chat',
                'name' => 'Chat & Messaging',
                'icon' => 'fa-comments',
                'items' => [
                    $this->ruleItem(SettingKey::ChatUnsendWindowMinutes, 'Unsend Window', 'Minutes allowed to delete a sent message for everyone', 'minutes'),
                    $this->ruleItem(SettingKey::ChatMaxPerMinute, 'Chat Message Rate Limit', 'Max messages a member can send per minute', 'msgs/min'),
                    $this->ruleItem(SettingKey::ChatMaxLength, 'Chat Max Characters', 'Maximum characters permitted in a single message', 'chars'),
                    $this->ruleItem(SettingKey::ChatImageMaxMb, 'Chat Image Max Size', 'Maximum image upload size in chat', 'MB'),
                    $this->ruleItem(SettingKey::ChatRetentionDays, 'Chat History Retention', 'Days chat logs are retained before archival', 'days'),
                    $this->ruleItem(SettingKey::ChatDigestDelayMinutes, 'Email Digest Delay', 'Minutes before sending unread message email alert', 'minutes'),
                    $this->ruleItem(SettingKey::ChatSafetyFirstMessages, 'Safety Warning Count', 'Number of initial messages in a thread showing safety tip', 'messages'),
                    $this->ruleItem(SettingKey::ChatAutoFreezeReports, 'Auto-Freeze Report Threshold', 'Unique reports before conversation is automatically frozen', 'reports'),
                ],
            ],
            [
                'id' => 'media',
                'name' => 'Photos & Media',
                'icon' => 'fa-camera',
                'items' => [
                    $this->ruleItem(SettingKey::PhotosMaxAdditional, 'Max Additional Photos', 'Maximum secondary gallery photos allowed per profile', 'photos'),
                    $this->ruleItem(SettingKey::PhotosMaxUploadMb, 'Max Photo Upload Size', 'Maximum file size allowed per photo upload', 'MB'),
                ],
            ],
            [
                'id' => 'notifications',
                'name' => 'Notifications & Comms',
                'icon' => 'fa-bell',
                'items' => [
                    $this->ruleItem(SettingKey::NotificationsQuietFrom, 'SMS Quiet Hours Start', 'Hour (0-23 IST) when marketing & non-urgent SMS pause', 'hour (IST)'),
                    $this->ruleItem(SettingKey::NotificationsQuietUntil, 'SMS Quiet Hours End', 'Hour (0-23 IST) when queued SMS resume sending', 'hour (IST)'),
                ],
            ],
            [
                'id' => 'broker',
                'name' => 'Bureaus & Brokers',
                'icon' => 'fa-briefcase',
                'items' => [
                    $this->ruleItem(SettingKey::BrokerStaffSeatsDefault, 'Default Bureau Staff Seats', 'Initial staff seat limit assigned to new bureaus', 'seats'),
                    $this->ruleItem(SettingKey::BrokerImportRowsPerFile, 'Bulk Import File Row Cap', 'Maximum client rows allowed in one uploaded file', 'rows'),
                    $this->ruleItem(SettingKey::BrokerImportRowsPerDay, 'Daily Bulk Import Cap', 'Maximum profiles a verified bureau can import per day', 'profiles'),
                    $this->ruleItem(SettingKey::BrokerImportAutoPauseRejectionPct, 'Import Auto-Pause Rejection %', 'Auto-pauses imports if rejection rate exceeds this threshold', '%'),
                    $this->ruleItem(SettingKey::BrokerPayoutMinimumPaise, 'Minimum Payout Threshold', 'Minimum earnings before a payout run includes the broker', 'paise'),
                    $this->ruleItem(SettingKey::BrokerPayoutSecondApproverPaise, 'Second Approval Payout Limit', 'Payout runs exceeding this require a second admin approval', 'paise'),
                    $this->ruleItem(SettingKey::BrokerPayoutTdsBps, 'Broker TDS Rate', 'Tax Deducted at Source in basis points (500 bps = 5%)', 'bps'),
                ],
            ],
            [
                'id' => 'auth',
                'name' => 'Auth & OTP',
                'icon' => 'fa-shield',
                'items' => [
                    $this->ruleItem(SettingKey::OtpTtlMinutes, 'OTP Validity', 'Minutes an OTP code remains valid before expiration', 'minutes'),
                    $this->ruleItem(SettingKey::OtpMaxAttempts, 'OTP Max Wrong Attempts', 'Allowed attempts before an OTP is invalidated', 'attempts'),
                    $this->ruleItem(SettingKey::OtpMaxSendsPer15Minutes, 'OTP Resends / 15 Min', 'Max OTP send requests in a 15-minute rolling window', 'sends'),
                    $this->ruleItem(SettingKey::OtpMaxSendsPerPhonePerDay, 'Daily OTP / Phone', 'Max OTP sends to a single mobile number per 24 hours', 'sends/day'),
                ],
            ],
        ];
    }

    /**
     * @return array{key: string, label: string, description: string, current: mixed, default: mixed, unit: string}
     */
    private function ruleItem(SettingKey $key, string $label, string $description, string $unit): array
    {
        return [
            'key' => $key->value,
            'label' => $label,
            'description' => $description,
            'current' => Settings::get($key),
            'default' => $key->default(),
            'unit' => $unit,
        ];
    }

    private function labelForKey(SettingKey $key): string
    {
        foreach ($this->categorizedRules() as $cat) {
            foreach ($cat['items'] as $item) {
                if ($item['key'] === $key->value) {
                    return $item['label'];
                }
            }
        }

        return $key->value;
    }

    private function descriptionForKey(SettingKey $key): string
    {
        foreach ($this->categorizedRules() as $cat) {
            foreach ($cat['items'] as $item) {
                if ($item['key'] === $key->value) {
                    return $item['description'];
                }
            }
        }

        return '';
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $user */
        $user = Auth::guard('admin')->user();

        return $user;
    }
}
