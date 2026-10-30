<?php

declare(strict_types=1);

namespace App\Domain\Broker\Import;

use App\Domain\Moderation\ModerationFlags;
use App\Enums\ImportBatchStatus;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\SettingKey;
use App\Events\Admin\ModerationQueueChanged;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use App\Models\Media;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use App\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Batch-level quality signals of a bulk import (§11A B.12 "many imported profiles sharing a
 * mobile pattern → flag batch"; "imported photos matching other profiles (pHash) → flag batch, A04
 * escalation"). Each is worked out once per batch and kept in `signal_flags` (rows affected):
 *  - `mobile_pattern` — once the batch was validated: client mobiles sharing their first 8 digits
 *    in groups of `broker.signal_import_shared_prefix` (5) or more, or runs of that many consecutive
 *    numbers (the template has no email column, so mobiles only);
 *  - `photo_matches` — once the batch completed: imported photos that A04's duplicate check
 *    (ModerationFlags, pHash) matches to another profile or to a banned member's photo; their
 *    waiting A04 items are escalated.
 */
final class ImportBatchSignals
{
    public function __construct(
        private readonly ModerationFlags $flags,
        private readonly AuditLogger $audit,
    ) {}

    /** Work out the flags this batch is ready for and doesn't have yet; true when something changed. */
    public function analyse(BrokerImportBatch $batch): bool
    {
        $flags = $batch->signal_flags ?? [];
        $before = $flags;

        if (! array_key_exists('mobile_pattern', $flags) && in_array($batch->status, [ImportBatchStatus::Ready, ImportBatchStatus::Importing, ImportBatchStatus::Completed], true)) {
            $flags['mobile_pattern'] = $this->mobilePattern($batch);
        }

        if (! array_key_exists('photo_matches', $flags) && $batch->status === ImportBatchStatus::Completed) {
            $flags['photo_matches'] = $this->photoMatches($batch);
        }

        if ($flags === $before) {
            return false;
        }

        $batch->forceFill(['signal_flags' => $flags])->save();

        return true;
    }

    /** Rows in a shared-prefix group or a consecutive run of at least the limit. */
    public function mobilePattern(BrokerImportBatch $batch): int
    {
        $limit = Settings::int(SettingKey::BrokerSignalImportSharedPrefix);
        $numbers = [];
        BrokerImportRow::query()->where('batch_id', $batch->id)->select(['id', 'data'])->each(function (BrokerImportRow $row) use (&$numbers): void {
            $raw = $row->data['values']['client_mobile'] ?? null;
            $e164 = is_scalar($raw) ? PhoneNumber::tryParse((string) $raw)?->e164() : null;
            if (is_string($e164) && preg_match('/^\+91(\d{10})$/', $e164, $m) === 1) {   // Indian mobiles: the 10-digit number
                $numbers[] = $m[1];
            }
        });

        $flagged = [];
        foreach (array_count_values(array_map(fn (string $n): string => substr($n, 0, 8), $numbers)) as $prefix => $count) {
            if ($count >= $limit) {
                foreach ($numbers as $n) {
                    if (str_starts_with($n, (string) $prefix)) {
                        $flagged[$n] = true;
                    }
                }
            }
        }

        $sorted = array_values(array_unique(array_map('intval', $numbers)));
        sort($sorted);
        $run = [];
        foreach ($sorted as $i => $n) {
            $run = $i > 0 && $n === $sorted[$i - 1] + 1 ? [...$run, $n] : [$n];
            if (count($run) >= $limit) {
                foreach ($run as $m) {
                    $flagged[str_pad((string) $m, 10, '0', STR_PAD_LEFT)] = true;
                }
            }
        }

        return count($flagged);
    }

    /** Imported photos matching another profile; their waiting A04 items are escalated (system, audited). */
    public function photoMatches(BrokerImportBatch $batch): int
    {
        $profiles = Profile::query()->where('import_batch_id', $batch->id)->pluck('id');
        if ($profiles->isEmpty()) {
            return 0;
        }

        $matched = Media::query()->where('model_type', (new Profile)->getMorphClass())->whereIn('model_id', $profiles)
            ->where('collection_name', Profile::PHOTOS)->whereNotNull('phash')->get()
            ->filter(fn (Media $photo): bool => collect($this->flags->forPhoto($photo))->contains(fn ($flag): bool => in_array($flag->code, ['duplicate_photo', 'banned_photo'], true)));

        $escalated = 0;
        foreach ($matched as $photo) {
            $escalated += DB::transaction(function () use ($photo, $batch): int {
                $items = ModerationItem::query()->where('type', ModerationItemType::Photo)->where('subject_id', (string) $photo->id)
                    ->where('status', ModerationStatus::Open)->lockForUpdate()->get();
                foreach ($items as $item) {
                    $item->forceFill(['status' => ModerationStatus::Escalated, 'reason_note' => __('Bulk import :batch: this photo matches another profile.', ['batch' => $batch->code]),
                        'claimed_by_admin_id' => null, 'claimed_until' => null])->save();
                    $this->audit->record('moderation.escalated', $item, after: ['batch' => $batch->code, 'signal' => 'import_photo_match'], subjectLabel: $item->type->value);
                }

                return $items->count();
            });
        }

        if ($escalated > 0) {
            ModerationQueueChanged::dispatch(ModerationItemType::Photo);
        }

        return $matched->count();
    }
}
