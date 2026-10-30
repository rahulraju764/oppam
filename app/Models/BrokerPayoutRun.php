<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Enums\SettingKey;
use App\Services\Broker\PayoutRunCodeGenerator;
use App\Support\Facades\Settings;
use Database\Factories\BrokerPayoutRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A payout run for a period (A07, §11A B.10). DRAFT is the preview finance checks; it becomes
 * APPROVED after one approval — or two, by different admins, when a line to be paid is above the
 * second-approver threshold (R-A07-4) — and PAID when every payable line has its UTR. A DRAFT can
 * be discarded (its commissions go back to the pool). Nothing is mass-assignable.
 *
 * @property string $id
 * @property string $code PAY1001 — the run's public id (URLs, statements)
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property PayoutRunStatus $status
 * @property string|null $override_reason why a period already paid was run again (B.17), audited
 * @property string|null $created_by_admin_id
 * @property string|null $approved_by_admin_id
 * @property string|null $second_approved_by_admin_id
 * @property Carbon|null $approved_at
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BrokerPayoutItem> $items
 * @property-read AdminUser|null $createdBy
 * @property-read AdminUser|null $approvedBy
 * @property-read AdminUser|null $secondApprovedBy
 */
final class BrokerPayoutRun extends Model
{
    /** @use HasFactory<BrokerPayoutRunFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        self::creating(static function (self $run): void {
            if (($run->code ?? '') === '') {
                $run->code = app(PayoutRunCodeGenerator::class)->next();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => PayoutRunStatus::class,
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** "1 Oct 2026 – 31 Oct 2026": how A07 and statements name a run. */
    public function label(): string
    {
        return $this->period_start->format('j M Y').' – '.$this->period_end->format('j M Y');
    }

    /** R-A07-4: a line to be paid is above the threshold (the setting is read live, never cached past a change). */
    public function needsSecondApprover(): bool
    {
        $threshold = Settings::int(SettingKey::BrokerPayoutSecondApproverPaise);

        return $this->items()->where('status', PayoutItemStatus::Pending)->where('net_paise', '>', $threshold)->exists();
    }

    /** @return HasMany<BrokerPayoutItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BrokerPayoutItem::class, 'run_id');
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by_admin_id');
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function secondApprovedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'second_approved_by_admin_id');
    }
}
