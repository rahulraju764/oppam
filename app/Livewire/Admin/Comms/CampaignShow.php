<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Comms;

use App\Actions\Admin\Comms\ApproveCampaign;
use App\Actions\Admin\Comms\CancelCampaign;
use App\Actions\Admin\Comms\SendTestCampaign;
use App\Actions\Admin\Comms\SubmitCampaign;
use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\CommsChannel;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\Campaign;
use App\Queries\Admin\Comms\CampaignStats;
use App\Services\Masters\Masters;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One A14 campaign (P8.3b1, `campaigns.view`): what it says, who it targets, its numbers
 * (refreshed every 10 s while it sends) and what can be done next — edit / test / send (draft,
 * `campaigns.send`), approve (waiting, `campaigns.approve`, a different admin), cancel. Every
 * action re-checks permission and state in its Action; the buttons only follow them.
 *
 * @property-read Campaign $campaign
 */
final class CampaignShow extends Component
{
    #[Locked]
    public string $code = '';

    public function mount(string $campaign): void
    {
        $this->authorize('campaigns.view');
        $this->code = Campaign::query()->where('code', $campaign)->firstOrFail()->code;

        if (is_string($toast = session('campaign.toast'))) {
            $this->dispatch('toast', type: 'success', title: $toast);
        }
    }

    #[Computed]
    public function campaign(): Campaign
    {
        return Campaign::query()->with(['creator:id,name', 'approver:id,name'])->where('code', $this->code)->firstOrFail();
    }

    public function submit(SubmitCampaign $submit): void
    {
        $this->run(function () use ($submit): string {
            $row = $submit->handle($this->admin(), $this->campaign);

            return match ($row->status) {
                CampaignStatus::PendingApproval => __('Sent for approval: :count members is above the limit for one admin.', ['count' => number_format($row->recipient_count)]),
                CampaignStatus::Scheduled => __('Scheduled for :count members.', ['count' => number_format($row->recipient_count)]),
                default => __('Sending to :count members.', ['count' => number_format($row->recipient_count)]),
            };
        });
    }

    public function sendTest(SendTestCampaign $test): void
    {
        $this->run(function () use ($test): string {
            $sent = $test->handle($this->admin(), $this->campaign);

            return $this->campaign->channel === CommsChannel::InApp
                ? __('In-app messages have no test send — the preview here is what members see.')
                : trans_choice('Test sent (:count message).|Test sent (:count messages).', $sent);
        });
    }

    public function approve(ApproveCampaign $approve): void
    {
        $this->run(function () use ($approve): string {
            $approve->handle($this->admin(), $this->campaign);

            return __('Approved.');
        });
    }

    public function cancel(CancelCampaign $cancel): void
    {
        $this->run(function () use ($cancel): string {
            $cancel->handle($this->admin(), $this->campaign);

            return __('Cancelled. Nobody else will receive it.');
        });
    }

    public function render(CampaignStats $stats, Masters $masters): View
    {
        $campaign = $this->campaign;
        $admin = $this->admin();
        $own = $campaign->created_by === $admin->id || $campaign->submitted_by === $admin->id;

        return view('livewire.admin.comms.campaign-show', [
            'row' => $campaign,
            'stats' => $stats->one($campaign),
            'skippedBy' => DB::table('campaign_recipients')->where('campaign_id', $campaign->id)
                ->where('status', CampaignRecipientStatus::Skipped->value)
                ->groupBy('skip_reason')->selectRaw('skip_reason, count(*) as n')->pluck('n', 'skip_reason')->all(),
            'canSend' => $admin->can('campaigns.send'),
            'canApprove' => $admin->can('campaigns.approve') && ! $own && $campaign->status === CampaignStatus::PendingApproval,
            'canCancel' => ! $campaign->status->isFinal()
                && ($admin->can('campaigns.send') || ($campaign->status === CampaignStatus::PendingApproval && $admin->can('campaigns.approve'))),
            'filters' => $this->filterSummary($campaign, $masters),
            'timezone' => (string) config('oppam.display_timezone'),
        ])->layout('layouts::admin', ['title' => $campaign->name]);
    }

    /** @return array<string, string> filter label => value, only the filters that are set */
    private function filterSummary(Campaign $campaign, Masters $masters): array
    {
        $s = $campaign->segment;
        $yes = fn (?bool $v): ?string => $v === null ? null : ($v ? __('Yes') : __('No'));
        $religions = Masters::forSelect($masters->religions());
        $districts = Masters::forSelect($masters->allDistricts());

        return array_filter([
            __('Plan') => $s->plan?->label(),
            __('Gender') => $s->gender?->label(),
            __('Religion') => $s->religionId !== null ? ($religions[$s->religionId] ?? '#'.$s->religionId) : null,
            __('District') => $s->districtId !== null ? ($districts[$s->districtId] ?? '#'.$s->districtId) : null,
            __('Profile at least') => $s->completenessMin !== null ? $s->completenessMin.' %' : null,
            __('Active in the last') => $s->activeWithinDays !== null ? trans_choice(':count day|:count days', $s->activeWithinDays) : null,
            __('Inactive for at least') => $s->inactiveForDays !== null ? trans_choice(':count day|:count days', $s->inactiveForDays) : null,
            __('Registered from') => $s->registeredFrom?->format('d M Y'),
            __('Registered until') => $s->registeredTo?->format('d M Y'),
            __('Has an approved photo') => $yes($s->hasPhoto),
            __('ID verified') => $yes($s->verified),
            __('Came through a bureau') => $yes($s->viaBureau),
        ], fn (?string $v): bool => $v !== null);
    }

    /** @param callable(): string $action */
    private function run(callable $action): void
    {
        try {
            $message = $action();
        } catch (ValidationException $invalid) {
            $this->dispatch('toast', type: 'danger', title: collect($invalid->errors())->flatten()->first());

            return;
        } catch (CommsRefused $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        } finally {
            unset($this->campaign);
        }

        $this->dispatch('toast', type: 'success', title: $message);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return Auth::guard('admin')->user();
    }
}
