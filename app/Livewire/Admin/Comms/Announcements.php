<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Comms;

use App\Actions\Admin\Comms\EndAnnouncement;
use App\Actions\Admin\Comms\SaveAnnouncement;
use App\Data\Comms\AnnouncementData;
use App\Enums\AnnouncementAudience;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\Announcement;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A14 announcements (P8.3a): list (live / scheduled / ended), create or edit a banner, end one now.
 * Reading needs `campaigns.view`; every write `notifications.broadcast` (checked in the Actions).
 * Rows are addressed by code. Times are typed in IST.
 */
final class Announcements extends Component
{
    use WithPagination;

    #[Locked]
    public ?string $editing = null;

    public string $title = '';

    public string $body = '';

    public string $actionUrl = '';

    public string $actionText = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public string $audience = 'ALL';

    public bool $isDismissible = true;

    public function mount(): void
    {
        $this->authorize('campaigns.view');
    }

    public function create(): void
    {
        $this->authorize('notifications.broadcast');
        $this->resetForm();
        $this->editing = '';
        $this->dispatch('open-drawer', name: 'announcement-editor');
    }

    public function edit(string $code): void
    {
        $this->authorize('notifications.broadcast');
        $row = Announcement::query()->where('code', $code)->whereNull('ended_at')->firstOrFail();
        $tz = 'Asia/Kolkata';

        $this->resetForm();
        $this->editing = $row->code;
        $this->title = $row->title;
        $this->body = $row->body;
        $this->actionUrl = (string) $row->action_url;
        $this->actionText = (string) $row->action_text;
        $this->startsAt = $row->starts_at?->timezone($tz)->format('Y-m-d\TH:i') ?? '';
        $this->endsAt = $row->ends_at?->timezone($tz)->format('Y-m-d\TH:i') ?? '';
        $this->audience = $row->audience->value;
        $this->isDismissible = $row->is_dismissible;
        $this->dispatch('open-drawer', name: 'announcement-editor');
    }

    public function save(SaveAnnouncement $save): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:500'],
            'actionUrl' => ['nullable', 'string', 'max:255'],
            'actionText' => ['nullable', 'string', 'max:50'],
            'startsAt' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'endsAt' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'audience' => ['required', Rule::enum(AnnouncementAudience::class)],
        ]);

        $existing = $this->editing !== null && $this->editing !== ''
            ? Announcement::query()->where('code', $this->editing)->firstOrFail() : null;

        try {
            $row = $save->handle($this->admin(), new AnnouncementData(
                title: $this->title,
                body: $this->body,
                actionUrl: $this->actionUrl,
                actionText: $this->actionText,
                startsAt: self::ist($this->startsAt),
                endsAt: self::ist($this->endsAt),
                audience: AnnouncementAudience::from($this->audience),
                isDismissible: $this->isDismissible,
            ), $existing);
        } catch (ValidationException $invalid) {
            $this->setErrorBag($invalid->validator->errors());

            return;
        } catch (CommsRefused $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->dispatch('close-drawer', name: 'announcement-editor');
        $this->resetForm();
        $this->dispatch('toast', type: 'success', title: $row->isLive()
            ? __('The announcement is live — members see it now.')
            : __('Scheduled. It goes live at its start time.'));
    }

    public function end(string $code, EndAnnouncement $end): void
    {
        $row = Announcement::query()->where('code', $code)->firstOrFail();

        try {
            $end->handle($this->admin(), $row);
        } catch (CommsRefused $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', title: __('Ended. It has left every member page.'));
    }

    /** @return LengthAwarePaginator<int, Announcement> */
    #[Computed]
    public function announcements(): LengthAwarePaginator
    {
        return Announcement::query()->orderByRaw('ended_at is not null')->orderByDesc('created_at')->paginate(15);
    }

    public function render(): View
    {
        return view('livewire.admin.comms.announcements', [
            'audiences' => AnnouncementAudience::options(),
            'canBroadcast' => $this->admin()->can('notifications.broadcast'),
            'timezone' => 'Asia/Kolkata',
        ])->layout('layouts::admin', ['title' => __('Announcements')]);
    }

    private static function ist(string $value): ?CarbonImmutable
    {
        return trim($value) === '' ? null : CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, 'Asia/Kolkata')?->utc();
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'title', 'body', 'actionUrl', 'actionText', 'startsAt', 'endsAt', 'audience', 'isDismissible');
        $this->resetErrorBag();
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return Auth::guard('admin')->user();
    }
}
