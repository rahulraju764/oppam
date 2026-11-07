<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Data\Content\SeoData;
use App\Enums\LegalKind;
use App\Models\LegalDocument;
use App\Queries\Content\LegalVersions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /terms, /privacy, /grievance (M12, A08): the live version, its effective date and version number,
 * and a "not legally reviewed" banner until counsel signs it off. Before any version is published the
 * reference copy (resources/views/legal/reference) is shown. In a signed preview: the previewed one.
 */
final class LegalPage extends Component
{
    #[Locked]
    public string $kind = '';

    public function mount(?string $kind = null, ?string $code = null): void
    {
        if ($code !== null) {
            // Signed preview: the route only reaches here for a legal row (EnableContentPreview).
            $previewed = LegalDocument::query()->where('code', $code)->firstOrFail();
            $this->kind = $previewed->kind->value;

            return;
        }

        abort_if(LegalKind::tryFrom((string) $kind) === null, 404);
        $this->kind = (string) $kind;
    }

    public function render(LegalVersions $versions): View
    {
        $kind = LegalKind::from($this->kind);
        $document = $versions->live($kind);

        return view('livewire.public.legal-page', [
            'legalKind' => $kind,
            'document' => $document,
            'reference' => 'legal.reference.'.strtolower($kind->value),
            'timezone' => (string) config('oppam.display_timezone'),
        ])->layout('layouts::public', ['seo' => $this->seo($kind)]);
    }

    private function seo(LegalKind $kind): SeoData
    {
        return match ($kind) {
            LegalKind::Terms => new SeoData(
                title: 'Terms of Use | Oppam Matrimony',
                description: 'The terms that govern your use of Oppam Matrimony: accounts, profiles, conduct, membership, payments and refunds.',
                keywords: 'Oppam Matrimony Terms, Matrimony Terms of Use Kerala',
            ),
            LegalKind::Privacy => new SeoData(
                title: 'Privacy Policy | Oppam Matrimony',
                description: 'How Oppam Matrimony collects, uses, shares and protects your personal data, and the rights you have over it.',
                keywords: 'Oppam Matrimony Privacy Policy, Matrimony Data Protection, DPDP Act',
            ),
            LegalKind::Grievance => new SeoData(
                title: 'Grievance Redressal | Oppam Matrimony',
                description: 'How to raise a complaint with the Oppam Matrimony Grievance Officer, and how quickly we act on it.',
                keywords: 'Oppam Matrimony grievance officer, complaint, IT Rules 2021',
            ),
        };
    }
}
