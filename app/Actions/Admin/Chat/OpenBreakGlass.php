<?php

declare(strict_types=1);

namespace App\Actions\Admin\Chat;

use App\Enums\BreakGlassReference;
use App\Enums\SettingKey;
use App\Models\AdminUser;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Models\MessageFlag;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Open a break-glass read of one conversation (PRD A13 "Break-glass read"): `chat.read_conversation`
 * (super admins by default — decisions 2026-10-18), a mandatory reason (20 – 500 characters) and a
 * reference — an A13 flag OF THIS conversation (its id), or a legal / law-enforcement request number.
 * The grant lasts `chat.break_glass_minutes` (30) and only this admin can use it; reading through it
 * is audited page by page (ReadBreakGlassTranscript). Opening is audited too ("chat.break_glass.opened",
 * subject: the conversation, labelled with both members' codes). An admin who already has a running
 * grant for the thread gets that one back — no second clock, no second audit row.
 */
final class OpenBreakGlass
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Conversation $thread, string $reason, string $referenceType, string $reference): BreakGlassGrant
    {
        Gate::forUser($admin)->authorize('chat.read_conversation');

        $data = Validator::make(
            ['reason' => trim($reason), 'reference_type' => $referenceType, 'reference' => trim($reference)],
            [
                'reason' => ['required', 'string', 'min:20', 'max:500'],
                'reference_type' => ['required', Rule::enum(BreakGlassReference::class)],
                'reference' => $referenceType === BreakGlassReference::Flag->value
                    ? ['required', 'string', 'regex:/^[0-9a-z]{26}$/i']
                    : ['required', 'string', 'min:3', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \/#._\-]*$/'],
            ],
            ['reference.regex' => __('Enter the reference number as written on the request (letters, digits, / # . _ -).')],
            ['reason' => __('reason'), 'reference' => __('reference')],
        )->validate();

        $type = BreakGlassReference::from($data['reference_type']);
        $reference = $type === BreakGlassReference::Flag ? strtolower($data['reference']) : $data['reference'];
        if ($type === BreakGlassReference::Flag
            && ! MessageFlag::query()->whereKey($reference)->where('conversation_id', $thread->id)->exists()) {
            throw ValidationException::withMessages(['reference' => __('That flag doesn’t belong to this conversation.')]);
        }

        return DB::transaction(function () use ($admin, $thread, $data, $type, $reference): BreakGlassGrant {
            $running = BreakGlassGrant::query()->where('admin_user_id', $admin->id)->where('conversation_id', $thread->id)
                ->whereNull('ended_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            if ($running !== null) {
                return $running;
            }

            $grant = new BreakGlassGrant;
            $grant->admin_user_id = $admin->id;
            $grant->conversation_id = $thread->id;
            $grant->reason = $data['reason'];
            $grant->reference_type = $type;
            $grant->reference = $reference;
            $grant->expires_at = now()->addMinutes(Settings::int(SettingKey::ChatBreakGlassMinutes));
            $grant->save();

            $this->audit->record('chat.break_glass.opened', $thread, after: [
                'grant_id' => $grant->id,
                'reference_type' => $type->value,
                'reference' => $reference,
                'expires_at' => $grant->expires_at->toIso8601String(),
            ], reason: $grant->reason, actor: $admin, subjectLabel: $thread->memberCodesLabel());

            return $grant;
        });
    }
}
