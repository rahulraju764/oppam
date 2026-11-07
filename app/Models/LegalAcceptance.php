<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LegalAcceptanceContext;
use App\Enums\LegalKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member agreed to one legal version (A08 forced re-acceptance, PRD §14 consent at sign-up).
 * Written only by RecordLegalAcceptance. The IP and browser are cleared when the account is purged.
 *
 * @property string $id
 * @property string $user_id
 * @property string $legal_document_id
 * @property LegalKind $kind
 * @property int $version
 * @property LegalAcceptanceContext $context
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable $accepted_at
 */
final class LegalAcceptance extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => LegalKind::class,
            'version' => 'integer',
            'context' => LegalAcceptanceContext::class,
            'accepted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<LegalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }
}
