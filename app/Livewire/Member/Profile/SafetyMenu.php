<?php

declare(strict_types=1);

namespace App\Livewire\Member\Profile;

use App\Actions\Safety\BlockProfile;
use App\Actions\Safety\IgnoreProfile;
use App\Actions\Safety\ReportProfile;
use App\Enums\ReportReason;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\ReportRefused;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The "More" menu on another member's profile (PRD M09): Block (silent, two-way — R-M06-4),
 * Ignore (one-way hide) and Report (reason + details → the A10 case). Each opens a confirmation
 * dialog; every action re-checks on the server who may do it (the profile code is locked, and
 * the Actions load nothing the member can't see). After a block or an ignore the profile is gone
 * for this member, so the page moves to /settings/blocked where it can be undone.
 */
final class SafetyMenu extends Component
{
    #[Locked]
    public string $profileCode = '';

    #[Locked]
    public string $name = '';

    public string $reportReason = '';

    public string $reportDetails = '';

    public function mount(string $profileCode, string $name): void
    {
        $this->profileCode = $profileCode;
        $this->name = $name;
    }

    public function block(BlockProfile $block): void
    {
        $this->run(fn () => $block->handle($this->member(), $this->target()),
            __('You blocked :name. You won’t see each other any more — they are not told.', ['name' => $this->name]));
    }

    public function ignore(IgnoreProfile $ignore): void
    {
        $this->run(fn () => $ignore->handle($this->member(), $this->target()),
            __('You won’t see :name in your search and lists any more.', ['name' => $this->name]));
    }

    public function report(ReportProfile $report): void
    {
        try {
            $report->handle($this->member(), $this->target(), $this->reportReason, $this->reportDetails);
        } catch (ReportRefused|ImpersonationRestricted $refused) {
            $this->dispatch('close-modal', name: 'report-profile');
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->reset('reportReason', 'reportDetails');
        $this->dispatch('close-modal', name: 'report-profile');
        $this->dispatch('toast', type: 'success', title: __('Thank you for telling us. Our safety team will review it — :name won’t be told who reported.', ['name' => $this->name]));
    }

    public function render(): View
    {
        return view('livewire.member.profile.safety-menu', ['reasons' => ReportReason::options()]);
    }

    /** Block / ignore: on success the profile is hidden, so go where it can be undone. */
    private function run(callable $action, string $done): void
    {
        try {
            $action();
        } catch (SafetyRefused|ImpersonationRestricted $refused) {
            $this->dispatch('close-modal', name: 'block-profile');
            $this->dispatch('close-modal', name: 'ignore-profile');
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        session()->flash('status', $done);
        $this->redirectRoute('member.settings.blocked', navigate: true);
    }

    private function target(): Profile
    {
        return Profile::query()->where('code', $this->profileCode)->first() ?? abort(404);
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
