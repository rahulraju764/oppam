<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketCategory;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A support ticket (PRD A10 support desk): from the contact form (a guest or a member) or in-app
 * help (a signed-in member; `context` = their plan and last actions, admins only). The SLA is the
 * first reply, by category. URLs use `code` (TKT10001); a member only ever sees their own.
 *
 * @property string $id
 * @property string $code
 * @property TicketSource $source
 * @property TicketCategory $category
 * @property TicketStatus $status
 * @property string|null $user_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string $subject
 * @property array<string, mixed>|null $context
 * @property string|null $assigned_admin_id
 * @property \Illuminate\Support\Carbon $first_response_due_at
 * @property \Illuminate\Support\Carbon|null $first_responded_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $last_activity_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read User|null $user
 * @property-read AdminUser|null $assignee
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SupportTicketMessage> $messages
 */
final class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use HasFactory, HasUlids;

    /** The queue key on `admin.queues` and the sidebar badge. */
    public const QUEUE_KEY = 'support.tickets';

    /** @var list<string> */
    protected $fillable = [];

    /** Tickets waiting for us (OPEN): the sidebar badge. */
    public static function waitingCount(): int
    {
        return self::query()->where('status', TicketStatus::Open->value)->count();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwnedBy(Builder $query, User $member): Builder
    {
        return $query->where('user_id', $member->id);
    }

    public function isFirstResponseOverdue(): bool
    {
        return $this->first_responded_at === null && $this->status->isOpen() && $this->first_response_due_at->isPast();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    /** @return HasMany<SupportTicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => TicketSource::class,
            'category' => TicketCategory::class,
            'status' => TicketStatus::class,
            'context' => 'array',
            'first_response_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }
}
