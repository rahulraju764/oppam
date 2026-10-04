<?php

declare(strict_types=1);

namespace App\Livewire\Member\Dashboard;

use App\Domain\Activity\ProfileVisitors;
use App\Domain\Matching\DailyBatch;
use App\Domain\Matching\MatchFunnel;
use App\Domain\Profile\ProfileCards;
use App\Enums\Entitlement;
use App\Enums\MatchTab;
use App\Models\Profile;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Profile\ProfileSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One dashboard slider (M05), loaded after the page with a skeleton (#[Lazy]): today's daily
 * batch, new / mutual / premium matches (MatchFunnel tabs) or "recently viewed you" — the
 * visitors' cards for plans with see_who_viewed_me, otherwise only their number behind an upgrade
 * prompt (no visitor data reaches the page). Everything is read for the signed-in member.
 */
#[Lazy(isolate: false)] // the five strips load together in ONE request after the page
final class Strip extends Component
{
    public const KINDS = ['daily', 'new', 'mutual', 'premium', 'visitors'];

    private const SIZE = 10;

    #[Locked]
    public string $kind = 'daily';

    public function mount(string $kind): void
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $this->kind = $kind;
    }

    public function placeholder(): View
    {
        return view('livewire.member.dashboard.strip-placeholder');
    }

    public function render(ProfileCards $cards, EntitlementService $entitlements, ProfileVisitors $visitors): View
    {
        $member = $this->member();
        $me = $member->profile ?? abort(404);

        $locked = $this->kind === 'visitors' && ! $entitlements->allows($me, Entitlement::SeeWhoViewedMe);
        $profiles = $locked ? collect() : $this->profiles($me, $visitors);

        return view('livewire.member.dashboard.strip', [
            'cards' => $cards->forViewers($profiles, $member),
            'locked' => $locked,
            'visitorCount' => $locked ? $visitors->count($me, 30) : 0,
            'expiresIn' => $this->kind === 'daily' ? (int) now()->diffInSeconds(DailyBatch::expiresAt(), true) : 0,
            ...$this->copy(),
        ]);
    }

    /** @return Collection<int, Profile> */
    private function profiles(Profile $me, ProfileVisitors $visitors): Collection
    {
        if ($this->kind === 'daily') {
            return app(DailyBatch::class)->profiles($me, self::SIZE);
        }

        if ($this->kind === 'visitors') {
            return $visitors->query($me, 30)->with('privacySetting')->limit(self::SIZE)->get();
        }

        $tab = match ($this->kind) {
            'mutual' => MatchTab::Mutual,
            'premium' => MatchTab::Premium,
            default => MatchTab::New,
        };
        $criteria = app(MatchFunnel::class)->criteria($me, $tab);

        return $criteria === null ? collect() : app(ProfileSearch::class)->page($me, $criteria)->profiles->take(self::SIZE);
    }

    /** @return array{title: string, subtitle: string, empty: string, viewAll: string|null} */
    private function copy(): array
    {
        return match ($this->kind) {
            'daily' => ['title' => __('Daily Recommendations'), 'subtitle' => __('Recommended matches for today'),
                'empty' => __('Your daily matches arrive every morning at 5 AM.'), 'viewAll' => route('member.matches.daily')],
            'new' => ['title' => __('New Matches'), 'subtitle' => __('Joined in the last 7 days'),
                'empty' => __('No new matches this week yet.'), 'viewAll' => route('member.matches', ['tab' => MatchTab::New->value])],
            'mutual' => ['title' => __('Mutual Matches'), 'subtitle' => __('You match each other’s preferences'),
                'empty' => __('No mutual matches yet.'), 'viewAll' => route('member.matches', ['tab' => MatchTab::Mutual->value])],
            'premium' => ['title' => __('Premium Members'), 'subtitle' => __('Members with a paid plan'),
                'empty' => __('No premium members match your preferences yet.'), 'viewAll' => route('member.matches', ['tab' => MatchTab::Premium->value])],
            default => ['title' => __('Recently Viewed You'), 'subtitle' => __('Members who opened your profile'),
                'empty' => __('Nobody has viewed your profile in the last 30 days.'), 'viewAll' => route('member.visitors')],
        };
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
