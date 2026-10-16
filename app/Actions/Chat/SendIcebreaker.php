<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Domain\Chat\ChatAccess;
use App\Domain\Masters\MasterLists;
use App\Enums\MessageType;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Chat\ChatRefused;
use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use App\Services\Masters\Masters;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Send an icebreaker (PRD M07 "quick replies / icebreakers for the first message"): one of the
 * active A11 "Chat icebreakers", chosen by its code, as the member's FIRST message in the thread.
 * It is an ordinary text message after that — SendMessage applies every rule (plan, the Free
 * member's one reply, rate limit, idempotent client id).
 */
final class SendIcebreaker
{
    public function __construct(
        private readonly SendMessage $send,
        private readonly ChatAccess $access,
        private readonly Masters $masters,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws NotFoundHttpException
     * @throws ValidationException
     * @throws ChatRefused not the first message, unknown code, or SendMessage's refusals
     */
    public function handle(User $member, Profile $other, string $code, string $clientId): Message
    {
        $me = $member->profile ?? throw new NotFoundHttpException;
        $thread = $this->access->threadWith($me, $other) ?? throw new NotFoundHttpException;

        $existing = Message::query()->where('conversation_id', $thread->id)->where('client_id', $clientId)->first();
        if ($existing !== null && $existing->sender_profile_id === $me->id) {
            return $existing;   // a retry of the icebreaker already stored
        }

        $text = $this->text($code);
        if ($text === null || $this->hasWritten($me, $thread->id)) {
            throw ChatRefused::icebreakerUnavailable();
        }

        return $this->send->handle($member, $other, $text, $clientId);
    }

    /** @return array<string, string> code => text, the active icebreakers in A11 order */
    public function options(): array
    {
        $options = [];
        foreach ($this->masters->options(MasterLists::CHAT_ICEBREAKERS) as $item) {
            $options[(string) $item->code] = (string) $item->label;
        }

        return $options;
    }

    public function hasWritten(Profile $me, string $threadId): bool
    {
        return Message::query()->where('conversation_id', $threadId)->where('sender_profile_id', $me->id)
            ->where('type', '!=', MessageType::System->value)->exists();
    }

    private function text(string $code): ?string
    {
        return $this->options()[$code] ?? null;
    }
}
