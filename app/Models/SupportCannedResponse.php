<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketCategory;
use Database\Factories\SupportCannedResponseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved support reply (PRD A10 "canned responses with variables"): `{first_name}`, `{code}`,
 * `{ticket}`, `{plan}`, `{agent}` are filled in by CannedResponseRenderer when an agent uses it.
 *
 * @property string $id
 * @property string $title
 * @property string $body
 * @property TicketCategory|null $category
 * @property string|null $created_by_admin_id
 */
final class SupportCannedResponse extends Model
{
    /** @use HasFactory<SupportCannedResponseFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['category' => TicketCategory::class];
    }
}
