<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Enums\Flag;
use App\Models\User;
use App\Support\Facades\Feature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * On Livewire's temporary-upload endpoint: a body over STANDARD_KB is accepted only from a bureau
 * login that may bulk-import (`profiles.import`, an active KYC-verified bureau, the
 * `broker.bulk_import` flag) — the 200 MB photo zip of §11A B.9. Everyone else (members, admins,
 * other bureau roles) keeps the usual 12 MB ceiling, so the raised Livewire limit can't be used to
 * fill the disk from ordinary accounts. Decided from Content-Length or, when that header is missing
 * (chunked bodies), the parsed files' sizes — before Livewire stores anything.
 */
final class LimitLargeLivewireUploads
{
    public const STANDARD_KB = 12288;

    public function __construct(private readonly BureauGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $files = array_sum(array_map(
            fn (UploadedFile $f): int => (int) $f->getSize(),
            array_filter(Arr::flatten($request->allFiles()), fn (mixed $f): bool => $f instanceof UploadedFile)));
        $length = max((int) $request->header('Content-Length', '0'), $files);

        if ($length > self::STANDARD_KB * 1024 && ! $this->mayUploadLarge($request)) {
            abort(413, __('This file is too large.'));
        }

        return $next($request);
    }

    private function mayUploadLarge(Request $request): bool
    {
        $user = $request->user('web');

        return $user instanceof User
            && Feature::active(Flag::BrokerBulkImport)
            && $this->gate->allows($user, BureauPermission::ProfilesImport);
    }
}
