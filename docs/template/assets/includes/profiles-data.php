<?php
/* =============================================================================
   PROFILE DIRECTORY — the browsable list, in ONE place
   =============================================================================
   TODO(backend): hardcoded demo results. Replace the body of profile_directory()
   with the real query (the same one all-profiles.php pages through).

   This exists for the same reason plans.php and stories-data.php do: two surfaces
   render it and they must not drift. Today those two are

     • all-profiles.php  — the result rows
     • single-profile.php — its Prev / Next PROFILE buttons in the page-nav bar

   and the second one is the reason it had to move out of the page: "next profile"
   is meaningless unless the order the directory is listed in and the order the
   arrows walk are literally the same array.

   'pid' is the database key and the only thing that ever appears in a URL
   (single-profile?id=<pid>); 'id' is the displayed reference code. See CLAUDE.md —
   don't collapse the two.
   ============================================================================= */

if (!function_exists('profile_directory')) {
    function profile_directory()
    {
        return [
            ['name' => 'Anna Thomas',    'id' => 'VIS12370', 'pid' => 12370, 'img' => 'assets/images/matches/profile.webp',    'age' => '26 yrs', 'height' => "5'0\"", 'study' => 'MBA',    'work' => 'Consultant',      'place' => 'Thrissur',   'seen' => 'an hour ago', 'new' => true],
            ['name' => 'Meera Nair',     'id' => 'VIS12384', 'pid' => 12384, 'img' => 'assets/images/matches/600-600-1.webp',  'age' => '25 yrs', 'height' => "5'3\"", 'study' => 'B.Tech',  'work' => 'Engineer',        'place' => 'Kochi',      'seen' => 'today',      'new' => false],
            ['name' => 'Divya Krishna',  'id' => 'VIS12391', 'pid' => 12391, 'img' => 'assets/images/matches/profile.webp',    'age' => '28 yrs', 'height' => "5'2\"", 'study' => 'MBBS',    'work' => 'Doctor',          'place' => 'Kozhikode',  'seen' => '2 days ago', 'new' => true],
            ['name' => 'Sneha Menon',    'id' => 'VIS12402', 'pid' => 12402, 'img' => 'assets/images/matches/600-600-1.webp',  'age' => '27 yrs', 'height' => "5'4\"", 'study' => 'M.Com',   'work' => 'Banking',         'place' => 'Ernakulam',  'seen' => 'a week ago', 'new' => false],
        ];
    }
}

/* The row for one pid, or null. */
if (!function_exists('profile_by_pid')) {
    function profile_by_pid($pid)
    {
        foreach (profile_directory() as $row) {
            if ((string) $row['pid'] === (string) $pid) {
                return $row;
            }
        }
        return null;
    }
}

/* ['prev' => row|null, 'next' => row|null, 'index' => int|null, 'total' => int]
   for the given pid. The list does NOT wrap: the first profile has no Prev and
   the last has no Next, so the arrows never quietly loop you back to where you
   started — same rule the wizard steps follow. An unknown (or missing) pid means
   we are not inside the sequence, so BOTH neighbours are null and the bar renders
   Back only, exactly as it did before.

   TODO(backend): the real sequence must follow the list the member actually came
   from (search results, daily matches, …), not the whole directory — pass the
   result-set ids through the URL or hold them in the session. */
if (!function_exists('profile_neighbours')) {
    function profile_neighbours($pid)
    {
        $rows = array_values(profile_directory());
        $at   = null;

        foreach ($rows as $i => $row) {
            if ((string) $row['pid'] === (string) $pid) {
                $at = $i;
                break;
            }
        }

        return [
            'index' => $at === null ? null : $at + 1,
            'total' => count($rows),
            'prev'  => ($at !== null && isset($rows[$at - 1])) ? $rows[$at - 1] : null,
            'next'  => ($at !== null && isset($rows[$at + 1])) ? $rows[$at + 1] : null,
        ];
    }
}
