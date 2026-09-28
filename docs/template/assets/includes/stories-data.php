<?php
/* =============================================================================
   SUCCESS STORIES — the one source of story data
   =============================================================================
   Three surfaces render these couples and they must agree:

     success-stories.php   the grid + the full write-ups
     single-profile.php    the .stories slider in the right rail
     (footer / my-matches / search cards link into the page)

   Before this file, single-profile.php carried THREE byte-identical slides all
   saying "Allen & Riya" and pointing at href="#". That is the same drift the
   profile-row / profile-tile partials were created to stop.

   TODO(backend): replace stories_data() with the real success-stories query.
   'slug' is the anchor id on success-stories.php — keep it URL-safe and stable,
   it is what the "Read their story" links target.
   ============================================================================= */

if (!function_exists('stories_data')) {
    function stories_data()
    {
        return [
            [
                'slug'   => 'allen-riya',
                'couple' => 'Allen & Riya',
                'place'  => 'Thrissur',
                'date'   => 'Married January 2024',
                'img'    => 'assets/images/profile/story-couple.webp',
                'w'      => 500,
                'h'      => 500,
                'quote'  => 'We matched in the first week. Six months later both families met in Thrissur.',
                'story'  => [
                    'Riya had been on two other sites for the better part of a year and had almost given up on the whole idea. Her sister made the Oppam profile for her, filled in half the details wrong, and left it at that.',
                    'Allen sent an interest on the third day. What made the difference, she says, was that the profile was verified — her parents could see the badge, and that was the end of the argument about whether any of this was safe.',
                    'The families met in Thrissur in the monsoon, which nobody had planned for, and the wedding was in January.',
                ],
            ],
            [
                'slug'   => 'vishnu-anjali',
                'couple' => 'Vishnu & Anjali',
                'place'  => 'Kochi',
                'date'   => 'Married March 2024',
                'img'    => 'assets/images/details/001.jpg',
                'w'      => 600,
                'h'      => 600,
                'quote'  => 'The daily recommendations did the work. Ten minutes an evening, and one of them was him.',
                'story'  => [
                    'Anjali is a consultant and travels most weeks, so scrolling through hundreds of profiles was never going to happen. The daily recommendations were the only part of the site she actually used.',
                    'Vishnu turned up in that list in October. They talked for a month before either family knew anything about it, which she recommends to everyone.',
                    'They married in Kochi in March, and her mother has since made profiles for two cousins.',
                ],
            ],
            [
                'slug'   => 'arun-meera',
                'couple' => 'Arun & Meera',
                'place'  => 'Kozhikode',
                'date'   => 'Married August 2023',
                'img'    => 'assets/images/details/s1.jpg',
                'w'      => 400,
                'h'      => 400,
                'quote'  => 'Both sets of parents were on the call. That is not something we could have done over a phone number.',
                'story'  => [
                    'Arun was working in Bangalore and Meera in Kozhikode, so the first four conversations were video calls — twice with both sets of parents sitting in.',
                    'They credit the partner-preference filters for the fact that the shortlist was short. Caste, language and the fact that neither wanted to leave Kerala permanently ruled out most of it before anyone spoke.',
                    'The wedding was in August at Meera\'s family temple, and Arun has since moved back to Kozhikode.',
                ],
            ],
            [
                'slug'   => 'jithin-sneha',
                'couple' => 'Jithin & Sneha',
                'place'  => 'Thiruvananthapuram',
                'date'   => 'Married November 2023',
                'img'    => 'assets/images/family/02.jpg',
                'w'      => 400,
                'h'      => 400,
                'quote'  => 'I was the one who sent the interest first. Nobody warned me that was allowed.',
                'story'  => [
                    'Sneha is a doctor and had a very specific idea of the hours a partner would have to tolerate. She sent the first interest, which she says she would never have done on a site where the messaging felt public.',
                    'Jithin replied the same evening. They met properly at a wedding neither of them wanted to attend, six weeks later.',
                    'They married in Thiruvananthapuram in November.',
                ],
            ],
            [
                'slug'   => 'rahul-divya',
                'couple' => 'Rahul & Divya',
                'place'  => 'Ernakulam',
                'date'   => 'Married June 2024',
                'img'    => 'assets/images/family/03.jpg',
                'w'      => 400,
                'h'      => 400,
                'quote'  => 'Two weeks of talking, one meeting, and my father was already looking at dates.',
                'story'  => [
                    'Divya\'s profile had been live for three days. Rahul\'s was six months old and, by his own account, largely ignored until he finally added photographs.',
                    'They were matched on the horoscope details as well as everything else, which mattered a great deal to one family and not at all to the other — they got past it.',
                    'The wedding was in Ernakulam in June, and both of them still argue about who noticed the other first.',
                ],
            ],
            [
                'slug'   => 'nikhil-aparna',
                'couple' => 'Nikhil & Aparna',
                'place'  => 'Kannur',
                'date'   => 'Married February 2024',
                'img'    => 'assets/images/family/04.webp',
                'w'      => 400,
                'h'      => 400,
                'quote'  => 'We are both second marriages. That was the filter that made this site different.',
                'story'  => [
                    'Both had been married before, and both had spent a while on sites where that fact turned every conversation into an interrogation.',
                    'Aparna says the difference was simply that marital status was a field, set once, and visible before anyone got in touch. Nobody had to explain themselves twice.',
                    'They married quietly in Kannur in February with about thirty people present.',
                ],
            ],
        ];
    }
}
