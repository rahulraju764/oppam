<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply the team emailed to a contact-form sender (A08 inbox). Plain text only.
 *
 * @property string $id
 * @property string $contact_submission_id
 * @property string|null $admin_user_id
 * @property string $body
 * @property CarbonImmutable $created_at
 * @property-read AdminUser|null $admin
 */
final class ContactReply extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
