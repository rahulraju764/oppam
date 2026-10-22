<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentSide;
use Database\Factories\VerificationDocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One side of an ID document (M09). The bytes live ENCRYPTED on the private disk under a random
 * name (DocumentVault) and are only ever streamed by VerificationDocumentController. `path` and
 * `sha256` are cleared when the purge has verified the file is gone.
 *
 * @property string $id
 * @property string $verification_request_id
 * @property DocumentSide $side
 * @property string $disk
 * @property string|null $path
 * @property string $mime
 * @property int $size_bytes
 * @property string|null $sha256
 * @property Carbon|null $purged_at
 * @property-read VerificationRequest|null $request
 */
final class VerificationDocument extends Model
{
    /** @use HasFactory<VerificationDocumentFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> never serialised */
    protected $hidden = ['path', 'disk', 'sha256'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'side' => DocumentSide::class,
            'size_bytes' => 'integer',
            'purged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<VerificationRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(VerificationRequest::class, 'verification_request_id');
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function isPurged(): bool
    {
        return $this->purged_at !== null || $this->path === null;
    }
}
