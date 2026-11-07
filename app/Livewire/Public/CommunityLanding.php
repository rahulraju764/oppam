<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Data\Content\SeoData;
use App\Enums\ContentType;
use App\Enums\ProfileStatus;
use App\Models\CommunityPage;
use App\Models\Profile;
use App\Queries\Content\PreviewContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A community landing page (M12 SEO, A08), e.g. /nair-matrimony: the page's introduction, a rounded
 * member count for its religion / caste and a way in (register, or search when signed in). It never
 * shows a profile: guests can't see members (PRD §6.2). Small communities show no count at all.
 */
final class CommunityLanding extends Component
{
    /** Below this many active members no count is shown (a tiny number would point at people). */
    public const MIN_SHOWN = 50;

    #[Locked]
    public string $code = '';

    private PreviewContext $preview;

    public function boot(PreviewContext $preview): void
    {
        $this->preview = $preview;
    }

    public function mount(?string $community = null, ?string $code = null): void
    {
        $page = $code !== null
            ? CommunityPage::query()->where('code', $code)->firstOrFail()
            : CommunityPage::query()->live()->where('slug', (string) $community)->firstOrFail();

        $this->code = $page->code;
    }

    public function render(): View
    {
        $stored = CommunityPage::query()->where('code', $this->code)->firstOrFail();
        $previewing = $this->preview->codeFor(ContentType::Community) === $stored->code;
        // Only a live page, or the one row a signed preview shows (with its waiting edits).
        abort_unless($stored->isLive() || $previewing, 404);
        $page = $previewing ? $stored->withDraft() : $stored;

        return view('livewire.public.community-landing', [
            'page' => $page,
            'members' => $this->members($page),
            'signedIn' => auth('web')->check(),
        ])->layout('layouts::public', ['seo' => new SeoData(
            title: $page->heading.' | Oppam Matrimony',
            description: $page->meta_description,
            keywords: $page->heading.', Kerala matrimony, Oppam Matrimony',
            ogTitle: $page->heading,
        )]);
    }

    /** Active members of the page's religion / caste, rounded down to a 50; null when too few or no filter. */
    private function members(CommunityPage $page): ?int
    {
        if ($page->religion_id === null) {
            return null;
        }

        $count = Cache::remember('community:members:'.$page->religion_id.':'.($page->caste_id ?? 0), 3600, fn (): int => Profile::query()
            ->where('status', ProfileStatus::Active)
            ->where('religion_id', $page->religion_id)
            ->when($page->caste_id !== null, fn ($q) => $q->where('caste_id', $page->caste_id))
            ->count());

        return $count < self::MIN_SHOWN ? null : intdiv($count, self::MIN_SHOWN) * self::MIN_SHOWN;
    }
}
