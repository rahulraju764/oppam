<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Data\Admin\WorkQueueData;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\SettingKey;
use App\Models\AdminUser;
use App\Models\MessageFlag;
use App\Models\ModerationItem;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The work queues an admin may open (PRD A02 work-queue cards, §11.0 sidebar badges): waiting
 * count and oldest item for the three A04 queues and the escalations list — one grouped query
 * for all of them — and the A13 flagged chat messages (`chat.view_flagged`, one query). Verification,
 * reports, broker KYC, stuck orders and tickets add
 * themselves here with their modules (owner decision 2026-10-11: only queues that exist are shown).
 * Memoised for the request: the layout's sidebar and the dashboard ask the same thing.
 */
final class WorkQueues
{
    public const ESCALATIONS_ROUTE = 'admin.moderation.escalations';

    /** @var list<WorkQueueData>|null */
    private ?array $moderation = null;

    private ?WorkQueueData $chat = null;

    /**
     * @return list<WorkQueueData> only the queues this admin may open
     */
    public function for(?AdminUser $admin): array
    {
        if ($admin === null) {
            return [];
        }

        $queues = $admin->can('moderation.view') ? ($this->moderation ??= $this->moderation()) : [];
        if ($admin->can('chat.view_flagged')) {
            $queues[] = $this->chat ??= $this->chat();
        }

        return $queues;
    }

    /**
     * Waiting counts by sidebar route name, for the badges.
     *
     * @return array<string, array{key: string|null, count: int}>
     */
    public function badges(?AdminUser $admin): array
    {
        $badges = [];

        foreach ($this->for($admin) as $queue) {
            $badges[$queue->routeName] = ['key' => $queue->key, 'count' => $queue->count];
        }

        return $badges;
    }

    /** A13: flags still waiting (PENDING or ESCALATED), the oldest first raised. */
    private function chat(): WorkQueueData
    {
        $row = MessageFlag::query()->open()->selectRaw('count(*) as waiting, min(created_at) as oldest')->toBase()->first();
        $oldest = $row?->oldest !== null ? CarbonImmutable::parse((string) $row->oldest) : null;
        $breach = CarbonImmutable::now()->subHours(Settings::int(SettingKey::ModerationSlaHours));

        return new WorkQueueData(MessageFlag::QUEUE_KEY, __('Flagged messages'), 'admin.chat.flags', 'fa-flag', (int) ($row->waiting ?? 0), $oldest, $oldest !== null && $oldest->lt($breach));
    }

    /** @return list<WorkQueueData> */
    private function moderation(): array
    {
        $slaHours = Settings::int(SettingKey::ModerationSlaHours);
        $breach = CarbonImmutable::now()->subHours($slaHours);

        // One grouped query on (status, type, submitted_at): every waiting item, by status and type.
        // A queue's count has the same meaning as ModerationItem::pendingCount() — OPEN and
        // ESCALATED both still wait; the escalations card is the ESCALATED rows of all types.
        $rows = ModerationItem::query()->pending()
            ->selectRaw('status, type, count(*) as waiting, min(submitted_at) as oldest')
            ->groupBy('status', 'type')->toBase()->get();

        $queue = function (?string $key, string $label, string $route, string $icon, Collection $rows) use ($breach): WorkQueueData {
            $oldest = $rows->min('oldest');
            $oldest = $oldest !== null ? CarbonImmutable::parse((string) $oldest) : null;

            return new WorkQueueData($key, $label, $route, $icon, (int) $rows->sum('waiting'), $oldest, $oldest !== null && $oldest->lt($breach));
        };
        $ofType = fn (ModerationItemType $type): Collection => $rows->where('type', $type->value);

        return [
            $queue(ModerationItemType::ProfileNew->queueKey(), __('Profiles to moderate'), 'admin.moderation.profiles', 'fa-check-square-o', $ofType(ModerationItemType::ProfileNew)),
            $queue(ModerationItemType::Photo->queueKey(), __('Photos'), 'admin.moderation.photos', 'fa-picture-o', $ofType(ModerationItemType::Photo)),
            $queue(ModerationItemType::ProfileEdit->queueKey(), __('Edited fields'), 'admin.moderation.edits', 'fa-pencil-square-o', $ofType(ModerationItemType::ProfileEdit)),
            $queue(null, __('Escalations'), self::ESCALATIONS_ROUTE, 'fa-level-up', $rows->where('status', ModerationStatus::Escalated->value)),
        ];
    }
}
