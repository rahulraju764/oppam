<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shared navigation lists
|--------------------------------------------------------------------------
| The template defined "inside Matches" twice ($in_matches in header.php AND footer2.php) and
| the two drifted once. Here it is defined once and read by the header dropdown, the drawer and
| the mobile tab-bar sheet. Items whose route does not exist yet are not rendered.
*/

return [

    // Header "Matches" dropdown + tab-bar Matches sheet, in this order, with these icons.
    'matches' => [
        ['route' => 'member.profiles', 'label' => 'All Profiles', 'icon' => 'fa-users', 'description' => 'Browse everyone'],
        ['route' => 'member.matches', 'label' => 'My Matches', 'icon' => 'fa-heart', 'description' => 'Your funnel'],
        ['route' => 'member.matches.daily', 'label' => 'Daily Matches', 'icon' => 'fa-bolt', 'description' => "Today's picks"],
    ],

    // Routes that light the Matches section (its pages + a member profile opened from them).
    'matches_section' => ['member.profiles', 'member.matches', 'member.matches.daily', 'member.profile.show'],

    // Routes that light "Edit Profile" / the Profile tab (the wizard is reached through My Profile).
    'profile_section' => ['member.profile.me', 'member.onboarding'],

];
