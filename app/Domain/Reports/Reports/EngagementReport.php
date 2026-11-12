<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Data\Reports\ReportData;
use App\Data\Reports\ReportStatData;
use App\Data\Reports\ReportTableData;
use App\Domain\Reports\ReportRange;
use App\Enums\Gender;
use App\Enums\PlanCode;
use App\Enums\ReportMetric;
use App\Queries\Reports\LiveMetrics;
use App\Queries\Reports\ReportSeries;
use Generator;

/**
 * A09 Engagement (P9.1a, PRD "likes / chat metrics"): active members, likes and mutual likes,
 * like → interest conversion, interests by gender and tier, conversations (reply rate, messages
 * per conversation, typical first reply), search usage (zero-result rate, top filters),
 * saved-search alerts and notification delivery by channel. Ratios come from their stored parts.
 */
final class EngagementReport implements ReportDefinition
{
    use FormatsReportNumbers;

    public const TOP_FILTERS = 15;

    private const FLOWS = [
        ReportMetric::ActiveMembers, ReportMetric::LikesSent, ReportMetric::LikesMutual, ReportMetric::InterestsAfterLike,
        ReportMetric::InterestsSent, ReportMetric::InterestsAccepted, ReportMetric::InterestsDeclined,
        ReportMetric::ConversationsStarted, ReportMetric::Messages, ReportMetric::Searches, ReportMetric::SearchesZeroResults,
    ];

    public function __construct(
        private readonly ReportSeries $series,
        private readonly LiveMetrics $live,
    ) {}

    public function build(ReportRange $range, bool $compare): ReportData
    {
        $t = $this->series->totals($range, self::FLOWS);
        $b = $compare ? $this->series->totals($range->previous(), self::FLOWS) : null;
        $chat = $this->live->conversations($range);
        $chatBefore = $compare ? $this->live->conversations($range->previous()) : null;
        $active = $this->live->uniqueActive($range);

        $rate = fn (array $x, string $part, string $whole): ?float => self::pct($x[$part], $x[$whole]);
        $stats = [
            new ReportStatData(__('Unique active members'), self::n($active), __('Members who used the site at least once in the period (WAU for 7 days, MAU for 30).'),
                $compare ? ReportStatData::delta($active, $this->live->uniqueActive($range->previous())) : null),
            new ReportStatData(__('Average daily active'), self::n(round($t['active_members'] / $range->days(), 1)), __('Active members per day, on average (DAU).'),
                $b ? ReportStatData::delta($t['active_members'], $b['active_members']) : null),
            new ReportStatData(__('Likes sent'), self::n($t['likes_sent']), ReportMetric::LikesSent->definition(), $b ? ReportStatData::delta($t['likes_sent'], $b['likes_sent']) : null),
            new ReportStatData(__('Mutual-like rate'), self::p($rate($t, 'likes_mutual', 'likes_sent')), __('Likes that made a pair mutual ÷ likes sent.'),
                $b ? ReportStatData::delta($rate($t, 'likes_mutual', 'likes_sent'), $rate($b, 'likes_mutual', 'likes_sent'), true) : null),
            new ReportStatData(__('Like → interest'), self::p($rate($t, 'interests_after_like', 'likes_sent')), __('Interests sent to a liked profile ÷ likes sent.'),
                $b ? ReportStatData::delta($rate($t, 'interests_after_like', 'likes_sent'), $rate($b, 'interests_after_like', 'likes_sent'), true) : null),
            new ReportStatData(__('Interests sent'), self::n($t['interests_sent']), ReportMetric::InterestsSent->definition(), $b ? ReportStatData::delta($t['interests_sent'], $b['interests_sent']) : null),
            new ReportStatData(__('Acceptance rate'), self::p($rate($t, 'interests_accepted', 'interests_sent')), __('Interests accepted ÷ interests sent, in the period.'),
                $b ? ReportStatData::delta($rate($t, 'interests_accepted', 'interests_sent'), $rate($b, 'interests_accepted', 'interests_sent'), true) : null),
            new ReportStatData(__('Conversations started'), self::n($chat['started']), ReportMetric::ConversationsStarted->definition(), $chatBefore ? ReportStatData::delta($chat['started'], $chatBefore['started']) : null),
            new ReportStatData(__('Conversations with a reply'), self::p(self::pct($chat['replied'], $chat['started'])), __('Conversations opened in the period where both members have written.'),
                $chatBefore ? ReportStatData::delta(self::pct($chat['replied'], $chat['started']), self::pct($chatBefore['replied'], $chatBefore['started']), true) : null),
            new ReportStatData(__('Messages per conversation'), self::n($chat['started'] > 0 ? round($chat['messages'] / $chat['started'], 1) : null), __('Messages in the conversations opened in the period, on average.')),
            new ReportStatData(__('Typical first reply'), $chat['median_reply_minutes'] === null ? '—' : self::n($chat['median_reply_minutes']).' '.__('min'), __('Median minutes from the first message to the other member\'s first answer.')),
            new ReportStatData(__('Searches with no results'), self::p($rate($t, 'searches_zero_results', 'searches')), __('Share of searches that found no one.'),
                $b ? ReportStatData::delta($rate($t, 'searches_zero_results', 'searches'), $rate($b, 'searches_zero_results', 'searches'), true) : null),
        ];

        $daily = $this->series->daily($range, [ReportMetric::ActiveMembers, ReportMetric::InterestsSent, ReportMetric::InterestsAccepted, ReportMetric::InterestsDeclined]);
        $charts = [
            ['id' => 'engagement-active', 'title' => __('Active members per day'), 'type' => 'line', 'labels' => $daily['labels'], 'datasets' => [
                ['label' => __('Active members'), 'data' => $daily['series']['active_members']],
            ]],
            ['id' => 'engagement-interests', 'title' => __('Interests per day'), 'type' => 'bar', 'labels' => $daily['labels'], 'datasets' => [
                ['label' => __('Sent'), 'data' => $daily['series']['interests_sent']],
                ['label' => __('Accepted'), 'data' => $daily['series']['interests_accepted']],
                ['label' => __('Declined'), 'data' => $daily['series']['interests_declined']],
            ]],
        ];

        return new ReportData($stats, $charts, iterator_to_array($this->tables($range), false), [
            __('Active members and search usage are tracked since :date.', ['date' => self::trackedSince()]),
            __('Email and SMS delivery come from the delivery log, kept 180 days. Saved-search alert clicks are not tracked.'),
        ]);
    }

    public function exportTables(ReportRange $range): iterable
    {
        return $this->tables($range);
    }

    public function exportRows(ReportRange $range): int
    {
        return array_sum(array_map(fn (ReportTableData $t): int => count($t->rows), iterator_to_array($this->tables($range), false)));
    }

    /** @return Generator<int, ReportTableData> */
    private function tables(ReportRange $range): Generator
    {
        foreach (['gender' => __('Interests by the sender\'s gender'), 'tier' => __('Interests by the sender\'s plan at the time')] as $prefix => $title) {
            $sent = $this->series->byDimension($range, ReportMetric::InterestsSent, $prefix);
            $accepted = $this->series->byDimension($range, ReportMetric::InterestsAccepted, $prefix);
            $declined = $this->series->byDimension($range, ReportMetric::InterestsDeclined, $prefix);
            $rows = [];
            foreach (array_unique([...array_keys($sent), ...array_keys($accepted), ...array_keys($declined)]) as $key) {
                $label = $prefix === 'gender' ? (Gender::tryFrom((string) $key)?->label() ?? (string) $key) : (PlanCode::tryFrom((string) $key)?->label() ?? (string) $key);
                $s = $sent[$key] ?? 0;
                $rows[] = [$label, $s, $accepted[$key] ?? 0, $declined[$key] ?? 0, self::p(self::pct($accepted[$key] ?? 0, $s))];
            }
            yield new ReportTableData('interests_by_'.$prefix, $title, [$prefix === 'gender' ? __('Gender') : __('Plan'), __('Sent'), __('Accepted'), __('Declined'), __('Accepted ÷ sent')], $rows);
        }

        $searches = max(1, $this->series->totals($range, [ReportMetric::Searches])['searches']);
        $rows = [];
        foreach (array_slice($this->series->byDimension($range, ReportMetric::SearchFilterUsed, 'filter'), 0, self::TOP_FILTERS, true) as $key => $n) {
            $rows[] = [str_replace('_', ' ', (string) $key), $n, self::p(self::pct($n, $searches))];
        }
        yield new ReportTableData('search_filters', __('Most used search filters'), [__('Filter'), __('Searches'), __('Of all searches')], $rows);

        $rows = [];
        foreach ($this->live->notifications($range) as $channel => $c) {
            $rows[] = [match ($channel) {
                'email' => __('Email'), 'sms' => __('SMS'), default => __('In-app')
            }, $c['sent'], $c['delivered'],
                $c['opened'] ?? '—', $c['opened'] === null ? '—' : self::p(self::pct($c['opened'], $c['delivered'])), $c['failed']];
        }
        $rows[] = [__('Saved-search alerts'), $this->live->savedSearchAlerts($range), '—', '—', __('not tracked'), 0];
        yield new ReportTableData('notifications', __('Notifications by channel'), [__('Channel'), __('Sent'), __('Delivered'), __('Opened / read'), __('Open rate'), __('Failed')], $rows);
    }
}
