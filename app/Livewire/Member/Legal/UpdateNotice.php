<?php

declare(strict_types=1);

namespace App\Livewire\Member\Legal;

use App\Actions\Content\RecordLegalAcceptance;
use App\Enums\LegalAcceptanceContext;
use App\Enums\LegalKind;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Http\Middleware\EnsureLegalTermsAccepted;
use App\Models\User;
use App\Queries\Content\LegalVersions;
use App\Services\Admin\Impersonation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The "we updated our terms" banner in the member and bureau layouts (A08): a non-material newer
 * version. "Got it" records the member read it. Costs no query once the session has checked the
 * current versions (EnsureLegalTermsAccepted); hidden in an admin support session.
 */
final class UpdateNotice extends Component
{
    /** @var list<string> */
    #[Locked]
    public array $kinds = [];

    public function mount(LegalVersions $versions, Impersonation $impersonation): void
    {
        $user = auth('web')->user();
        if (! $user instanceof User || $impersonation->isActive()) {
            return;
        }

        $fingerprint = EnsureLegalTermsAccepted::fingerprint($versions->current(), $user->id);
        if ($fingerprint === null || session(EnsureLegalTermsAccepted::SESSION_KEY) === $fingerprint) {
            return;
        }

        $this->kinds = array_map(fn (LegalKind $kind): string => $kind->value, $versions->pendingFor($user)->notices);
    }

    public function dismiss(RecordLegalAcceptance $record): void
    {
        /** @var User $user */
        $user = auth('web')->user();
        $kinds = array_values(array_filter(array_map(fn (string $kind): ?LegalKind => LegalKind::tryFrom($kind), $this->kinds)));

        try {
            $record->handle($user, LegalAcceptanceContext::Notice, request()->ip(), request()->userAgent(), $kinds);
        } catch (ImpersonationRestricted) {
            return;
        }

        session()->forget(EnsureLegalTermsAccepted::SESSION_KEY);
        $this->kinds = [];
    }

    public function render(): View
    {
        return view('livewire.member.legal.update-notice', [
            'labels' => array_map(fn (string $kind): LegalKind => LegalKind::from($kind), $this->kinds),
        ]);
    }
}
