<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ContentType;
use App\Models\SuccessStory;
use App\Queries\Content\PreviewContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The A08 signed preview route, after `signed`: {code} must be a row of the type the route's page
 * can show. A story withdrawn after its link was issued is gone. The page is never indexed or stored.
 */
final class EnableContentPreview
{
    public function __construct(private readonly PreviewContext $preview) {}

    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $code = (string) $request->route('code');
        $type = collect($types)->map(fn (string $value): ContentType => ContentType::from($value))
            ->first(fn (ContentType $candidate): bool => $candidate->model()::query()->where('code', $code)->exists());

        abort_if($type === null, 404);
        abort_if((string) $type->model()::query()->where('code', $code)->value('lock_version') !== (string) $request->query('v'), 404);
        abort_if($type === ContentType::Story && SuccessStory::query()->where('code', $code)->whereNotNull('withdrawn_at')->exists(), 404);

        $this->preview->set($type, $code, (int) $request->query('v'));

        try {
            /** @var Response $response */
            $response = $next($request);
        } finally {
            // The page is rendered by now. A long-running worker must never serve drafts to the next visitor.
            $this->preview->clear();
        }

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
