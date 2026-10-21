<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SafetyAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An A13 rules-editor override of one App\Enums\SafetyRule's default pattern and actions. No row = the default.
 * Read only through ChatSafetyRules (cached); written only by UpdateSafetyRule / ResetSafetyRule.
 *
 * @property string $key SafetyRule value
 * @property string|null $pattern
 * @property SafetyAction $early_action
 * @property SafetyAction $later_action
 * @property string|null $updated_by_admin_id
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read AdminUser|null $updatedBy
 */
final class ChatSafetyRule extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [];

    /** @return BelongsTo<AdminUser, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by_admin_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['early_action' => SafetyAction::class, 'later_action' => SafetyAction::class];
    }
}
