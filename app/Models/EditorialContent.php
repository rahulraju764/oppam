<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A row A08 edits (PRD A08 workflow). Only PUBLISHED rows are on the site. An edit to a live row
 * waits in `draft` until published (withDraft() shows it, without saving). Written only through
 * the Actions in App\Actions\Admin\Content.
 *
 * @property string $id
 * @property string $code
 * @property ContentStatus $status
 * @property int $sort_order
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $archived_at
 * @property array<string, mixed>|null $draft
 * @property int $lock_version counts every write, so a screen can say which version it was looking at
 * @property string|null $image_path
 */
abstract class EditorialContent extends Model
{
    use HasUlids;

    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            $row->lock_version = (int) $row->getAttribute('lock_version') + 1;
        });
    }

    abstract public function contentType(): ContentType;

    /** The row's name in lists and the audit log. */
    abstract public function title(): string;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'sort_order' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'draft' => 'array',
            'lock_version' => 'integer',
        ];
    }

    /**
     * What may be on the public site.
     *
     * @param  Builder<static>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', ContentStatus::Published);
    }

    public function isLive(): bool
    {
        return $this->status === ContentStatus::Published;
    }

    public function hasPendingChanges(): bool
    {
        return $this->draft !== null;
    }

    /** This row as it would look with its waiting edits. A copy: never saved. */
    public function withDraft(): static
    {
        $copy = clone $this;
        $copy->forceFill($this->draft ?? []);

        return $copy;
    }

    /** Why this row, as it would be published, can't go on the site; null when it can. */
    public function publishBlocker(): ?string
    {
        $row = $this->withDraft();

        return $this->contentType()->hasImage() && $row->image_path === null
            ? __('Add an image before publishing.')
            : null;
    }
}
