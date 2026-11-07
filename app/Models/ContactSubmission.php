<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactStatus;
use App\Enums\TicketCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message from the public contact form (M12) in the A08 inbox. Written only by SubmitContact and
 * the Actions in App\Actions\Admin\Contact. The visitor's IP is kept only as a salted hash.
 *
 * @property string $id
 * @property string $code CNT…
 * @property string|null $user_id the member who was signed in, if any
 * @property string $name
 * @property string $email
 * @property TicketCategory $category
 * @property string $message
 * @property ContactStatus $status
 * @property string|null $spam_reason
 * @property string|null $assigned_admin_id
 * @property string|null $support_ticket_id
 * @property string|null $ip_hash
 * @property CarbonImmutable|null $replied_at
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AdminUser|null $assignee
 * @property-read SupportTicket|null $ticket
 * @property-read User|null $member
 */
final class ContactSubmission extends Model
{
    /** @use HasFactory<\Database\Factories\ContactSubmissionFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::saving(function (self $row): void {
            $row->lock_version = (int) $row->getAttribute('lock_version') + 1;
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => TicketCategory::class,
            'status' => ContactStatus::class,
            'replied_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /** @return BelongsTo<User, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<ContactReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(ContactReply::class)->orderBy('created_at');
    }
}
