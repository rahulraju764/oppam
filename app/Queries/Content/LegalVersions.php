<?php

declare(strict_types=1);

namespace App\Queries\Content;

use App\Data\Content\PendingLegalData;
use App\Enums\ContentType;
use App\Enums\LegalKind;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Which legal version is live, and what a member still has to agree to (A08 forced re-acceptance,
 * owner 2026-11-06). A member must accept again — on a blocking page — when they haven't agreed to
 * the newest MATERIAL version of terms or privacy; a newer non-material version only shows a notice.
 * With nothing published, nothing is asked.
 */
final class LegalVersions
{
    private const CACHE_KEY = 'legal:versions';

    public function __construct(private readonly PreviewContext $preview) {}

    /** The page for a kind: the published version, or inside a signed preview the previewed one. */
    public function live(LegalKind $kind): ?LegalDocument
    {
        $code = $this->preview->codeFor(ContentType::Legal);
        if ($code !== null) {
            $previewed = LegalDocument::query()->where('code', $code)->where('kind', $kind)->first();
            if ($previewed !== null) {
                return $previewed;
            }
        }

        return LegalDocument::query()->live()->where('kind', $kind)->orderByDesc('version')->first();
    }

    public function pendingFor(User $user): PendingLegalData
    {
        $current = $this->current();
        if ($current === []) {
            return new PendingLegalData([], []);
        }

        $accepted = LegalAcceptance::query()->where('user_id', $user->id)
            ->selectRaw('kind, MAX(version) AS version')->groupBy('kind')
            ->pluck('version', 'kind')->map(fn (mixed $v): int => (int) $v)->all();

        $required = [];
        $notices = [];
        foreach ($current as $kind => $versions) {
            $agreed = $accepted[$kind] ?? 0;
            if ($agreed < $versions['material']) {
                $required[] = LegalKind::from($kind);
            } elseif ($agreed < $versions['live']) {
                $notices[] = LegalKind::from($kind);
            }
        }

        return new PendingLegalData($required, $notices);
    }

    /**
     * Per kind that needs acceptance: the live version, the live version's code and the newest
     * material version up to it.
     *
     * @return array<string, array{live: int, code: string, id: string, material: int}>
     */
    public function current(): array
    {
        /** @var array<string, array{live: int, code: string, id: string, material: int}> */
        return Cache::remember(self::CACHE_KEY, 600, function (): array {
            $out = [];
            foreach (LegalKind::accepted() as $kind) {
                $live = LegalDocument::query()->live()->where('kind', $kind)->orderByDesc('version')->first(['id', 'code', 'version']);
                if ($live === null) {
                    continue;
                }
                $material = LegalDocument::query()->where('kind', $kind)->where('is_material', true)
                    ->whereNotNull('published_at')->where('version', '<=', $live->version)->max('version');
                $out[$kind->value] = ['live' => $live->version, 'code' => $live->code, 'id' => $live->id, 'material' => (int) $material];
            }

            return $out;
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
