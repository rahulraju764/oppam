<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Comms;

use App\Enums\CampaignStatus;
use App\Enums\CommsChannel;
use App\Models\AdminUser;
use App\Models\Campaign;
use App\Queries\Admin\Comms\CampaignStats;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A14 campaigns list (P8.3b1, `campaigns.view`): every campaign with its status and headline
 * numbers (CampaignStats, one grouped query per page). Rows open the campaign page by code.
 * Filters are allow-listed enums in the URL.
 *
 * @property-read LengthAwarePaginator<int, Campaign> $campaigns
 */
final class Campaigns extends Component
{
    use WithPagination;

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'channel')]
    public string $channel = '';

    public function mount(): void
    {
        $this->authorize('campaigns.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'channel'], true)) {
            $this->resetPage();
        }
    }

    /** @return LengthAwarePaginator<int, Campaign> */
    #[Computed]
    public function campaigns(): LengthAwarePaginator
    {
        return Campaign::query()
            ->with('creator:id,name')
            ->when(CampaignStatus::tryFrom($this->status), fn ($q, CampaignStatus $s) => $q->where('status', $s->value))
            ->when(CommsChannel::tryFrom($this->channel), fn ($q, CommsChannel $c) => $q->where('channel', $c->value))
            ->latest()
            ->paginate(15);
    }

    public function render(CampaignStats $stats): View
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('admin')->user();

        return view('livewire.admin.comms.campaigns', [
            'stats' => $stats->for($this->campaigns->items()),
            'statuses' => CampaignStatus::options(),
            'channels' => CommsChannel::options(),
            'canSend' => $admin->can('campaigns.send'),
            'awaitingApproval' => $admin->can('campaigns.approve')
                ? Campaign::query()->where('status', CampaignStatus::PendingApproval->value)->count() : 0,
            'timezone' => (string) config('oppam.display_timezone'),
        ])->layout('layouts::admin', ['title' => __('Campaigns')]);
    }
}
