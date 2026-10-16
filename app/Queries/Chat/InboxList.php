<?php

declare(strict_types=1);

namespace App\Queries\Chat;

use App\Data\Chat\ConversationItemData;
use App\Domain\Chat\MessageText;
use App\Domain\Media\PhotoUrls;
use App\Domain\Profile\ProfileNames;
use App\Domain\Safety\BlockList;
use App\Enums\ConversationStatus;
use App\Enums\MessageType;
use App\Models\ConversationParticipant;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The member's conversation list (PRD M07 inbox): newest activity first, filters All (not
 * archived) / Unread / Archived, search by the other member's name. Only the member's own threads
 * (their participant rows), never CLOSED ones, never a thread with a member they may not see
 * (block either way). One query for the rows + one for the other members + one for their photos
 * (PhotoUrls batch; ProfileNames: connected members see full names). In an admin support session
 * the previews are left out (message text is private — owner decision 2026-10-15).
 */
final class InboxList
{
    public const ALL = 'all';

    public const UNREAD = 'unread';

    public const ARCHIVED = 'archived';

    public const FILTERS = [self::ALL, self::UNREAD, self::ARCHIVED];

    public function __construct(
        private readonly BlockList $blocks,
        private readonly PhotoUrls $photos,
        private readonly ProfileNames $names,
    ) {}

    /** @return array{items: list<ConversationItemData>, more: bool} */
    public function page(User $viewer, Profile $me, string $filter, string $search, int $limit, bool $hidePreviews = false): array
    {
        $rows = $this->query($me, $filter, $search)->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $others = Profile::withTrashed()->with('privacySetting')
            ->whereKey($rows->map(fn (ConversationParticipant $row): string => (string) $row->getAttribute('other_id'))->all())
            ->get()->keyBy('id');
        $photos = $this->photos->primaryCardUrls($others->values(), $viewer);
        $timezone = (string) config('oppam.display_timezone');

        $items = [];
        foreach ($rows as $row) {
            $other = $others->get((string) $row->getAttribute('other_id'));
            if (! $other instanceof Profile) {
                continue;
            }

            $type = MessageType::tryFrom((string) $row->getAttribute('last_type'));
            $at = $row->getAttribute('last_message_at');

            $items[] = new ConversationItemData(
                code: $other->code,
                name: $this->names->forViewer($other, $viewer),
                photoUrl: $photos[(string) $other->id] ?? PhotoUrls::PLACEHOLDER,
                preview: $hidePreviews || $type === null ? null
                    : MessageText::preview($type, $row->getAttribute('last_body'), $row->getAttribute('last_deleted_at') !== null),
                lastFromMe: $row->getAttribute('last_sender') === $me->id,
                at: $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC')->setTimezone($timezone),
                unread: (int) $row->getAttribute('unread_count'),
                archived: (bool) $row->getAttribute('is_archived'),
                frozen: $row->getAttribute('thread_status') !== ConversationStatus::Open->value,
            );
        }

        return ['items' => $items, 'more' => $more];
    }

    /** Threads with unread messages (not archived) — the "N unread" chip. */
    public function unreadThreads(Profile $me): int
    {
        return $this->query($me, self::UNREAD, '')->count();
    }

    /** @return Builder<ConversationParticipant> */
    private function query(Profile $me, string $filter, string $search): Builder
    {
        $hidden = $this->blocks->hiddenFrom($me);
        $term = Str::of($search)->squish()->limit(40, '')->replace(['\\', '%', '_'], ['\\\\', '\%', '\_'])->value();

        return ConversationParticipant::query()
            ->from('conversation_participants as me')
            ->join('conversations as c', 'c.id', '=', 'me.conversation_id')
            ->join('conversation_participants as them', fn ($join) => $join->on('them.conversation_id', '=', 'me.conversation_id')
                ->whereColumn('them.profile_id', '!=', 'me.profile_id'))
            ->join('profiles as p', 'p.id', '=', 'them.profile_id')
            ->leftJoin('messages as m', 'm.id', '=', 'c.last_message_id')
            ->where('me.profile_id', $me->id)
            ->where('c.status', '!=', ConversationStatus::Closed->value)
            ->when($hidden !== [], fn ($q) => $q->whereNotIn('them.profile_id', $hidden))
            ->when($filter === self::ARCHIVED, fn ($q) => $q->where('me.is_archived', true), fn ($q) => $q->where('me.is_archived', false))
            ->when($filter === self::UNREAD, fn ($q) => $q->where('me.unread_count', '>', 0))
            ->when($term !== '', fn ($q) => $q->where(fn ($names) => $names->where('p.first_name', 'like', $term.'%')
                ->orWhere('p.last_name', 'like', $term.'%')))
            ->orderByDesc('c.last_message_at')->orderByDesc('c.id')
            ->select([
                'me.unread_count', 'me.is_archived', 'them.profile_id as other_id',
                'c.status as thread_status', 'c.last_message_at',
                'm.type as last_type', 'm.body as last_body', 'm.sender_profile_id as last_sender', 'm.deleted_for_all_at as last_deleted_at',
            ]);
    }
}
