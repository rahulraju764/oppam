<?php

declare(strict_types=1);

namespace App\Actions\Admin\Content;

use App\Models\AdminUser;
use App\Models\EditorialContent;
use App\Models\HomeBlock;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * A08 signed preview: a 30-minute link to the real public page with this row's unpublished text.
 * It works without a sign-in (the public host has no admin session), so it is audited. Open to
 * anyone who works on content.
 */
final class IssueContentPreview
{
    private const MINUTES = 30;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws AuthorizationException */
    public function handle(AdminUser $admin, EditorialContent $row): string
    {
        Gate::forUser($admin)->authorize('content.view');

        $this->audit->record('content.preview_issued', $row, actor: $admin, subjectLabel: $row->code);

        return URL::temporarySignedRoute(
            $row->contentType()->previewRoute($row instanceof HomeBlock ? $row->block_key : null),
            now()->addMinutes(self::MINUTES),
            ['code' => $row->code],
        );
    }
}
