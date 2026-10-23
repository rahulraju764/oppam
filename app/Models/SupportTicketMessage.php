<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketAuthor;
use Database\Factories\SupportTicketMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in a support ticket thread: the member's message, an admin reply, or an internal
 * note (`is_internal` — admins only, never shown to the member or emailed).
 *
 * @property string $id
 * @property string $support_ticket_id
 * @property TicketAuthor $author
 * @property string|null $admin_id
 * @property string $body
 * @property bool $is_internal
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read AdminUser|null $admin
 */
final class SupportTicketMessage extends Model
{
    /** @use HasFactory<SupportTicketMessageFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleToMember(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'author' => TicketAuthor::class,
            'is_internal' => 'boolean',
        ];
    }
}
