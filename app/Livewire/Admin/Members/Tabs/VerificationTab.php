<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Members\Tabs;

use App\Actions\Admin\Verification\RevokeVerification;
use App\Livewire\Admin\Members\Concerns\IsMemberTab;
use App\Models\VerificationRequest;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * A03 "Verification" (P1.7a → P6.1): the member's ID requests, newest first — document type,
 * masked number, status, who decided and why — with a link to the A05 review, and "force
 * re-verification" (RevokeVerification: verification.approve + typed reason) while verified.
 * Never a document: those open only in the A05 viewer.
 */
final class VerificationTab extends Component
{
    use IsMemberTab;

    public string $revokeReason = '';

    public function mount(string $code): void
    {
        $this->openTab($code);
    }

    public function revoke(RevokeVerification $revoke): void
    {
        $this->authorize('verification.approve');
        $revoke->handle($this->admin(), $this->profile(), $this->revokeReason);
        $this->revokeReason = '';
        $this->dispatch('toast', type: 'success', message: __('Verified badge removed. The member has been asked to verify again.'));
    }

    public function render(): View
    {
        return view('livewire.admin.members.tabs.verification-tab', [
            'requests' => VerificationRequest::query()->where('profile_id', $this->profile()->id)
                ->with('decidedBy:id,name')->latest('submitted_at')->limit(50)->get(),
            'verified' => $this->profile()->fresh()?->is_verified === true,
            'canRevoke' => $this->admin()->can('verification.approve'),
            'canOpenQueue' => $this->admin()->can('verification.queue.view'),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }
}
