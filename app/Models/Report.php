<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportReason;
use App\Enums\ReportSource;
use App\Enums\ReportStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A member reporting another (PRD M09) — the PRD's `abuse_reports`. From chat it names the thread
 * and the messages the member selected (each also a REPORT flag in the A13 queue); from a profile
 * page it carries the reason and details only. Every report joins the reported member's open A10
 * abuse case (AbuseCases). The reported member is never told.
 *
 * @property string $id
 * @property string $reporter_profile_id
 * @property string $reported_profile_id
 * @property ReportSource $source
 * @property ReportReason $reason
 * @property string|null $details
 * @property string|null $conversation_id
 * @property string|null $abuse_case_id
 * @property ReportStatus $status
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Profile|null $reporter
 * @property-read Profile|null $reported
 * @property-read AbuseCase|null $abuseCase
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Message> $messages
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MessageFlag> $flags
 */
final class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return BelongsTo<Profile, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'reporter_profile_id')->withTrashed();
    }

    /** @return BelongsTo<Profile, $this> */
    public function reported(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'reported_profile_id')->withTrashed();
    }

    /** @return BelongsTo<AbuseCase, $this> */
    public function abuseCase(): BelongsTo
    {
        return $this->belongsTo(AbuseCase::class);
    }

    /** @return BelongsToMany<Message, $this> */
    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(Message::class, 'report_messages');
    }

    /** @return HasMany<MessageFlag, $this> */
    public function flags(): HasMany
    {
        return $this->hasMany(MessageFlag::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => ReportSource::class,
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'decided_at' => 'datetime',
        ];
    }
}
