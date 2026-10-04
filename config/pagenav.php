<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Page-nav map — the [< Back]  title  [< Prev] [Next >] bar (template pagenav.php)
|--------------------------------------------------------------------------
| Keyed by ROUTE NAME. A route that is not in this map renders no bar (the two home pages,
| `home` and `member.dashboard`, are absent on purpose: they have no parent).
|
| back: [route, label] — the page's PARENT (its section), never "wherever you came from";
|       the JS upgrades it to history.back() when the visitor came from this site.
|       Omitted → the viewer's home (Dashboard when signed in, Home otherwise).
| prev / next: [route, label] — SIBLINGS only, where a real sequence exists (the Matches trio,
|       terms ⇄ privacy). Pricing / contact / legal are NOT a sequence — don't chain them.
|
| Entries may name routes that are not built yet: a button whose route does not exist is not
| rendered (never link to a page that isn't there). Per-request prev/next (the member being
| viewed, the wizard step) are passed to the layout at runtime — keep them out of this map.
| Rules and history: docs/template-notes.md "The back / prev / next bar".
*/

return [

    // ---- Auth (M01) ----
    'login' => ['title' => 'Login', 'back' => ['home', 'Home'], 'next' => ['register', 'Register']],
    'register' => ['title' => 'Register Free', 'back' => ['home', 'Home'], 'prev' => ['login', 'Login']],
    'password.request' => ['title' => 'Reset Password', 'back' => ['login', 'Login']],

    // ---- Profile wizard (M02): one route, six steps — the wizard passes its own prev/next. ----
    'member.onboarding' => ['title' => 'Create Your Profile', 'back' => ['member.profile.me', 'My Profile']],

    // ---- Browse / match (the three Matches pages walk in header-dropdown order) ----
    'member.profiles' => ['title' => 'All Profiles', 'back' => ['member.dashboard', 'Dashboard'], 'next' => ['member.matches', 'My Matches']],
    'member.matches' => ['title' => 'My Matches', 'back' => ['member.dashboard', 'Dashboard'], 'prev' => ['member.profiles', 'All Profiles'], 'next' => ['member.matches.daily', 'Daily Matches']],
    'member.my-matches' => ['title' => 'My Matches', 'back' => ['member.dashboard', 'Dashboard'], 'prev' => ['member.profiles', 'All Profiles'], 'next' => ['member.matches.daily', 'Daily Matches']],
    'member.matches.daily' => ['title' => 'Daily Matches', 'back' => ['member.dashboard', 'Dashboard'], 'prev' => ['member.my-matches', 'My Matches']],
    'member.daily-matches' => ['title' => 'Daily Matches', 'back' => ['member.dashboard', 'Dashboard'], 'prev' => ['member.my-matches', 'My Matches']],
    'member.visitors' => ['title' => 'Visitors', 'back' => ['member.dashboard', 'Dashboard']],
    'member.profile.show' => ['title' => 'Member Profile', 'back' => ['member.profiles', 'All Profiles']],
    'member.search' => ['title' => 'Search', 'back' => ['member.dashboard', 'Dashboard']],
    'member.interests' => ['title' => 'Interests', 'back' => ['member.dashboard', 'Dashboard']],
    'member.profile.me' => ['title' => 'My Profile', 'back' => ['member.dashboard', 'Dashboard']],
    'member.messages' => ['title' => 'Messages', 'back' => ['member.dashboard', 'Dashboard']],
    'member.notifications' => ['title' => 'Notifications', 'back' => ['member.dashboard', 'Dashboard']],
    'member.verification' => ['title' => 'Verify Profile', 'back' => ['member.profile.me', 'My Profile']],
    'member.checkout' => ['title' => 'Checkout', 'back' => ['plans', 'Membership Plans']],

    // ---- Static / support: reachable from both chromes, so Back is the viewer's home ----
    'plans' => ['title' => 'Membership Plans'],
    'contact' => ['title' => 'Contact Us'],
    'about' => ['title' => 'About Us'],
    'branches' => ['title' => 'Our Branches'],
    'success-stories' => ['title' => 'Success Stories'],
    // Terms and Privacy are a pair — the only sequence among the static pages; it walks both ways.
    'terms' => ['title' => 'Terms of Use', 'next' => ['privacy', 'Privacy']],
    'privacy' => ['title' => 'Privacy Policy', 'prev' => ['terms', 'Terms']],

];
