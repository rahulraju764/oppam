<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Comms;

use App\Actions\Admin\Comms\BuildCampaign;
use App\Models\AdminUser;
use App\Models\CommunicationTemplate;
use App\Models\Masters\District;
use App\Models\Masters\Religion;
use App\Queries\Admin\Comms\SegmentQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Visual campaign segment builder and creator (PRD §11 A14).
 */
#[Layout('layouts::admin', ['title' => 'Create Campaign'])]
final class CampaignBuilder extends Component
{
    public string $name = '';

    public string $channel = 'email';

    public ?string $templateId = null;

    public string $subject = '';

    public string $message = '';

    public string $scheduledAt = '';

    public int $throttlePerMinute = 60;

    // Segment Filters
    public string $gender = '';

    public ?int $religionId = null;

    public ?int $districtId = null;

    public ?int $completenessMin = null;

    public ?bool $isVerified = null;

    public ?bool $isPremium = null;

    public ?bool $hasPhoto = null;

    public ?bool $isBroker = null;

    public ?int $activeWithinDays = null;

    public function mount(): void
    {
        $this->authorize('campaigns.send');
    }

    public function updatedTemplateId(?string $value): void
    {
        if ($value) {
            $template = CommunicationTemplate::query()->find($value);
            if ($template) {
                $live = $template->currentVersion();
                $this->channel = $template->channel->value;
                $this->subject = (string) $live?->subject;
                $this->message = (string) $live?->body;
            }
        }
    }

    public function getEstimatedAudienceProperty(SegmentQuery $segmentQuery): int
    {
        return $segmentQuery->build($this->filters(), $this->channel)->count();
    }

    public function save(BuildCampaign $action): mixed
    {
        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'channel' => ['required', 'in:email,sms,in_app'],
            'message' => ['required', 'string'],
            'subject' => ['nullable', 'string', 'max:200'],
            'scheduledAt' => ['nullable', 'date'],
            'throttlePerMinute' => ['required', 'integer', 'min:1', 'max:500'],
        ]);

        $admin = $this->admin();

        try {
            $campaign = $action->handle($admin, [
                'name' => $this->name,
                'channel' => $this->channel,
                'template_id' => $this->templateId,
                'subject' => $this->subject,
                'message' => $this->message,
                'scheduled_at' => $this->scheduledAt ?: null,
                'throttle_per_minute' => $this->throttlePerMinute,
                'segment_filters' => $this->filters(),
            ]);

            return redirect()->route('admin.comms.campaigns.index')->with('toast', [
                'type' => 'success',
                'message' => __('Campaign created with :count recipients.', ['count' => $campaign->recipient_count]),
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $msgs) {
                foreach ($msgs as $m) {
                    $this->addError($key, $m);
                }
            }

            return null;
        }
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        $filters = [];
        if ($this->gender !== '') {
            $filters['gender'] = $this->gender;
        }
        if ($this->religionId) {
            $filters['religion_id'] = $this->religionId;
        }
        if ($this->districtId) {
            $filters['district_id'] = $this->districtId;
        }
        if ($this->completenessMin !== null && $this->completenessMin > 0) {
            $filters['completeness_min'] = $this->completenessMin;
        }
        if ($this->isVerified !== null) {
            $filters['is_verified'] = $this->isVerified;
        }
        if ($this->isPremium !== null) {
            $filters['is_premium'] = $this->isPremium;
        }
        if ($this->hasPhoto !== null) {
            $filters['has_photo'] = $this->hasPhoto;
        }
        if ($this->isBroker !== null) {
            $filters['is_broker'] = $this->isBroker;
        }
        if ($this->activeWithinDays !== null && $this->activeWithinDays > 0) {
            $filters['active_within_days'] = $this->activeWithinDays;
        }

        return $filters;
    }

    public function render(SegmentQuery $segmentQuery): View
    {
        return view('livewire.admin.comms.campaign-builder', [
            'religions' => Religion::query()->ordered()->get(),
            'districts' => District::query()->ordered()->get(),
            'templates' => CommunicationTemplate::query()->where('channel', $this->channel)->get(),
            'audienceCount' => $this->getEstimatedAudienceProperty($segmentQuery),
        ]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $user */
        $user = Auth::guard('admin')->user();

        return $user;
    }
}
