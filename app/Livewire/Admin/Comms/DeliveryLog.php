<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Comms;

use App\Actions\Admin\Comms\ResendFailedMessage;
use App\Enums\CommsChannel;
use App\Enums\OutboundStatus;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\OutboundMessage;
use App\Models\Profile;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A14 delivery log (P8.3a, `campaigns.view`): every outbound email / SMS — masked recipient, event,
 * status, provider id. Filters by channel / status / event; search by member code only (never by
 * phone or email — the log stores neither in full). Resend (`campaigns.send`) for a failed member
 * notification only (ResendFailedMessage decides). Admins see all rows: the permission is the scope.
 */
final class DeliveryLog extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $channel = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $member = '';

    public function mount(): void
    {
        $this->authorize('campaigns.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['channel', 'status', 'member'], true)) {
            $this->resetPage();
        }
    }

    public function resend(string $id, ResendFailedMessage $resend): void
    {
        $message = OutboundMessage::query()->findOrFail($id);

        try {
            $resend->handle($this->admin(), $message);
        } catch (CommsRefused $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', title: __('Queued to send again — it appears in the log once sent (an SMS waits for the end of quiet hours).'));
    }

    /** @return LengthAwarePaginator<int, OutboundMessage> */
    #[Computed]
    public function messages(): LengthAwarePaginator
    {
        $query = OutboundMessage::query()->orderByDesc('created_at')->orderByDesc('id');

        if (($channel = CommsChannel::tryFrom($this->channel)) !== null) {
            $query->where('channel', $channel->value);
        }
        if (($status = OutboundStatus::tryFrom($this->status)) !== null) {
            $query->where('status', $status->value);
        }
        if (trim($this->member) !== '') {
            $userId = preg_match('/^OPM\d{4,9}$/i', trim($this->member)) === 1
                ? Profile::withTrashed()->where('code', mb_strtoupper(trim($this->member)))->value('user_id')
                : null;
            $query->where('user_id', $userId ?? '');
        }

        return $query->paginate(25);
    }

    public function render(): View
    {
        return view('livewire.admin.comms.delivery-log', [
            'channels' => CommsChannel::options(),
            'statuses' => OutboundStatus::options(),
            'canResend' => $this->admin()->can('campaigns.send'),
            'timezone' => 'Asia/Kolkata',
        ])->layout('layouts::admin', ['title' => __('Delivery log')]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return Auth::guard('admin')->user();
    }
}
