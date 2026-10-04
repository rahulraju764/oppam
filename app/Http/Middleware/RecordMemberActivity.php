<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Activity\RecordMemberActivity as RecordActivity;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appended to the `web` group after the session checks: a signed-in member's request (page or
 * Livewire update) refreshes "last seen" / "last active" — debounced in the Action, so this costs
 * one cache call per request and a write at most every 5 minutes.
 */
final class RecordMemberActivity
{
    public function __construct(private readonly RecordActivity $record) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user('web');
        if ($user instanceof User && $response->getStatusCode() < 400) {
            $this->record->handle($user);
        }

        return $response;
    }
}
