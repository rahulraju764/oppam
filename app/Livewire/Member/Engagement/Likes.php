<?php

declare(strict_types=1);

namespace App\Livewire\Member\Engagement;

use App\Actions\Engagement\MarkLikesSeen;
use App\Data\Content\SeoData;
use App\Domain\Profile\ProfileCards;
use App\Enums\Entitlement;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Engagement\EngagementLists;
use App\Services\Entitlements\EntitlementService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /likes (PRD M06, M15): three tabs — "Liked me" (the members for plans with see_who_liked_me;
 * otherwise only their number behind a data-free blurred teaser and an upgrade link), "I liked"
 * and "Mutual" (every plan). All read through EngagementLists, so blocked, ignored, incognito and
 * non-ACTIVE members are never listed nor counted. Likes the member hasn't seen yet carry a "new"
 * dot for this visit and are then marked seen. A new like (".like.changed"), a like made on this
 * page or a socket reconnect re-reads the page.
 */
final class Likes extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

    public const TABS = ['liked-me', 'liked', 'mutual'];

    /** At most this many "new" dots are tracked per visit. */
    private const NEW_LIMIT = 100;

    #[Url]
    public string $tab = 'liked-me';

    /** @var list<string> profile codes whose like was new when this visit read it */
    #[Locked]
    public array $newCodes = [];

    public function mount(): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'liked-me';
        $this->collectNew();
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:App.Models.User.'.$this->member()->id.',.like.changed' => 'refreshLikes',
            'realtime-reconnected' => 'refreshLikes',   // back-fill anything missed while offline (PRD §9.7)
            'like-changed' => '$refresh',               // a like made or taken back on this page
        ];
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'liked-me';
        $this->resetPage();
        $this->collectNew();
    }

    public function refreshLikes(): void
    {
        $this->collectNew();
    }

    public function render(EngagementLists $lists, ProfileCards $cards, EntitlementService $entitlements): View
    {
        $member = $this->member();
        $me = $member->profile ?? abort(404);
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'liked-me';
        $locked = $tab === 'liked-me' && ! $entitlements->allows($me, Entitlement::SeeWhoLikedMe);

        $list = fn (string $which): Builder => match ($which) {
            'liked' => $lists->likedByMe($me),
            'mutual' => $lists->mutual($me),
            default => $lists->likedMe($me),
        };
        $page = $locked ? null : $list($tab)->with('privacySetting')->paginate(self::PER_PAGE);

        // The page the member was on was just emptied (an un-like on "I liked"): back to the first.
        if ($page !== null && $page->isEmpty() && $page->currentPage() > 1) {
            $this->resetPage();
            $page = $list($tab)->with('privacySetting')->paginate(self::PER_PAGE);
        }

        $rows = [];
        if ($page !== null) {
            $profiles = collect($page->items());
            $cardData = $cards->forViewers($profiles, $member);
            foreach ($profiles->values() as $i => $profile) {
                /** @var Profile $profile */
                $rows[] = [
                    'card' => $cardData[$i],
                    'when' => $this->when($profile->getAttribute('liked_at')),
                    'new' => $tab === 'liked-me' && in_array($profile->code, $this->newCodes, true),
                ];
            }
        }

        return view('livewire.member.engagement.likes', [
            'tab' => $tab,
            'locked' => $locked,
            'rows' => $rows,
            'page' => $page,
            // The open tab's number is the paginator's own total — no second count for it.
            'counts' => collect(self::TABS)->mapWithKeys(fn (string $which): array => [
                $which => $page !== null && $which === $tab ? $page->total() : $list($which)->count(),
            ])->all(),
        ])->layout('layouts::member', [
            'seo' => SeoData::private('Likes | Oppam Matrimony'),
        ]);
    }

    /**
     * Remember which likes are new for this visit, then mark them seen (M06 `likes.seen_at`).
     * Only on "Liked me" and only for a plan that shows who liked: a Free member sees no list, so
     * nothing is "seen".
     */
    private function collectNew(): void
    {
        $member = $this->member();
        $me = $member->profile;

        if ($me === null || $this->tab !== 'liked-me' || ! app(EntitlementService::class)->allows($me, Entitlement::SeeWhoLikedMe)) {
            return;
        }

        $fresh = app(EngagementLists::class)->likedMe($me)->whereNull('likes.seen_at')->limit(self::NEW_LIMIT)->pluck('profiles.code')->all();
        $this->newCodes = array_values(array_unique([...$this->newCodes, ...array_map(strval(...), $fresh)]));

        if ($fresh !== []) {
            app(MarkLikesSeen::class)->handle($member);
        }
    }

    private function when(mixed $at): string
    {
        return is_string($at) || $at instanceof DateTimeInterface
            ? CarbonImmutable::parse($at)->setTimezone((string) config('oppam.display_timezone'))->diffForHumans()
            : '';
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
