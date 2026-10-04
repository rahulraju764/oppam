<?php

declare(strict_types=1);

namespace App\Livewire\Member\Matches;

use App\Actions\Matching\DismissDailyMatch;
use App\Data\Content\SeoData;
use App\Data\Profile\ProfileCardData;
use App\Domain\Matching\DailyBatch;
use App\Domain\Profile\ProfileCards;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\User;
use App\Support\Navigation\ProfileBrowseList;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Daily Matches (M05 / F06, template daily-matches.php): today's batch, best first, with the
 * countdown to its 23:59 IST expiry. Reading only — the batch is made by GenerateDailyMatches at
 * 05:00 (and right after approval); "Not interested" dismisses one by its profile code.
 */
final class Daily extends Component
{
    public ?string $notice = null;

    public function dismiss(string $code, DismissDailyMatch $dismiss): void
    {
        try {
            $dismiss->handle($this->member(), $code);
        } catch (ImpersonationRestricted $restricted) {
            $this->notice = $restricted->getMessage();

            return;
        }

        $this->notice = __('Removed from today’s matches.');
    }

    public function render(DailyBatch $batch, ProfileCards $cards): View
    {
        $member = $this->member();
        $profiles = $batch->profiles($member->profile ?? abort(404));
        $cardData = $cards->forViewers($profiles, $member);

        // Prev / Next on a profile opened from today's list (M03).
        app(ProfileBrowseList::class)->remember(array_map(fn (ProfileCardData $c): string => $c->code, $cardData));

        return view('livewire.member.matches.daily', [
            'cards' => $cardData,
            'expiresIn' => (int) now()->diffInSeconds(DailyBatch::expiresAt(), true),
        ])->layout('layouts::member', [
            'seo' => SeoData::private('Daily Matches | Oppam Matrimony'),
        ]);
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
