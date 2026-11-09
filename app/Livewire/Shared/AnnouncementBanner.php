<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Actions\Comms\DismissAnnouncement;
use App\Domain\Comms\ActiveAnnouncements;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The A14 announcement banner on member pages (P8.3a). Shows what ActiveAnnouncements gives this
 * member (window, audience, not dismissed); a live `.announcement.published` on the private
 * `announcements` channel only says "re-read" — its code is never trusted for content. After a
 * reconnect it re-reads too (§9.7). Dismiss → DismissAnnouncement (dismissible ones only).
 */
final class AnnouncementBanner extends Component
{
    /** @return array<string, string> */
    public function getListeners(): array
    {
        if ($this->member() === null || app(Impersonation::class)->isActive()) {
            return [];
        }

        return [
            'echo-private:announcements,.announcement.published' => '$refresh',
            'realtime-reconnected' => '$refresh',
        ];
    }

    public function dismiss(string $code, DismissAnnouncement $dismiss): void
    {
        $member = $this->member();
        if ($member === null) {
            return;
        }

        try {
            $dismiss->handle($member, $code);
        } catch (ImpersonationRestricted|NotFoundHttpException) {
            // Already gone, not dismissible, or a support session: nothing to change, just re-read.
        }
    }

    public function render(ActiveAnnouncements $active): View
    {
        $member = $this->member();

        return view('livewire.shared.announcement-banner', [
            'announcements' => $member !== null ? $active->forMember($member) : collect(),
        ]);
    }

    private function member(): ?User
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User && $user->isActive() ? $user : null;
    }
}
