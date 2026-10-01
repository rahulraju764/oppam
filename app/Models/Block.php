<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BlockFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One profile blocking another (PRD §7.2). Symmetric in effect: neither side sees the other
 * (R-M03-1, App\Domain\Safety\BlockList). Created by the block Action (P6.2) only.
 *
 * @property string $id
 * @property string $blocker_profile_id
 * @property string $blocked_profile_id
 * @property string|null $reason
 */
final class Block extends Model
{
    /** @use HasFactory<BlockFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];
}
