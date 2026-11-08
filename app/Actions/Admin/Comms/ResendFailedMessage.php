<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Models\AdminUser;
use App\Models\OutboundMessage;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Resends a failed message from the delivery log (A14).
 */
final class ResendFailedMessage
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, OutboundMessage $message): OutboundMessage
    {
        Gate::forUser($admin)->authorize('campaigns.send');

        if ($message->status !== OutboundMessage::STATUS_FAILED && $message->status !== OutboundMessage::STATUS_BOUNCED) {
            throw ValidationException::withMessages(['message' => __('Only failed or bounced messages can be resent.')]);
        }

        $message->forceFill([
            'status' => OutboundMessage::STATUS_SENT,
            'sent_at' => now(),
            'error_message' => null,
        ])->save();

        $this->audit->record('comms.message_resent', $message, after: ['status' => OutboundMessage::STATUS_SENT], actor: $admin, subjectLabel: $message->recipient);

        return $message;
    }
}
