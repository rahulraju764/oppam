<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Data\Admin\ChatOverviewData;
use App\Enums\MessageType;
use App\Enums\Metric;
use App\Enums\SettingKey;
use App\Enums\UserRole;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Profile;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The A13 conversation overview (PRD A13 "Conversation overview (metadata only)"): messages per day
 * (the A02 hourly snapshots), the top senders of the last 7 days, threads where one member wrote
 * `chat.one_sided_threshold` or more messages in a row without a reply (harassment signal), and new
 * accounts (younger than `signals.new_account_days`) writing in many conversations in the last
 * 24 hours. Counts and profile codes only, never text. Grouped queries on indexed windows, cached for
 * CACHE_MINUTES (the screen says "as of").
 */
final class ChatOverview
{
    public const CACHE_MINUTES = 10;

    private const LIMIT = 20;

    public function __construct(
        private readonly Cache $cache,
        private readonly DashboardMetrics $metrics,
    ) {}

    public function get(): ChatOverviewData
    {
        /** @var ChatOverviewData */
        return $this->cache->remember('admin.chat.overview.v1', now()->addMinutes(self::CACHE_MINUTES), fn (): ChatOverviewData => $this->build());
    }

    public function forget(): void
    {
        $this->cache->forget('admin.chat.overview.v1');
    }

    private function build(): ChatOverviewData
    {
        $threshold = Settings::int(SettingKey::ChatOneSidedThreshold);
        $newThreads = Settings::int(SettingKey::SignalsNewAccountThreadsPerDay);

        return new ChatOverviewData(
            perDay: $this->metrics->series(Metric::Messages),
            topSenders: $this->topSenders(),
            oneSided: $this->oneSided($threshold),
            newAccounts: $this->newAccounts($newThreads),
            asOf: CarbonImmutable::now(),
            oneSidedThreshold: $threshold,
            newAccountThreads: $newThreads,
        );
    }

    /** @return list<array{code: string, messages: int, threads: int}> */
    private function topSenders(): array
    {
        $rows = Message::query()->where('created_at', '>=', now()->subDays(7))
            ->where('type', '!=', MessageType::System->value)->whereNotNull('sender_profile_id')
            ->selectRaw('sender_profile_id, count(*) as messages, count(distinct conversation_id) as threads')
            ->groupBy('sender_profile_id')->orderByDesc('messages')->limit(self::LIMIT)->toBase()->get();
        $codes = $this->codes($rows->pluck('sender_profile_id')->all());

        return $rows->map(fn (object $row): array => [
            'code' => $codes[$row->sender_profile_id] ?? '—',
            'messages' => (int) $row->messages,
            'threads' => (int) $row->threads,
        ])->values()->all();
    }

    /** @return list<array{sender: string, recipient: string, unanswered: int, last_at: string|null}> */
    private function oneSided(int $threshold): array
    {
        $rows = ConversationParticipant::query()->where('unanswered_count', '>=', $threshold)
            ->orderByDesc('unanswered_count')->limit(self::LIMIT)
            ->with('conversation:id,last_message_at')->get(['id', 'conversation_id', 'profile_id', 'unanswered_count']);
        $others = ConversationParticipant::query()->whereIn('conversation_id', $rows->pluck('conversation_id')->all())
            ->get(['conversation_id', 'profile_id'])->groupBy('conversation_id');
        $codes = $this->codes($others->flatten()->pluck('profile_id')->all());
        $zone = (string) config('oppam.display_timezone');

        return $rows->map(fn (ConversationParticipant $row): array => [
            'sender' => $codes[$row->profile_id] ?? '—',
            'recipient' => $codes[(string) $others->get($row->conversation_id)?->firstWhere('profile_id', '!=', $row->profile_id)?->profile_id] ?? '—',
            'unanswered' => $row->unanswered_count,
            'last_at' => $row->conversation?->last_message_at?->setTimezone($zone)->format('d M, H:i'),
        ])->values()->all();
    }

    /** @return list<array{code: string, joined: string, threads: int}> */
    private function newAccounts(int $minThreads): array
    {
        $rows = Message::query()
            ->join('profiles', 'profiles.id', '=', 'messages.sender_profile_id')
            ->join('users', 'users.id', '=', 'profiles.user_id')
            ->where('messages.created_at', '>=', now()->subDay())
            ->where('messages.type', '!=', MessageType::System->value)
            ->where('users.role', UserRole::Member->value)
            ->where('users.created_at', '>=', now()->subDays(Settings::int(SettingKey::SignalsNewAccountDays)))
            ->selectRaw('profiles.code as code, users.created_at as joined, count(distinct messages.conversation_id) as threads')
            ->groupBy('profiles.code', 'users.created_at')
            ->having('threads', '>=', $minThreads)
            ->orderByDesc('threads')->limit(self::LIMIT)->toBase()->get();
        $zone = (string) config('oppam.display_timezone');

        return $rows->map(fn (object $row): array => [
            'code' => (string) $row->code,
            'joined' => CarbonImmutable::parse((string) $row->joined)->setTimezone($zone)->format('d M Y'),
            'threads' => (int) $row->threads,
        ])->values()->all();
    }

    /**
     * @param  list<mixed>  $ids
     * @return array<string, string> profile id => code
     */
    private function codes(array $ids): array
    {
        /** @var array<string, string> */
        return Profile::withTrashed()->whereKey(array_values(array_unique(array_filter($ids))))->pluck('code', 'id')->all();
    }
}
