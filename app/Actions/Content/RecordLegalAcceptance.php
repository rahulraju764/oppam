<?php

declare(strict_types=1);

namespace App\Actions\Content;

use App\Enums\LegalAcceptanceContext;
use App\Enums\LegalKind;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\LegalAcceptance;
use App\Models\User;
use App\Queries\Content\LegalVersions;
use App\Services\Admin\Impersonation;
use Illuminate\Support\Str;

/**
 * A member agrees to the CURRENT live version of terms / privacy (A08, PRD §14). The version is the
 * server's: whatever page the browser had open, the row records what is live now. An admin in a
 * support session can't agree for the member. Recording the same version twice is a no-op.
 */
final class RecordLegalAcceptance
{
    public function __construct(
        private readonly LegalVersions $versions,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @param  list<LegalKind>|null  $kinds  null = every kind that needs acceptance
     * @return int rows written
     *
     * @throws ImpersonationRestricted
     */
    public function handle(User $user, LegalAcceptanceContext $context, ?string $ip, ?string $userAgent, ?array $kinds = null): int
    {
        $this->impersonation->assertAllowed();

        $current = $this->versions->current();
        $wanted = array_map(fn (LegalKind $kind): string => $kind->value, $kinds ?? LegalKind::accepted());
        $rows = [];

        foreach ($current as $kind => $version) {
            if (! in_array($kind, $wanted, true)) {
                continue;
            }
            $rows[] = [
                'id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'legal_document_id' => $version['id'],
                'kind' => $kind,
                'version' => $version['live'],
                'context' => $context->value,
                'ip_address' => $ip,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'accepted_at' => now(),
            ];
        }

        return $rows === [] ? 0 : LegalAcceptance::query()->insertOrIgnore($rows);
    }
}
