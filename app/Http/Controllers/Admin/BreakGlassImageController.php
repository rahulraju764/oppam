<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Chat\ReadBreakGlassTranscript;
use App\Enums\MessageType;
use App\Exceptions\Chat\BreakGlassEnded;
use App\Models\AdminUser;
use App\Models\BreakGlassGrant;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A photo sent in a conversation, for the admin reading it through a break-glass grant (PRD A13).
 * Private file, streamed — never a file URL. Only with `chat.read_conversation`, only the grant's own
 * admin while it runs, only a photo of that conversation; anything else is a 404. The grant's page
 * reads that listed the photo are what the audit log records.
 */
final class BreakGlassImageController
{
    public function __invoke(Request $request, string $grant, string $message, ReadBreakGlassTranscript $read): StreamedResponse
    {
        Gate::authorize('chat.read_conversation');

        /** @var AdminUser $admin */
        $admin = $request->user('admin');
        $row = BreakGlassGrant::query()->find(strtolower($grant)) ?? abort(404);

        try {
            $thread = $read->usable($admin, $row);
        } catch (BreakGlassEnded) {
            abort(404);
        }

        $photo = Message::query()->whereKey(strtolower($message))->where('conversation_id', $thread->id)->first();
        $file = $photo?->getFirstMedia(Message::IMAGE);
        if ($photo === null || $file === null || $photo->type !== MessageType::Image || $photo->isGone()) {
            abort(404);
        }

        $conversion = $file->hasGeneratedConversion('preview') ? 'preview' : '';

        return response()->stream(function () use ($file, $conversion): void {
            $stream = $file->stream($conversion);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $conversion === 'preview' ? 'image/webp' : (string) $file->mime_type,
            'Content-Disposition' => 'inline; filename="photo.'.($conversion === 'preview' ? 'webp' : 'jpg').'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
