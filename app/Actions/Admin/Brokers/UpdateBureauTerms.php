<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Data\Broker\BureauTermsData;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A07 Overview edits (§11A B.12, B.17, R-A07-11): commission rate (0–10000 bps — `brokers.commission_bps`;
 * applies to commissions earned from now on, earned rows keep their snapshot), monthly interest
 * allowance for managed profiles, staff seat limit (lowering it deactivates nobody — R-A07-11,
 * R-M13-25) and the daily import cap (`brokers.edit`). Only the fields that change need their
 * permission; every change is audited old → new with the reason.
 */
final class UpdateBureauTerms
{
    use ManagesBureaus;

    private const LIMITS = [
        'commission_bps' => [0, 10_000],
        'interest_allowance_monthly' => [0, 10_000],
        'staff_seat_limit' => [0, 100],
        'import_daily_cap' => [0, 20_000],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array<string, int> the fields that changed (new values)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Broker $broker, BureauTermsData $terms, string $reason): array
    {
        if (! Gate::forUser($admin)->any(['brokers.edit', 'brokers.commission_bps'])) {
            $this->authorizeFor($admin, 'brokers.edit');   // throws
        }

        $wanted = [
            'commission_bps' => $terms->commissionBps,
            'interest_allowance_monthly' => $terms->interestAllowanceMonthly,
            'staff_seat_limit' => $terms->staffSeatLimit,
            'import_daily_cap' => $terms->importDailyCap,
        ];

        foreach (self::LIMITS as $field => [$min, $max]) {
            if ($wanted[$field] < $min || $wanted[$field] > $max) {
                throw ValidationException::withMessages([$field => __('Enter a number from :min to :max.', ['min' => $min, 'max' => $max])]);
            }
        }

        return DB::transaction(function () use ($admin, $broker, $wanted, $reason): array {
            $locked = Broker::query()->whereKey($broker->id)->lockForUpdate()->firstOrFail();
            $changed = array_filter($wanted, fn (int $value, string $field): bool => $locked->getAttribute($field) !== $value, ARRAY_FILTER_USE_BOTH);

            if ($changed === []) {
                throw ValidationException::withMessages(['reason' => __('Nothing has changed.')]);
            }

            if (array_key_exists('commission_bps', $changed)) {
                $this->authorizeFor($admin, 'brokers.commission_bps');
            }
            if (array_diff_key($changed, ['commission_bps' => true]) !== []) {
                $this->authorizeFor($admin, 'brokers.edit');
            }
            $reason = $this->validatedReason($reason);

            $before = array_map(fn (string $field): mixed => $locked->getAttribute($field), array_combine(array_keys($changed), array_keys($changed)));
            $locked->forceFill($changed)->save();

            $this->audit->record('brokers.terms_updated', $locked, $before, $changed, reason: $reason, actor: $admin, subjectLabel: $locked->code);
            $broker->setRawAttributes($locked->getAttributes(), true);

            return $changed;
        });
    }
}
