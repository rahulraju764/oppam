<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's channel choices for one notification event (PRD §7.2, M08/M14). Read by the
 * NotificationRouter (P3.2). user_id is set by the Action, never from input.
 *
 * @property int $id
 * @property string $user_id
 * @property string $event
 * @property bool $in_app
 * @property bool $email
 * @property bool $sms
 * @property bool $push
 */
final class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['event', 'in_app', 'email', 'sms', 'push'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['in_app' => 'boolean', 'email' => 'boolean', 'sms' => 'boolean', 'push' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
