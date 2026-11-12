<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Data\Comms\CampaignData;
use App\Enums\CampaignStatus;
use App\Enums\CommsChannel;
use App\Enums\SettingKey;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\Campaign;
use App\Models\CommunicationTemplate;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A14 campaign builder (P8.3b1, `campaigns.send`): create or change a DRAFT campaign. Nothing is
 * sent and no audience is frozen here — SubmitCampaign does that.
 * Rules per channel: email needs a subject and Markdown text; in-app a title and up to 500
 * characters; SMS a DLT template id (the text is the registered one, for reference, up to 320).
 * {first_name} is the only placeholder. The button link is a path on this site or https (as for
 * announcements); a button needs both link and text (SMS has none). Throttle 1 … A15
 * `campaigns.max_per_minute`; a start time, when set, is in the future. Audited (name, channel,
 * filters — never the text).
 */
final class SaveCampaign
{
    /** The one per-member value a campaign may fill in (CampaignMessage). */
    public const PLACEHOLDER = 'first_name';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws CommsRefused
     */
    public function handle(AdminUser $admin, CampaignData $data, ?Campaign $existing = null): Campaign
    {
        Gate::forUser($admin)->authorize('campaigns.send');
        $this->validate($data);

        return DB::transaction(function () use ($admin, $data, $existing): Campaign {
            $campaign = $existing !== null ? Campaign::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail() : new Campaign;
            if ($campaign->exists && ! $campaign->isEditable()) {
                throw CommsRefused::campaignNotDraft();
            }

            $isNew = ! $campaign->exists;
            $sms = $data->channel === CommsChannel::Sms;
            $campaign->forceFill([
                'code' => $campaign->code ?? $this->newCode(),
                'name' => trim($data->name),
                'channel' => $data->channel,
                'segment_filters' => $data->segment->toArray(),
                'template_id' => $this->templateId($data),
                'subject' => $sms ? null : self::blankToNull($data->subject),
                'message' => trim($data->message),
                'action_url' => $sms ? null : self::blankToNull($data->actionUrl),
                'action_text' => $sms ? null : self::blankToNull($data->actionText),
                'dlt_template_id' => $sms ? self::blankToNull($data->dltTemplateId) : null,
                'scheduled_at' => $data->scheduledAt,
                'throttle_per_minute' => $data->throttlePerMinute,
                'status' => CampaignStatus::Draft,
                'created_by' => $campaign->created_by ?? $admin->id,
            ])->save();

            $this->audit->record($isNew ? 'comms.campaign_created' : 'comms.campaign_updated', $campaign,
                after: ['name' => $campaign->name, 'channel' => $campaign->channel->value, 'segment' => $campaign->segment_filters],
                actor: $admin, subjectLabel: $campaign->code);

            return $campaign;
        });
    }

    /** @throws ValidationException */
    private function validate(CampaignData $data): void
    {
        $errors = [];
        $name = trim($data->name);
        $subject = trim((string) $data->subject);
        $message = trim($data->message);
        $url = (string) self::blankToNull($data->actionUrl);
        $text = (string) self::blankToNull($data->actionText);

        if ($name === '' || mb_strlen($name) > 150) {
            $errors['name'] = __('Give the campaign a name (up to 150 characters).');
        }

        switch ($data->channel) {
            case CommsChannel::Email:
                if ($subject === '' || mb_strlen($subject) > 200) {
                    $errors['subject'] = __('An email needs a subject (up to 200 characters).');
                }
                if ($message === '' || mb_strlen($message) > 10_000) {
                    $errors['message'] = __('Write the email (up to 10,000 characters).');
                }
                break;
            case CommsChannel::InApp:
                if ($subject === '' || mb_strlen($subject) > 150) {
                    $errors['subject'] = __('An in-app message needs a title (up to 150 characters).');
                }
                if ($message === '' || mb_strlen($message) > 500) {
                    $errors['message'] = __('Write the message (up to 500 characters).');
                }
                break;
            case CommsChannel::Sms:
                if (preg_match('/^\d{10,25}$/', trim((string) $data->dltTemplateId)) !== 1) {
                    $errors['dltTemplateId'] = __('An SMS needs its approved DLT template id (digits only).');
                }
                if ($message === '' || mb_strlen($message) > 320) {
                    $errors['message'] = __('Paste the registered SMS text (up to 320 characters).');
                }
                break;
        }

        preg_match_all('/\{([a-z_]+)\}/', $subject.' '.$message, $found);
        $notAllowed = array_values(array_unique(array_diff($found[1], [self::PLACEHOLDER])));
        if ($notAllowed !== []) {
            $errors['message'] = __('Only {first_name} can be filled in for each member. Remove: :list', ['list' => '{'.implode('}, {', $notAllowed).'}']);
        }

        if ($data->channel !== CommsChannel::Sms) {
            if ($url !== '' && ! SaveAnnouncement::safeLink($url)) {
                $errors['actionUrl'] = __('Use a page on this site (starting with /) or an https:// address.');
            }
            if (($url === '') !== ($text === '') || mb_strlen($text) > 50) {
                $errors['actionText'] = __('A button needs both a link and its text (up to 50 characters).');
            }
        }

        $max = Settings::int(SettingKey::CampaignsMaxPerMinute);
        if ($data->throttlePerMinute < 1 || $data->throttlePerMinute > $max) {
            $errors['throttlePerMinute'] = __('Send between 1 and :max messages a minute.', ['max' => number_format($max)]);
        }

        if ($data->scheduledAt !== null && ! $data->scheduledAt->isFuture()) {
            $errors['scheduledAt'] = __('The start time must be in the future (or leave it empty to send on submit).');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** A campaign text (A14 template manager, event "campaign") on the same channel, or none. */
    private function templateId(CampaignData $data): ?string
    {
        if ($data->templateId === null) {
            return null;
        }

        return CommunicationTemplate::query()->whereKey($data->templateId)
            ->where('event', 'campaign')->where('channel', $data->channel->value)->value('id');
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }

    private function newCode(): string
    {
        do {
            $code = 'CMP'.Str::upper(Str::random(8));
        } while (Campaign::query()->where('code', $code)->exists());

        return $code;
    }
}
