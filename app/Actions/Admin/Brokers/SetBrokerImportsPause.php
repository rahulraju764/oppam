<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Actions\Broker\Safety\PauseBrokerImports;
use App\Enums\ImportsPauseReason;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A07 pause / resume of a bureau's bulk imports (R-A07-9 "admin can pause a bureau's imports
 * without deactivating it; single creation still works"; B.12 Imports monitor — also the review
 * of an auto-pause, R-M13-32). Pausing goes through PauseBrokerImports (the same banner and audit
 * as the auto-pause, reason ADMIN); resuming clears the pause whatever its reason. Needs
 * `brokers.imports.manage`; typed reason, audited.
 */
final class SetBrokerImportsPause
{
    use ManagesBureaus;

    public function __construct(
        private readonly PauseBrokerImports $pause,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BrokerStateConflict already in that state
     */
    public function handle(AdminUser $admin, Broker $broker, bool $paused, string $reason): Broker
    {
        $this->authorizeFor($admin, 'brokers.imports.manage');
        $reason = $this->validatedReason($reason);

        if ($paused) {
            if (! $this->pause->handle($broker, ImportsPauseReason::Admin, admin: $admin, note: $reason)) {
                throw BrokerStateConflict::importsAlreadyPaused();
            }

            return $broker->refresh();
        }

        return DB::transaction(function () use ($admin, $broker, $reason): Broker {
            $locked = Broker::query()->whereKey($broker->id)->lockForUpdate()->firstOrFail();
            if ($locked->imports_paused_at === null) {
                throw BrokerStateConflict::importsNotPaused();
            }

            $before = ['reason' => $locked->imports_paused_reason?->value];
            $locked->forceFill(['imports_paused_at' => null, 'imports_paused_reason' => null, 'imports_paused_batch_id' => null])->save();
            $this->audit->record('brokers.imports_resumed', $locked, $before, ['paused' => false], reason: $reason, actor: $admin, subjectLabel: $locked->code);

            return $locked;
        });
    }
}
