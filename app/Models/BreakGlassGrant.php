<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BreakGlassReference;
use Database\Factories\BreakGlassGrantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A break-glass read of one conversation (PRD A13): opened by an admin holding
 * `chat.read_conversation` with a reason and a reference (a flag of that thread, or a legal request),
 * for `chat.break_glass_minutes`, usable only by that admin. Every page read is audited
 * (ReadBreakGlassTranscript). Rows go with the conversation; the audit rows stay. The member's
 * data-access log (`chat:data-access-log`) lists them.
 *
 * @property string $id
 * @property string|null $admin_user_id
 * @property string $conversation_id
 * @property string $reason
 * @property BreakGlassReference $reference_type
 * @property string $reference
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read AdminUser|null $admin
 * @property-read Conversation|null $conversation
 */
final class BreakGlassGrant extends Model
{
    /** @use HasFactory<BreakGlassGrantFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reference_type' => BreakGlassReference::class, 'expires_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
