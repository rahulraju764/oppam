<?php

declare(strict_types=1);

namespace App\Http\Controllers\Member;

use App\Actions\Search\UnsubscribeSavedSearch;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * The unsubscribe link in a saved-search alert email (M04). GET only shows a confirmation page —
 * mail scanners open links on their own — and the POST turns the alerts off. The POST is also
 * the RFC 8058 one-click target (List-Unsubscribe-Post), so it is CSRF-exempt: the 48-character
 * random token is the credential. Unknown token → 404.
 */
final class UnsubscribeSavedSearchController extends Controller
{
    public function show(string $token, UnsubscribeSavedSearch $unsubscribe): View
    {
        $search = $unsubscribe->find($token) ?? abort(404);

        return view('pages.saved-search-unsubscribe', ['searchName' => $search->name, 'token' => $token, 'done' => false]);
    }

    public function store(string $token, UnsubscribeSavedSearch $unsubscribe): View
    {
        $search = $unsubscribe->handle($token) ?? abort(404);

        return view('pages.saved-search-unsubscribe', ['searchName' => $search->name, 'token' => $token, 'done' => true]);
    }
}
