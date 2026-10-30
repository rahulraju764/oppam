<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConsentDisputeStatus;
use App\Enums\DisputeSource;
use App\Enums\ProfileStatus;
use App\Services\Broker\DisputeCodeGenerator;
use Database\Factories\BrokerConsentDisputeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A client's "I never agreed to this profile" complaint about a bureau's managed profile (§11A B.12
 * "Consent disputes", R-A07-10), recorded by an admin in A07. Recording suspends the profile and
 * opens an A10 case; UPHELD disputes within the window count towards the bureau's creation pause;
 * DISMISSED returns the profile to `profile_status_before`. Nothing is mass-assignable.
 *
 * @property string $id
 * @property string $code DSP1001 — the public id
 * @property string $broker_id
 * @property string $profile_id
 * @property string|null $abuse_case_id
 * @property DisputeSource $source
 * @property string $note
 * @property ConsentDisputeStatus $status
 * @property ProfileStatus $profile_status_before
 * @property string $recorded_by_admin_id
 * @property string|null $decided_by_admin_id
 * @property Carbon|null $decided_at
 * @property string|null $outcome_note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Broker|null $broker
 * @property-read Profile|null $profile
 * @property-read AbuseCase|null $abuseCase
 * @property-read AdminUser|null $recordedBy
 * @property-read AdminUser|null $decidedBy
 */
final class BrokerConsentDispute extends Model
{
    /** @use HasFactory<BrokerConsentDisputeFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        self::creating(static function (self $dispute): void {
            if (($dispute->code ?? '') === '') {
                $dispute->code = app(DisputeCodeGenerator::class)->next();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => DisputeSource::class,
            'status' => ConsentDisputeStatus::class,
            'profile_status_before' => ProfileStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Broker, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class)->withTrashed();
    }

    /** @return BelongsTo<AbuseCase, $this> */
    public function abuseCase(): BelongsTo
    {
        return $this->belongsTo(AbuseCase::class);
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'recorded_by_admin_id');
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'decided_by_admin_id');
    }
}
