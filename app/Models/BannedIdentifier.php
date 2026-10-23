<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BannedIdentifierType;
use Database\Factories\BannedIdentifierFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry on the ban blocklist (PRD A10 "ban (email/phone/device/pHash blocklist)"). Written
 * and read only through App\Domain\Safety\BanList, which hashes email / phone / device values.
 *
 * @property string $id
 * @property BannedIdentifierType $type
 * @property string $value
 * @property string|null $profile_id
 * @property string|null $abuse_case_id
 * @property string|null $banned_by_admin_id
 */
final class BannedIdentifier extends Model
{
    /** @use HasFactory<BannedIdentifierFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => BannedIdentifierType::class];
    }
}
