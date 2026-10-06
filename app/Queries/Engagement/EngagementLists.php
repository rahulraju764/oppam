<?php

declare(strict_types=1);

namespace App\Queries\Engagement;

use App\Data\Search\SearchCriteria;
use App\Models\Favorite;
use App\Models\Like;
use App\Models\Profile;
use App\Services\Profile\ProfileSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * The member's like and favourite lists (PRD M06 `/likes`, `/favorites`, M15), newest first. Every
 * list is read through ProfileSearch, so a profile in a blocked pair, ignored, incognito or no
 * longer ACTIVE is never listed nor counted — exactly like search. Always for ONE member: every
 * query is scoped to the profile passed in.
 */
final class EngagementLists
{
    public function __construct(private readonly ProfileSearch $search) {}

    /**
     * Members who liked me. Extra columns: `liked_at`, `like_seen_at` (null = new to me).
     *
     * @return Builder<Profile>
     */
    public function likedMe(Profile $me): Builder
    {
        return $this->search->query($me, new SearchCriteria)
            ->join('likes', fn (JoinClause $join) => $join->on('likes.from_profile_id', '=', 'profiles.id')
                ->where('likes.to_profile_id', '=', $me->id))
            ->addSelect(['likes.created_at as liked_at', 'likes.seen_at as like_seen_at'])
            ->orderByDesc('likes.created_at')
            ->orderByDesc('profiles.id');
    }

    /**
     * Members I liked. Extra column: `liked_at`.
     *
     * @return Builder<Profile>
     */
    public function likedByMe(Profile $me): Builder
    {
        return $this->search->query($me, new SearchCriteria)
            ->join('likes', fn (JoinClause $join) => $join->on('likes.to_profile_id', '=', 'profiles.id')
                ->where('likes.from_profile_id', '=', $me->id))
            ->addSelect(['likes.created_at as liked_at'])
            ->orderByDesc('likes.created_at')
            ->orderByDesc('profiles.id');
    }

    /**
     * Members who liked me and whom I liked back.
     *
     * @return Builder<Profile>
     */
    public function mutual(Profile $me): Builder
    {
        return $this->likedMe($me)
            ->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('likes as mine')
                ->whereColumn('mine.to_profile_id', 'profiles.id')
                ->where('mine.from_profile_id', $me->id));
    }

    /**
     * My private bookmarks. Extra columns: `favorite_note`, `favorited_at`.
     *
     * @return Builder<Profile>
     */
    public function favorites(Profile $me): Builder
    {
        return $this->search->query($me, new SearchCriteria)
            ->join('favorites', fn (JoinClause $join) => $join->on('favorites.favorite_profile_id', '=', 'profiles.id')
                ->where('favorites.profile_id', '=', $me->id))
            ->addSelect(['favorites.note as favorite_note', 'favorites.created_at as favorited_at'])
            ->orderByDesc('favorites.created_at')
            ->orderByDesc('profiles.id');
    }

    /** Bookmarks that hold a slot but can't be listed any more (profile hidden, suspended, deleted, blocked …). */
    public function unavailableFavoritesCount(Profile $me): int
    {
        return max(0, Favorite::query()->where('profile_id', $me->id)->count() - $this->favorites($me)->count());
    }

    /** Visible members who liked me and whose like I haven't seen yet. */
    public function newLikesCount(Profile $me): int
    {
        return $this->likedMe($me)->whereNull('likes.seen_at')->count();
    }

    /**
     * Which of these profiles the member already likes / has bookmarked — two queries for a whole
     * page of cards, so the buttons need none of their own.
     *
     * @param  list<string>  $profileIds
     * @return array{liked: array<string, true>, favorited: array<string, true>} keyed by profile id
     */
    public function stateFor(Profile $me, array $profileIds): array
    {
        if ($profileIds === []) {
            return ['liked' => [], 'favorited' => []];
        }

        $liked = Like::query()->where('from_profile_id', $me->id)->whereIn('to_profile_id', $profileIds)->pluck('to_profile_id');
        $favorited = Favorite::query()->where('profile_id', $me->id)->whereIn('favorite_profile_id', $profileIds)->pluck('favorite_profile_id');

        return [
            'liked' => array_fill_keys($liked->map(fn (mixed $id): string => (string) $id)->all(), true),
            'favorited' => array_fill_keys($favorited->map(fn (mixed $id): string => (string) $id)->all(), true),
        ];
    }
}
