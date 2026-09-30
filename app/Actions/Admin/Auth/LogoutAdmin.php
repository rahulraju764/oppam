<?php

declare(strict_types=1);

namespace App\Actions\Admin\Auth;

use App\Models\AdminUser;
use App\Services\Admin\AdminSessionRegistry;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

/** Sign out: revoke this session's registry row, end the guard session, audit it. */
final class LogoutAdmin
{
    public function __construct(
        private readonly AdminSessionRegistry $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(AdminUser $admin, Request $request): void
    {
        $current = $this->sessions->current($request);

        if ($current !== null) {
            $this->sessions->revoke($current);
        }

        $this->audit->record('admin.logout', $admin, actor: $admin, subjectLabel: $admin->email);

        auth('admin')->logout();
        session()->invalidate();
        session()->regenerateToken();
    }
}
