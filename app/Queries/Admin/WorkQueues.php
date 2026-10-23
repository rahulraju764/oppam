<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Data\Admin\WorkQueueData;
use App\Domain\Broker\KycQueue;
use App\Enums\KycSubmissionStatus;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\SettingKey;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerKyc;
use App\Models\EngagementSignal;
use App\Models\MessageFlag;
use App\Models\ModerationItem;
use App\Models\VerificationRequest;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The work queues an admin may open (PRD A02 work-queue cards, §11.0 sidebar badges): waiting
 * count and oldest item for the three A04 queues and the escalations list — one grouped query
 * for all of them — and the A13 flagged chat messages and abuse signals (`chat.view_flagged`, one
 * query each), and the A05 ID verification queue (`verification.queue.view`, P6.1; breached after
 * `verification.aging_breach_hours`). Reports, broker KYC, stuck orders and tickets add
 * themselves here with their modules (owner decision 2026-10-11: only queues that exist are shown).
 * Memoised for the request: the layout's sidebar and the dashboard ask the same thing.
 */
final class WorkQueues
{
    public const ESCALATIONS_ROUTE = 'admin.moderation.escalations';

    /** @var list<WorkQueueData>|null */
    private ?array $moderation = null;

    private ?WorkQueueData $chat = null;

    private ?WorkQueueData $signals = null;

    private ?WorkQueueData $verification = null;

    private ?WorkQueueData $brokerKyc = null;

    /**
     * @return list<WorkQueueData> only the queues this admin may open
     */
    public function for(?AdminUser $admin): array
    {
        if ($admin === null) {
            return [];
        }

        $queues = $admin->can('moderation.view') ? ($this->moderation ??= $this->moderation()) : [];
        if ($admin->can('verification.queue.view')) {
            $queues[] = $this->verification ??= $this->verification();
        }
        if ($admin->can('brokers.kyc')) {
            $queues[] = $this->brokerKyc ??= $this->brokerKyc();
        }
        if ($admin->can('chat.view_flagged')) {
            $queues[] = $this->chat ??= $this->chat();
            $queues[] = $this->signals ??= $this->signals();
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

    /** A05: ID requests waiting (escalated ones included), the oldest first sent. */
    private function verification(): WorkQueueData
    {
        $row = VerificationRequest::query()->waiting()->selectRaw('count(*) as waiting, min(submitted_at) as oldest')->toBase()->first();
        $oldest = $row?->oldest !== null ? CarbonImmutable::parse((string) $row->oldest) : null;
        $breach = CarbonImmutable::now()->subHours(Settings::int(SettingKey::VerificationAgingBreachHours));

        return new WorkQueueData(VerificationRequest::QUEUE_KEY, __('ID verification'), 'admin.verification.index', 'fa-id-card-o', (int) ($row->waiting ?? 0), $oldest, $oldest !== null && $oldest->lt($breach));
    }

    /** A07 (P7.1): bureau applications without KYC + KYC submissions waiting, the oldest first. */
    private function brokerKyc(): WorkQueueData
    {
        $oldestKyc = BrokerKyc::query()->where('status', KycSubmissionStatus::Pending)->min('created_at');
        $oldestApplication = Broker::query()->applicationsToReview()->min('created_at');
        $oldest = collect([$oldestKyc, $oldestApplication])->filter()->map(fn (mixed $at): CarbonImmutable => CarbonImmutable::parse((string) $at))->min();
        $breach = CarbonImmutable::now()->subHours(Settings::int(SettingKey::ModerationSlaHours));

        return new WorkQueueData(KycQueue::QUEUE_KEY, __('Bureau KYC'), 'admin.brokers.kyc', 'fa-building-o', KycQueue::waitingCount(), $oldest, $oldest !== null && $oldest->lt($breach));
    }

    /** A13: abuse signals waiting for a moderator, the oldest first raised. */
    private function signals(): WorkQueueData
    {
        $row = EngagementSignal::query()->open()->selectRaw('count(*) as waiting, min(created_at) as oldest')->toBase()->first();
        $oldest = $row?->oldest !== null ? CarbonImmutable::parse((string) $row->oldest) : null;
        $breach = CarbonImmutable::now()->subHours(Settings::int(SettingKey::ModerationSlaHours));

        return new WorkQueueData(EngagementSignal::QUEUE_KEY, __('Abuse signals'), 'admin.chat.signals', 'fa-bolt', (int) ($row->waiting ?? 0), $oldest, $oldest !== null && $oldest->lt($breach));
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
