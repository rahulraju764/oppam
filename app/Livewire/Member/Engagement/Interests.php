<?php

declare(strict_types=1);

namespace App\Livewire\Member\Engagement;

use App\Actions\Engagement\AcceptInterest;
use App\Actions\Engagement\DeclineInterest;
use App\Actions\Engagement\RespondToAccessRequest;
use App\Actions\Engagement\WithdrawInterest;
use App\Data\Content\SeoData;
use App\Domain\Engagement\Connections;
use App\Domain\Profile\ProfileCards;
use App\Enums\AccessRequestType;
use App\Enums\InterestStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Engagement\InterestLists;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /interests (PRD M06, template interest.php — 3/6/3: filters | list | ads): interests Received
 * and Sent, each by status (all / pending / accepted / declined / expired), and the photo /
 * horoscope requests waiting for the member. Accept / Decline inline on a pending received
 * interest, "decline selected" for several, Withdraw on a pending sent one, Approve / Decline on
 * a request. Lists come from InterestLists (blocked / hidden members never listed); every change
 * goes through an Action that re-checks who may do it. A live change (".interest.changed"), a
 * change made on this page or a socket reconnect re-reads the page.
 */
final class Interests extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

    #[Url]
    public string $box = InterestLists::RECEIVED;

    #[Url]
    public string $status = 'pending';

    /** @var list<string> profile codes ticked for "decline selected" (re-checked by the Action) */
    public array $selected = [];

    public function mount(): void
    {
        $this->normalise();
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:App.Models.User.'.$this->member()->id.',.interest.changed' => '$refresh',
            'realtime-reconnected' => '$refresh',   // back-fill anything missed while offline (PRD §9.7)
        ];
    }

    public function show(string $box, string $status = 'all'): void
    {
        $this->box = $box;
        $this->status = $status;
        $this->normalise();
        $this->selected = [];
        $this->resetPage();
    }

    public function accept(string $code, AcceptInterest $accept): void
    {
        $this->change($code, fn (Profile $other) => $accept->handle($this->member(), $other), __('You are now connected.'), Connections::CONNECTED);
    }

    public function decline(string $code, DeclineInterest $decline): void
    {
        $this->change($code, fn (Profile $other) => $decline->handle($this->member(), $other), __('Interest declined.'), Connections::NONE);
    }

    public function withdraw(string $code, WithdrawInterest $withdraw): void
    {
        $this->change($code, fn (Profile $other) => $withdraw->handle($this->member(), $other), __('Interest withdrawn.'), Connections::NONE);
    }

    public function declineSelected(DeclineInterest $decline): void
    {
        try {
            $declined = $decline->many($this->member(), $this->selected);
        } catch (ImpersonationRestricted $restricted) {
            $this->dispatch('toast', title: $restricted->getMessage(), type: 'danger');

            return;
        }

        $this->selected = [];

        if ($declined > 0) {
            $this->dispatch('toast', type: 'success', title: trans_choice(':count interest declined.|:count interests declined.', $declined, ['count' => $declined]));
        }
    }

    public function answerRequest(string $code, string $type, bool $approve, RespondToAccessRequest $respond): void
    {
        $kind = AccessRequestType::tryFrom($type);

        if ($kind === null) {
            return;
        }

        $this->change($code, fn (Profile $other) => $respond->handle($this->member(), $other, $kind, $approve),
            $approve ? __('Request approved.') : __('Request declined.'), null);
    }

    public function render(InterestLists $lists, ProfileCards $cards): View
    {
        $member = $this->member();
        $me = $member->profile ?? abort(404);
        $this->normalise();
        $requests = $this->box === InterestLists::REQUESTS;

        $page = $this->page($lists, $me, $requests);
        $profiles = collect($page->items());
        $cardData = $cards->forViewers($profiles, $member);

        $rows = [];
        foreach ($profiles->values() as $i => $profile) {
            /** @var Profile $profile */
            $rows[] = $requests
                ? ['card' => $cardData[$i], 'type' => AccessRequestType::from((string) $profile->getAttribute('request_type')),
                    'when' => $this->when($profile->getAttribute('requested_at'))]
                : ['card' => $cardData[$i], 'status' => InterestStatus::from((string) $profile->getAttribute('interest_status')),
                    'message' => (string) ($profile->getAttribute('interest_message') ?? ''),
                    'when' => $this->when($profile->getAttribute('interest_at'))];
        }

        return view('livewire.member.engagement.interests', [
            'rows' => $rows,
            'page' => $page,
            'requests' => $requests,
            'counts' => $lists->counts($me),
            'requestCount' => $requests ? $page->total() : $lists->requests($me)->count(),
            'statusLabels' => ['all' => __('All'), 'pending' => __('Pending'), 'accepted' => __('Accepted'), 'declined' => __('Declined'), 'expired' => __('Expired')],
        ])->layout('layouts::member', [
            'seo' => SeoData::private('Interests | Oppam Matrimony'),
        ]);
    }

    /** @return LengthAwarePaginator<int, Profile> */
    private function page(InterestLists $lists, Profile $me, bool $requests): LengthAwarePaginator
    {
        $query = fn () => ($requests ? $lists->requests($me) : $lists->box($me, $this->box, $this->status))->with('privacySetting');
        $page = $query()->paginate(self::PER_PAGE);

        // The page the member was on was just emptied (the last row answered): back to the first.
        if ($page->isEmpty() && $page->currentPage() > 1) {
            $this->resetPage();
            $page = $query()->paginate(self::PER_PAGE);
        }

        return $page;
    }

    /**
     * Run one change on the interest / request with the profile of that code; a refusal becomes a toast.
     *
     * @param  callable(Profile): mixed  $change
     */
    private function change(string $code, callable $change, string $done, ?string $state): void
    {
        $other = preg_match('/^OPM\d{1,10}$/', $code) === 1 ? Profile::withTrashed()->where('code', $code)->first() : null;

        try {
            $change($other ?? throw new NotFoundHttpException);
        } catch (EngagementRefused|ImpersonationRestricted $refused) {
            $this->dispatch('toast', title: $refused->getMessage(), type: 'danger');

            return;
        } catch (NotFoundHttpException) {
            $this->dispatch('toast', title: __('This is no longer available.'), type: 'danger');

            return;
        }

        $this->selected = array_values(array_filter($this->selected, fn (mixed $ticked): bool => $ticked !== $code));
        $this->dispatch('toast', title: $done, type: 'success');

        if ($state !== null) {
            $this->dispatch('interest-changed', code: $code, state: $state);
        }
    }

    private function normalise(): void
    {
        if (! in_array($this->box, [...InterestLists::BOXES, InterestLists::REQUESTS], true)) {
            $this->box = InterestLists::RECEIVED;
        }

        if (! in_array($this->status, InterestLists::STATUSES, true)) {
            $this->status = 'all';
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
