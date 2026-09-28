<?php
/* MEMBERSHIP PLANS — the single source of truth.
   index.php and package.php both render these. They used to be two hand-maintained
   copies of the same three cards, with a comment on each telling you to keep them in
   sync by hand. Now a price only changes here.

   TODO(backend): replace with the real plans query. */

$plans = [
    [
        'name'     => 'Silver',
        'price'    => '499',
        'billed'   => '5,988',
        'featured' => false,
        'features' => [
            'Send 25 interests a month',
            'View 10 verified mobile numbers',
            'Chat with accepted matches',
            'Daily match recommendations',
        ],
    ],
    [
        'name'     => 'Gold',
        'price'    => '999',
        'billed'   => '11,988',
        'featured' => true,
        'badge'    => 'Most Popular',
        'features' => [
            'Send 100 interests a month',
            'View 50 verified mobile numbers',
            'Unlimited chat and messages',
            'Profile highlighted in search',
        ],
    ],
    [
        'name'     => 'Diamond',
        'price'    => '1,999',
        'billed'   => '23,988',
        'featured' => false,
        'features' => [
            'Unlimited interests',
            'Unlimited verified mobile numbers',
            'Unlimited chat and messages',
            'Dedicated relationship manager',
        ],
    ],
];
