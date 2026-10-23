<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KycSubmissionStatus;
use Database\Factories\BrokerKycFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One KYC submission of a bureau (PRD §11A B.3 / B.13): PAN number and bank account encrypted at
 * rest (`encrypted` cast, APP_KEY), only their last 4 shown; the PAN image encrypted in the
 * DocumentVault and viewed by admins through a signed, session-bound, audited link (A07). Never
 * serialised with its secrets.
 *
 * @property string $id
 * @property string $broker_id
 * @property string $pan_number_encrypted
 * @property string $pan_last4
 * @property string|null $pan_image_path
 * @property string|null $pan_image_mime
 * @property string $bank_account_encrypted
 * @property string $bank_last4
 * @property string $ifsc
 * @property string $account_name
 * @property KycSubmissionStatus $status
 * @property string|null $reviewed_by_admin_id
 * @property Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property Carbon $created_at
 * @property-read Broker|null $broker
 * @property-read AdminUser|null $reviewedBy
 */
final class BrokerKyc extends Model
{
    /** @use HasFactory<BrokerKycFactory> */
    use HasFactory, HasUlids;

    protected $table = 'broker_kyc';

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['pan_number_encrypted', 'bank_account_encrypted', 'pan_image_path'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pan_number_encrypted' => 'encrypted',
            'bank_account_encrypted' => 'encrypted',
            'status' => KycSubmissionStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Broker, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by_admin_id');
    }

    public function maskedPan(): string
    {
        return 'XXXXXX'.$this->pan_last4;
    }

    public function maskedAccount(): string
    {
        return 'XXXX'.$this->bank_last4;
    }
}
