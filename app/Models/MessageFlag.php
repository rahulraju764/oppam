<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageFlagDecision;
use App\Enums\MessageFlagRule;
use App\Enums\MessageFlagSource;
use App\Enums\MessageFlagStatus;
use Database\Factories\MessageFlagFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reason a chat message is in the A13 flagged queue (PRD A13): raised by ContentSafety as the
 * message was sent (AUTO) or by a member's report (REPORT). `excerpt` is the masked / matched
 * snippet only, encrypted at rest, shown to a moderator after an audited reveal — never the
 * conversation. Written by SendMessage / ReportChatMessages, decided by DecideMessageFlag.
 *
 * @property string $id
 * @property string $message_id
 * @property string $conversation_id
 * @property string|null $sender_profile_id
 * @property MessageFlagSource $source
 * @property MessageFlagRule $rule
 * @property string|null $excerpt
 * @property string|null $report_id
 * @property string|null $reporter_profile_id
 * @property MessageFlagStatus $status
 * @property MessageFlagDecision|null $decision
 * @property string|null $decision_note
 * @property string|null $decided_by_admin_id
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Message|null $message
 * @property-read Profile|null $sender
 * @property-read Report|null $report
 * @property-read AdminUser|null $decidedBy
 */
final class MessageFlag extends Model
{
    /** @use HasFactory<MessageFlagFactory> */
    use HasFactory, HasUlids;

    /** The queue key on `admin.queues` (AdminQueueCountChanged) and the sidebar badge. */
    public const QUEUE_KEY = 'chat.flags';

    /** @var list<string> */
    protected $fillable = [];

    /** Flags still waiting for a decision (PENDING or ESCALATED): the sidebar badge. */
    public static function pendingCount(): int
    {
        return self::query()->open()->count();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', MessageFlagStatus::openValues());
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<Profile, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'sender_profile_id')->withTrashed();
    }

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'decided_by_admin_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => MessageFlagSource::class,
            'rule' => MessageFlagRule::class,
            'status' => MessageFlagStatus::class,
            'decision' => MessageFlagDecision::class,
            'excerpt' => 'encrypted',
            'decided_at' => 'datetime',
        ];
    }
}
