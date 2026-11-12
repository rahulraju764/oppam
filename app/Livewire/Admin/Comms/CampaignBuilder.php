<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Comms;

use App\Actions\Admin\Comms\SaveCampaign;
use App\Data\Comms\CampaignData;
use App\Data\Comms\CampaignSegment;
use App\Enums\CampaignStatus;
use App\Enums\CommsChannel;
use App\Enums\Gender;
use App\Enums\PlanCode;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\Campaign;
use App\Models\CommunicationTemplate;
use App\Queries\Admin\Comms\SegmentQuery;
use App\Services\Masters\Masters;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A14 campaign builder (P8.3b1, `campaigns.send`): segment filters with a live audience estimate
 * (the same SegmentQuery that freezes it at submit), the channel and its copy (optionally started
 * from an A14 campaign text), the button, the start time (IST) and the throttle. Saves a DRAFT
 * (SaveCampaign) and opens the campaign page, where it is tested and sent. Only drafts can be edited.
 */
final class CampaignBuilder extends Component
{
    #[Locked]
    public ?string $editing = null;

    public string $name = '';

    public string $channel = 'email';

    public string $subject = '';

    public string $message = '';

    public string $actionUrl = '';

    public string $actionText = '';

    public string $dltTemplateId = '';

    public string $scheduledAt = '';

    public int $throttlePerMinute = 300;

    public ?string $templateId = null;

    // Segment (null / '' = any)
    public string $plan = '';

    public string $gender = '';

    public ?int $religionId = null;

    public ?int $districtId = null;

    public ?int $completenessMin = null;

    public ?int $activeWithinDays = null;

    public ?int $inactiveForDays = null;

    public string $registeredFrom = '';

    public string $registeredTo = '';

    /** '' any · 'yes' · 'no' */
    public string $hasPhoto = '';

    public string $verified = '';

    public string $viaBureau = '';

    public function mount(?string $campaign = null): void
    {
        $this->authorize('campaigns.send');

        if ($campaign === null) {
            return;
        }

        $row = Campaign::query()->where('code', $campaign)->where('status', CampaignStatus::Draft->value)->firstOrFail();
        $segment = $row->segment;
        $this->editing = $row->code;
        $this->name = $row->name;
        $this->channel = $row->channel->value;
        $this->subject = (string) $row->subject;
        $this->message = $row->message;
        $this->actionUrl = (string) $row->action_url;
        $this->actionText = (string) $row->action_text;
        $this->dltTemplateId = (string) $row->dlt_template_id;
        $this->scheduledAt = $row->scheduled_at?->timezone(self::tz())->format('Y-m-d\TH:i') ?? '';
        $this->throttlePerMinute = $row->throttle_per_minute;
        $this->templateId = $row->template_id;
        $this->plan = (string) $segment->plan?->value;
        $this->gender = (string) $segment->gender?->value;
        $this->religionId = $segment->religionId;
        $this->districtId = $segment->districtId;
        $this->completenessMin = $segment->completenessMin;
        $this->activeWithinDays = $segment->activeWithinDays;
        $this->inactiveForDays = $segment->inactiveForDays;
        $this->registeredFrom = (string) $segment->registeredFrom?->format('Y-m-d');
        $this->registeredTo = (string) $segment->registeredTo?->format('Y-m-d');
        $this->hasPhoto = self::triState($segment->hasPhoto);
        $this->verified = self::triState($segment->verified);
        $this->viaBureau = self::triState($segment->viaBureau);
    }

    /** Start from an A14 campaign text (same channel): copies its live version into the form. */
    public function useTemplate(string $id): void
    {
        $this->authorize('campaigns.send');
        $template = CommunicationTemplate::query()->whereKey($id)->where('event', 'campaign')
            ->where('channel', $this->channelEnum()->value)->firstOrFail();
        $live = $template->currentVersion();

        $this->templateId = $template->id;
        $this->subject = (string) $live?->subject;
        $this->message = (string) $live?->body;
        $this->dltTemplateId = (string) $live?->dlt_template_id;
    }

    /** The members this segment reaches on this channel right now. */
    #[Computed]
    public function audience(): int
    {
        return app(SegmentQuery::class)->count($this->segment(), $this->channelEnum());
    }

    public function save(SaveCampaign $save): void
    {
        $this->validate([
            'channel' => ['required', Rule::enum(CommsChannel::class)],
            'plan' => ['nullable', Rule::in(['', ...array_keys(PlanCode::options())])],
            'gender' => ['nullable', Rule::in(['', ...array_keys(Gender::options())])],
            'religionId' => ['nullable', 'integer', 'exists:master_religions,id'],
            'districtId' => ['nullable', 'integer', 'exists:master_districts,id'],
            'completenessMin' => ['nullable', 'integer', 'min:0', 'max:100'],
            'activeWithinDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'inactiveForDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'registeredFrom' => ['nullable', 'date_format:Y-m-d'],
            'registeredTo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:registeredFrom'],
            'hasPhoto' => ['nullable', Rule::in(['', 'yes', 'no'])],
            'verified' => ['nullable', Rule::in(['', 'yes', 'no'])],
            'viaBureau' => ['nullable', Rule::in(['', 'yes', 'no'])],
            'scheduledAt' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'throttlePerMinute' => ['required', 'integer'],
        ]);

        $existing = $this->editing !== null
            ? Campaign::query()->where('code', $this->editing)->firstOrFail() : null;

        try {
            $campaign = $save->handle($this->admin(), new CampaignData(
                name: $this->name,
                channel: $this->channelEnum(),
                segment: $this->segment(),
                subject: $this->subject,
                message: $this->message,
                actionUrl: $this->actionUrl,
                actionText: $this->actionText,
                dltTemplateId: $this->dltTemplateId,
                scheduledAt: trim($this->scheduledAt) === '' ? null
                    : CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->scheduledAt, self::tz())?->utc(),
                throttlePerMinute: $this->throttlePerMinute,
                templateId: $this->templateId,
            ), $existing);
        } catch (ValidationException $invalid) {
            $this->setErrorBag($invalid->validator->errors());

            return;
        } catch (CommsRefused $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        session()->flash('campaign.toast', __('Saved as a draft. Send yourself a test, then send it.'));
        $this->redirectRoute('admin.comms.campaigns.show', ['campaign' => $campaign->code], navigate: true);
    }

    public function render(Masters $masters): View
    {
        return view('livewire.admin.comms.campaign-builder', [
            'channels' => CommsChannel::options(),
            'plans' => PlanCode::options(),
            'genders' => Gender::options(),
            'religions' => Masters::forSelect($masters->religions()),
            'districts' => Masters::forSelect($masters->allDistricts()),
            'yesNo' => ['yes' => __('Yes'), 'no' => __('No')],
            'templates' => CommunicationTemplate::query()->where('event', 'campaign')
                ->where('channel', $this->channelEnum()->value)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'channelEnum' => $this->channelEnum(),
        ])->layout('layouts::admin', ['title' => $this->editing ? __('Edit campaign') : __('New campaign')]);
    }

    private function segment(): CampaignSegment
    {
        return CampaignSegment::fromArray([
            'plan' => $this->plan,
            'gender' => $this->gender,
            'religion_id' => $this->religionId,
            'district_id' => $this->districtId,
            'completeness_min' => $this->completenessMin,
            'active_within_days' => $this->activeWithinDays,
            'inactive_for_days' => $this->inactiveForDays,
            'registered_from' => $this->registeredFrom,
            'registered_to' => $this->registeredTo,
            'has_photo' => self::fromTriState($this->hasPhoto),
            'verified' => self::fromTriState($this->verified),
            'via_bureau' => self::fromTriState($this->viaBureau),
        ]);
    }

    private function channelEnum(): CommsChannel
    {
        return CommsChannel::tryFrom($this->channel) ?? CommsChannel::Email;
    }

    private static function triState(?bool $value): string
    {
        return $value === null ? '' : ($value ? 'yes' : 'no');
    }

    private static function fromTriState(string $value): ?bool
    {
        return match ($value) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    private static function tz(): string
    {
        return (string) config('oppam.display_timezone');
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return Auth::guard('admin')->user();
    }
}
