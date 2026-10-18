<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\MessageType;
use App\Livewire\Admin\Chat\FlagReview;
use App\Models\Message;
use App\Models\MessageFlag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The photo of a flagged IMAGE message, for the moderator reviewing that flag (PRD A13). Private
 * file, streamed — never a file URL. Only with `chat.view_flagged`, only the flagged message
 * itself (never another photo of the thread), and only within FlagReview::REVEAL_MINUTES of this
 * admin's audited reveal of that flag (session). Anything else is a 404.
 */
final class ChatFlagImageController
{
    public function __invoke(Request $request, string $flag): StreamedResponse
    {
        Gate::authorize('chat.view_flagged');

        $row = MessageFlag::query()->find(strtolower($flag));
        $revealedAt = (int) $request->session()->get(FlagReview::REVEALED_SESSION.($row->id ?? ''), 0);
        $message = $row === null ? null : Message::query()->find($row->message_id);
        $file = $message?->getFirstMedia(Message::IMAGE);

        if ($row === null || $message === null || $file === null || $message->type !== MessageType::Image || $message->isGone()
            || $revealedAt < now()->subMinutes(FlagReview::REVEAL_MINUTES)->getTimestamp()) {
            abort(404);
        }

        $conversion = $file->hasGeneratedConversion('preview') ? 'preview' : '';

        return response()->stream(function () use ($file, $conversion): void {
            $stream = $file->stream($conversion);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $conversion === 'preview' ? 'image/webp' : (string) $file->mime_type,
            'Content-Disposition' => 'inline; filename="flagged.'.($conversion === 'preview' ? 'webp' : 'jpg').'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
