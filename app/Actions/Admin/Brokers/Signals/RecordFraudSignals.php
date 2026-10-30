<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Signals;

use App\Domain\Broker\FraudSignals;
use App\Enums\BrokerSignal;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Models\Broker;
use App\Models\BrokerFraudSignal;
use App\Models\BrokerStaff;
use App\Notifications\Broker\BureauSignalRaised;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Turn what FraudSignals finds for one bureau into A07 signals (§11A B.12; owner 2026-10-30), as the
 * system: a signal not yet open is opened (audited `brokers.signal_raised`); one already open gets
 * its details and `last_seen_at` refreshed. A signal no longer detected stays open until an admin
 * resolves it; once resolved, the same evidence never reopens it (nor its payout hold) — only new
 * evidence does (isNewEvidence). Responses on opening:
 *  - the money signals (shared sign-ups, self-referral, referral spike) put the bureau's payouts on
 *    hold — "hold commission" / "review before payout" — until an admin releases them;
 *  - staff on many devices and unusual exports are also told to the Owner (B.12), without details.
 * The bureau row is locked while opening, so two scans can't open a signal twice (unique open key).
 */
final class RecordFraudSignals
{
    public function __construct(
        private readonly FraudSignals $signals,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<BrokerSignal> the signals opened by this run */
    public function handle(Broker $broker): array
    {
        $opened = [];

        foreach ($this->signals->detect($broker) as $type => $details) {
            $signal = BrokerSignal::from($type);

            if (DB::transaction(fn (): bool => $this->record($broker, $signal, $details))) {
                $opened[] = $signal;
            }
        }

        return $opened;
    }

    /**
     * Is there evidence the admin didn't see when they resolved the last signal of this type? A code
     * not in the resolved lists, or a count above the resolved one; a referral spike is reviewed once
     * per IST month.
     *
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $seen
     */
    public static function isNewEvidence(BrokerSignal $type, array $now, array $seen): bool
    {
        if ($type === BrokerSignal::ReferralSpike) {
            return ($now['month'] ?? null) !== ($seen['month'] ?? null);
        }

        foreach ($now as $key => $value) {
            $before = $seen[$key] ?? null;
            if (is_array($value) ? array_diff($value, is_array($before) ? $before : []) !== [] : (int) $value > (int) $before) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $details */
    private function record(Broker $broker, BrokerSignal $type, array $details): bool
    {
        $locked = Broker::query()->whereKey($broker->id)->lockForUpdate()->firstOrFail();
        $key = BrokerFraudSignal::openKey($locked->id, $type);
        $open = BrokerFraudSignal::query()->where('open_key', $key)->lockForUpdate()->first();

        if ($open !== null) {
            $open->forceFill(['details' => $details, 'last_seen_at' => now()])->save();

            return false;
        }

        $resolved = BrokerFraudSignal::query()->where('broker_id', $locked->id)->where('type', $type)->whereNotNull('resolved_at')
            ->latest('resolved_at')->first();
        if ($resolved !== null && ! self::isNewEvidence($type, $details, $resolved->details ?? [])) {
            return false;   // the admin already reviewed this evidence: no new signal, no new hold
        }

        $signal = new BrokerFraudSignal;
        $signal->forceFill([
            'broker_id' => $locked->id, 'type' => $type, 'details' => $details, 'open_key' => $key,
            'detected_at' => now(), 'last_seen_at' => now(),
        ])->save();
        $this->audit->record('brokers.signal_raised', $locked, after: ['signal' => $type->value, 'details' => $details], subjectLabel: $locked->code);

        if ($type->holdsPayouts() && $locked->payouts_held_at === null) {
            $reason = __('Fraud signal: :signal', ['signal' => $type->label()]);
            $locked->forceFill(['payouts_held_at' => now(), 'payouts_hold_reason' => $reason])->save();
            $this->audit->record('brokers.payouts_held', $locked, ['held' => false], ['held' => true, 'signal' => $type->value],
                reason: $reason, subjectLabel: $locked->code);
        }

        if ($type->tellsOwner()) {
            $brokerId = $locked->id;
            DB::afterCommit(static function () use ($brokerId, $type): void {
                BrokerStaff::query()->where('broker_id', $brokerId)->where('role', BureauRole::Owner)->where('status', BureauStaffStatus::Active)
                    ->with('user')->first()?->user?->notify(new BureauSignalRaised($type));
            });
        }

        return true;
    }
}
