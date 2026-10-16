<?php

declare(strict_types=1);

namespace App\Http\Controllers\Member;

use App\Domain\Chat\ChatAccess;
use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /media/chat/{message}/{size} — an image sent in a chat (PRD M07; CLAUDE.md security rule 7:
 * private files are never public). Streamed from the private disk only to a signed-in member who
 * can read that thread right now (participant, not CLOSED, no block either way — ChatAccess), and
 * never to an admin support session (message content is private, R-M07-6). An unsent message, a
 * message that isn't an image, or anyone else → 404. `preview` falls back to the original until
 * the queued conversion exists. Not cached by the browser beyond the request (an unsend must win).
 */
final class ChatImageController extends Controller
{
    public function __invoke(Request $request, string $message, string $size, ChatAccess $access, Impersonation $impersonation): StreamedResponse
    {
        $viewer = $request->user('web');
        $me = $viewer instanceof User && $viewer->isActive() && ! $impersonation->isActive() ? $viewer->profile : null;
        $row = $me === null ? null : Message::query()->whereKey(strtolower($message))->first();
        $thread = $row?->conversation;
        $file = $row?->getFirstMedia(Message::IMAGE);

        if ($me === null || $row === null || $thread === null || $file === null || $row->type !== MessageType::Image
            || $row->deleted_for_all_at !== null || ! $access->canRead($me, $thread)) {
            abort(404);
        }

        $conversion = $size === 'preview' && $file->hasGeneratedConversion('preview') ? 'preview' : '';
        $mime = $conversion === 'preview' ? 'image/webp' : (string) $file->mime_type;

        return response()->stream(function () use ($file, $conversion): void {
            $stream = $file->stream($conversion);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="photo.'.($conversion === 'preview' ? 'webp' : 'jpg').'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
