<?php

declare(strict_types=1);

namespace App\Actions\Admin\Chat;

use App\Data\Chat\BreakGlassPageData;
use App\Exceptions\Chat\BreakGlassEnded;
use App\Models\AdminUser;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Queries\Chat\BreakGlassTranscript;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read one page of a conversation through a break-glass grant (PRD A13): the newest messages, or the
 * page before the oldest one already read. Only the admin the grant belongs to (anyone else: 404),
 * still holding `chat.read_conversation`, while the grant runs (else BreakGlassEnded). Every page
 * writes an audit row ("chat.break_glass.read") with the grant's reason and the message range — A13
 * acceptance "every break-glass read … writes an audit row with reason".
 */
final class ReadBreakGlassTranscript
{
    public function __construct(
        private readonly BreakGlassTranscript $transcript,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws NotFoundHttpException not this admin's grant
     * @throws BreakGlassEnded
     */
    public function handle(AdminUser $admin, BreakGlassGrant $grant, ?string $beforeId = null): BreakGlassPageData
    {
        Gate::forUser($admin)->authorize('chat.read_conversation');

        $thread = $this->usable($admin, $grant);
        $page = $this->transcript->page($thread, $beforeId);

        $this->audit->record('chat.break_glass.read', $thread, after: [
            'grant_id' => $grant->id,
            'messages' => count($page->messages),
            'from_message_id' => $page->oldestId,
            'to_message_id' => $page->newestId,
        ], reason: $grant->reason, actor: $admin, subjectLabel: $thread->memberCodesLabel());

        return $page;
    }

    /**
     * The grant's conversation, when this admin may read through it now.
     *
     * @throws NotFoundHttpException
     * @throws BreakGlassEnded
     */
    public function usable(AdminUser $admin, BreakGlassGrant $grant): Conversation
    {
        if ($grant->admin_user_id !== $admin->id) {
            throw new NotFoundHttpException;
        }

        if (! $grant->isActive()) {
            throw BreakGlassEnded::make();
        }

        return Conversation::query()->find($grant->conversation_id) ?? throw new NotFoundHttpException;
    }
}
