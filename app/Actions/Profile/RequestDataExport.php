<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Enums\DataExportStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Jobs\Profile\GenerateDataExportJob;
use App\Models\DataExport;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /settings → Hide / delete: "Download my data" (PRD M14, DPDP). Queues GenerateDataExportJob,
 * which builds a zip (JSON + the member's photos) on the private disk. One request per 24 hours
 * (one still being prepared also blocks a second); refused in an admin support session (it is
 * the member's own data, not support's). Audited without the contents.
 */
final class RequestDataExport
{
    public const HOURS_BETWEEN = 24;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws SettingsRefused
     * @throws NotFoundHttpException
     */
    public function handle(User $member): DataExport
    {
        $this->impersonation->assertAllowed();

        if (! $member->isActive()) {
            throw new NotFoundHttpException;
        }

        return DB::transaction(function () use ($member): DataExport {
            // Serialise two clicks at once on the member row.
            User::query()->whereKey($member->id)->lockForUpdate()->first();

            $latest = DataExport::query()->where('user_id', $member->id)->orderByDesc('created_at')->orderByDesc('id')->first();

            if ($latest?->status === DataExportStatus::Queued) {
                if ($latest->created_at->gt(now()->subMinutes(GenerateDataExportJob::STALE_MINUTES))) {
                    throw SettingsRefused::exportInProgress();
                }
                // Lost (worker killed / job dropped): mark it failed so the member can ask again.
                $latest->forceFill(['status' => DataExportStatus::Failed])->save();
            }

            if ($latest !== null && $latest->status !== DataExportStatus::Failed
                && $latest->created_at->gt(now()->subHours(self::HOURS_BETWEEN))) {
                throw SettingsRefused::exportTooSoon();
            }

            $export = new DataExport;
            $export->forceFill([
                'code' => $this->newCode(),
                'user_id' => $member->id,
                'status' => DataExportStatus::Queued,
            ])->save();

            GenerateDataExportJob::dispatch($export->id)->afterCommit();

            $this->audit->record('account.data_export_requested', $export, after: ['code' => $export->code],
                actor: $member, subjectLabel: $export->code);

            return $export;
        });
    }

    private function newCode(): string
    {
        do {
            $code = 'DEX'.Str::upper(Str::random(10));
        } while (preg_match('/^DEX[A-Z0-9]{10}$/', $code) !== 1 || DataExport::query()->where('code', $code)->exists());

        return $code;
    }
}
