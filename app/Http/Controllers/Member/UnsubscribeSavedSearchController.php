<?php

declare(strict_types=1);

namespace App\Http\Controllers\Member;

use App\Actions\Search\UnsubscribeSavedSearch;
use App\Http\Controllers\Controller;
use App\Models\SavedSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class UnsubscribeSavedSearchController extends Controller
{
    public function __invoke(Request $request, SavedSearch $savedSearch, UnsubscribeSavedSearch $action): View
    {
        $action->handle($savedSearch);

        return view('pages.saved-search-unsubscribed', [
            'searchName' => $savedSearch->name,
        ]);
    }
}
