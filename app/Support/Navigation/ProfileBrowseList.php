<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use Illuminate\Contracts\Session\Session;

/**
 * Prev / Next on a profile page (M03): the codes of the result list the member came from, kept
 * in the session by the list pages (search, matches — P2.x call remember()). Codes only.
 */
final class ProfileBrowseList
{
    private const KEY = 'profile_browse';

    private const MAX = 200;

    public function __construct(private readonly Session $session) {}

    /** @param  list<string>  $codes */
    public function remember(array $codes): void
    {
        $this->session->put(self::KEY, array_slice(array_values(array_unique($codes)), 0, self::MAX));
    }

    /** @return array{previous: string|null, next: string|null} */
    public function neighbours(string $code): array
    {
        $codes = $this->session->get(self::KEY, []);
        $codes = is_array($codes) ? array_values(array_filter($codes, is_string(...))) : [];
        $index = array_search($code, $codes, true);

        if ($index === false) {
            return ['previous' => null, 'next' => null];
        }

        return ['previous' => $codes[$index - 1] ?? null, 'next' => $codes[$index + 1] ?? null];
    }
}
