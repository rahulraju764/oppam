<?php

declare(strict_types=1);

namespace App\Queries\Engagement;

use App\Data\Search\SearchCriteria;
use App\Enums\AccessRequestStatus;
use App\Enums\InterestStatus;
use App\Models\Profile;
use App\Services\Profile\ProfileSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * The member's interest lists for /interests (PRD M06, template interest.php): two boxes —
 * Received and Sent — each filtered by status (all / pending / accepted / declined / expired),
 * plus the photo / horoscope requests waiting for them. Read through ProfileSearch, so a profile
 * in a blocked pair, ignored, incognito or no longer ACTIVE is never listed nor counted. Always
 * for ONE member: every query is scoped to the profile passed in.
 *
 * A WITHDRAWN interest stays in the sender's history only (under "expired"); the member it was
 * sent to simply stops seeing it.
 *
 * Each row is the OTHER member's profile plus `interest_status`, `interest_message`,
 * `interest_at` (when it was sent).
 */
final class InterestLists
{
    public const RECEIVED = 'received';

    public const SENT = 'sent';

    public const REQUESTS = 'requests';

    public const BOXES = [self::RECEIVED, self::SENT];

    public const STATUSES = ['all', 'pending', 'accepted', 'declined', 'expired'];

    public function __construct(private readonly ProfileSearch $search) {}

    /** @return Builder<Profile> */
    public function box(Profile $me, string $box, string $status = 'all'): Builder
    {
        $sent = $box === self::SENT;

        return $this->joined($me, $sent)
            ->whereIn('interests.status', $this->statuses($sent, $status))
            ->addSelect(['interests.status as interest_status', 'interests.message as interest_message', 'interests.sent_at as interest_at'])
            ->orderByDesc('interests.sent_at')
            ->orderByDesc('interests.id');
    }

    /**
     * Rows per box and status, in two grouped queries.
     *
     * @return array<string, array<string, int>> box => status => count
     */
    public function counts(Profile $me): array
    {
        $counts = [];

        foreach (self::BOXES as $box) {
            $sent = $box === self::SENT;
            $byStatus = $this->joined($me, $sent)->toBase()
                ->select('interests.status')->selectRaw('count(*) as total')
                ->groupBy('interests.status')->pluck('total', 'status');

            foreach (self::STATUSES as $status) {
                $counts[$box][$status] = (int) collect($this->statuses($sent, $status))->sum(fn (string $s): int => (int) ($byStatus[$s] ?? 0));
            }
        }

        return $counts;
    }

    /**
     * Members waiting for my answer to a photo / horoscope request. Extra columns:
     * `request_type`, `requested_at`.
     *
     * @return Builder<Profile>
     */
    public function requests(Profile $me): Builder
    {
        return $this->search->query($me, new SearchCriteria)
            ->join('access_requests', fn (JoinClause $join) => $join->on('access_requests.requester_profile_id', '=', 'profiles.id')
                ->where('access_requests.owner_profile_id', '=', $me->id)
                ->where('access_requests.status', '=', AccessRequestStatus::Pending->value))
            ->addSelect(['access_requests.type as request_type', 'access_requests.created_at as requested_at'])
            ->orderByDesc('access_requests.created_at')
            ->orderByDesc('access_requests.id');
    }

    /**
     * The interest statuses a filter covers.
     *
     * @return list<string>
     */
    private function statuses(bool $sent, string $status): array
    {
        $expired = $sent
            ? [InterestStatus::Expired->value, InterestStatus::Withdrawn->value]
            : [InterestStatus::Expired->value];

        return match ($status) {
            'pending' => [InterestStatus::Pending->value],
            'accepted' => [InterestStatus::Accepted->value],
            'declined' => [InterestStatus::Declined->value],
            'expired' => $expired,
            default => [InterestStatus::Pending->value, InterestStatus::Accepted->value, InterestStatus::Declined->value, ...$expired],
        };
    }

    /**
     * Visible profiles with an interest from me (sent) or to me (received).
     *
     * @return Builder<Profile>
     */
    private function joined(Profile $me, bool $sent): Builder
    {
        return $this->search->query($me, new SearchCriteria)
            ->join('interests', fn (JoinClause $join) => $join
                ->on($sent ? 'interests.to_profile_id' : 'interests.from_profile_id', '=', 'profiles.id')
                ->where($sent ? 'interests.from_profile_id' : 'interests.to_profile_id', '=', $me->id));
    }
}
