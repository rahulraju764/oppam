<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\Admin\MemberNoteIsImmutable;
use Database\Factories\MemberNoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An internal admin note on a member (A03 "Notes (append-only)"). Written only by AddMemberNote;
 * a note is never edited or deleted — a correction is a new note.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $admin_user_id
 * @property string|null $admin_label
 * @property string $body
 * @property Carbon $created_at
 */
final class MemberNote extends Model
{
    /** @use HasFactory<MemberNoteFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw MemberNoteIsImmutable::update());
        self::deleting(fn (): never => throw MemberNoteIsImmutable::delete());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
