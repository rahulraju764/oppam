<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IgnoreFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One profile ignoring another (M09 "Ignore"): the ignored profile no longer appears in the
 * ignorer's search and lists. One-directional and silent — the other member is unaffected (a block
 * is the two-way version). Created by the ignore Action (P6.2) only.
 *
 * @property string $id
 * @property string $ignorer_profile_id
 * @property string $ignored_profile_id
 */
final class Ignore extends Model
{
    /** @use HasFactory<IgnoreFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];
}
