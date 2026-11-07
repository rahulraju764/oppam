<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\LegalKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One version of a legal page (A08, M12). The live version of a kind is its one PUBLISHED row;
 * publishing a newer version archives the older one. A published version is never edited: what a
 * member agreed to stays exactly as it was (legal_acceptances points at it).
 *
 * @property LegalKind $kind
 * @property int $version
 * @property string $title
 * @property string|null $summary what changed from the version before
 * @property string $body sanitised and formatted HTML (LegalBodyFormatter)
 * @property CarbonImmutable $effective_on
 * @property bool $is_material members must accept it again before using the site
 * @property bool $legally_reviewed false shows a "not legally reviewed" banner on the page
 */
final class LegalDocument extends EditorialContent
{
    /** @use HasFactory<\Database\Factories\LegalDocumentFactory> */
    use HasFactory;

    public function contentType(): ContentType
    {
        return ContentType::Legal;
    }

    public function title(): string
    {
        return $this->kind->label().' v'.$this->version;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + [
            'kind' => LegalKind::class,
            'version' => 'integer',
            'effective_on' => 'immutable_date',
            'is_material' => 'boolean',
            'legally_reviewed' => 'boolean',
        ];
    }

    /** A newer version already went live: this one can never replace it. */
    public function publishBlocker(): ?string
    {
        $newer = self::query()
            ->where('kind', $this->kind)
            ->where('version', '>', $this->version)
            ->whereIn('status', [ContentStatus::Published, ContentStatus::Archived])
            ->whereNotNull('published_at')
            ->exists();

        return $newer ? __('A newer version of this page has already been published. Start a new version instead.') : null;
    }

    /** The version before this one, for the "what changed" comparison. */
    public function previous(): ?self
    {
        return self::query()->where('kind', $this->kind)->where('version', '<', $this->version)
            ->whereNotNull('published_at')->orderByDesc('version')->first();
    }
}
