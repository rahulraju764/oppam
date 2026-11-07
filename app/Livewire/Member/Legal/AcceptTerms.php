<?php

declare(strict_types=1);

namespace App\Livewire\Member\Legal;

use App\Actions\Content\RecordLegalAcceptance;
use App\Data\Content\SeoData;
use App\Enums\LegalAcceptanceContext;
use App\Enums\LegalKind;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Http\Middleware\EnsureLegalTermsAccepted;
use App\Models\User;
use App\Queries\Content\LegalVersions;
use App\Support\Navigation\MemberLanding;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * /legal/accept (A08 forced re-acceptance, owner 2026-11-06): the blocking page a member sees after
 * a material change to the terms or privacy policy. They agree, or sign out. Which versions are
 * agreed to is the server's (RecordLegalAcceptance records what is live).
 */
final class AcceptTerms extends Component
{
    public bool $agree = false;

    public function mount(LegalVersions $versions): mixed
    {
        if (! $versions->pendingFor($this->member())->blocks()) {
            return redirect()->to($this->next());
        }

        return null;
    }

    public function accept(RecordLegalAcceptance $record): mixed
    {
        $this->validate(['agree' => ['accepted']], ['agree.accepted' => __('Tick the box to agree to the updated terms.')]);

        try {
            $record->handle($this->member(), LegalAcceptanceContext::Required, request()->ip(), request()->userAgent());
        } catch (ImpersonationRestricted) {
            $this->dispatch('toast', type: 'danger', title: __('Only the member can agree to the terms.'));

            return null;
        }

        session()->forget(EnsureLegalTermsAccepted::SESSION_KEY);

        return redirect()->to($this->next());
    }

    public function render(LegalVersions $versions): View
    {
        $pending = $versions->pendingFor($this->member());

        return view('livewire.member.legal.accept-terms', [
            'kinds' => $pending->required === [] ? LegalKind::accepted() : $pending->required,
            'documents' => collect($pending->required)->mapWithKeys(fn (LegalKind $kind): array => [$kind->value => $versions->live($kind)]),
        ])->layout('layouts::public', ['seo' => SeoData::private(__('Updated terms').' | Oppam Matrimony')]);
    }

    private function next(): string
    {
        $intended = session()->pull('url.intended');

        return is_string($intended) && str_starts_with($intended, url('/')) ? $intended : app(MemberLanding::class)->url($this->member());
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
