<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Models\AdminUser;
use App\Models\CommunicationTemplate;
use App\Models\OutboundMessage;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sends a test notification of a template to the logged-in admin (A14).
 */
final class SendTestTemplate
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, CommunicationTemplate $template, ?string $target = null): OutboundMessage
    {
        Gate::forUser($admin)->authorize('templates.edit');

        $recipient = $target ?? $admin->email;

        // Render preview content substituting dummy variables
        $content = $template->body;
        if (is_array($template->variables)) {
            foreach ($template->variables as $var => $sample) {
                $content = str_replace('{'.$var.'}', (string) $sample, $content);
            }
        }

        $outbound = OutboundMessage::query()->create([
            'channel' => $template->channel,
            'recipient' => $recipient,
            'event' => 'template_test',
            'subject' => $template->subject ?? 'Test: '.$template->name,
            'content' => $content,
            'status' => OutboundMessage::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->audit->record('comms.template_test_sent', $template, after: ['recipient' => $recipient], actor: $admin, subjectLabel: $template->key);

        return $outbound;
    }
}
