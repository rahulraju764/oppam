<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\Content\StoryData;

/**
 * Demo copy for the public pages, ported verbatim from the template's data files
 * (index.php, about.php, branches.php, stories-data.php). Temporary: it becomes CMS tables
 * edited in A08 (P8.1) — success_stories, testimonials, branches, home blocks — and the
 * "top members" band becomes a query over ACTIVE, verified, photo-public profiles (M12).
 * Nothing here is real personal data.
 */
final class DemoContent
{
    /** @return list<array{name: string, place: string, img: string}> */
    public static function homeMembers(): array
    {
        return [
            ['name' => 'Reshma', 'place' => 'Thrissur',            'img' => 'images/home/profile1.webp'],
            ['name' => 'Anjali', 'place' => 'Kochi',               'img' => 'images/home/profile2.webp'],
            ['name' => 'Ann',    'place' => 'Kozhikode',           'img' => 'images/home/profile3.webp'],
            ['name' => 'Mariya', 'place' => 'Ernakulam',           'img' => 'images/home/profile4.webp'],
            ['name' => 'Neethu', 'place' => 'Trivandrum',          'img' => 'images/home/profile5.webp'],
            ['name' => 'Athira', 'place' => 'Kannur',              'img' => 'images/home/profile6.webp'],
        ];
    }

    /** @return list<array{name: string, place: string, img: string, quote: string}> */
    public static function testimonials(): array
    {
        return [
            ['name' => 'Devika',   'place' => 'Thrissur',   'img' => 'images/home/webp-women-01.webp',
                'quote' => 'Verified profiles and simple messaging made the search stress-free. I found the right person without the noise.'],
            ['name' => 'Martha',   'place' => 'Kochi',      'img' => 'images/home/webp-women-02.webp',
                'quote' => 'The matches suggested to me actually fit. Within weeks I met someone who shared my values and my goals.'],
            ['name' => 'Meera',    'place' => 'Kollam',     'img' => 'images/home/webp-women-03.webp',
                'quote' => 'From registration to the first meeting, the whole thing was smooth. Our families were involved from early on.'],
            ['name' => 'Sreya',    'place' => 'Kozhikode',  'img' => 'images/home/webp-women-04.webp',
                'quote' => 'Genuine profiles and a team that answers. It felt safe to reach out, which is the part I was most worried about.'],
            ['name' => 'Aparna',   'place' => 'Ernakulam',  'img' => 'images/home/profile7.webp',
                'quote' => 'Daily recommendations meant I was never scrolling for hours. Ten minutes a day was enough to find him.'],
            ['name' => 'Lakshmi',  'place' => 'Kannur',     'img' => 'images/home/profile8.webp',
                'quote' => 'My parents trusted it because every profile is checked. That mattered more to us than anything else.'],
        ];
    }

    /** @return list<array{icon: string, title: string, text: string}> */
    public static function values(): array
    {
        return [
            ['icon' => 'fa-shield',      'title' => 'Every profile is verified',
                'text' => 'A profile does not go live until we have checked it. That is the whole reason families trust the site, and it is not negotiable.'],
            ['icon' => 'fa-lock',        'title' => 'Your details stay yours',
                'text' => 'Contact details are never shown on a public profile. You decide who sees them, and you can change your mind.'],
            ['icon' => 'fa-users',       'title' => 'Families, not just individuals',
                'text' => 'A Kerala marriage involves two families. The site is built for that — parents can be part of the conversation from the start.'],
            ['icon' => 'fa-map-marker',  'title' => 'Kerala first',
                'text' => 'Community, language, district and horoscope are first-class fields here, not an afterthought bolted onto a national site.'],
        ];
    }

    /** @return list<array{city: string, role: string, address: string, phone: string, tel: string, hours: string}> */
    public static function branches(): array
    {
        return [
            ['city' => 'Thrissur',            'role' => 'Head office',
                'address' => 'Round South, Thrissur, Kerala 680001',
                'phone' => '0487 244 1100',   'tel' => '+914872441100',
                'hours' => 'Mon–Sat, 9:30am – 6:00pm'],
            ['city' => 'Kochi',               'role' => 'Regional office',
                'address' => 'MG Road, Ernakulam, Kochi, Kerala 682035',
                'phone' => '0484 240 1100',   'tel' => '+914842401100',
                'hours' => 'Mon–Sat, 9:30am – 6:00pm'],
            ['city' => 'Thiruvananthapuram',  'role' => 'Regional office',
                'address' => 'Vazhuthacaud, Thiruvananthapuram, Kerala 695014',
                'phone' => '0471 233 1100',   'tel' => '+914712331100',
                'hours' => 'Mon–Sat, 9:30am – 6:00pm'],
            ['city' => 'Kozhikode',           'role' => 'Branch',
                'address' => 'Mavoor Road, Kozhikode, Kerala 673004',
                'phone' => '0495 276 1100',   'tel' => '+914952761100',
                'hours' => 'Mon–Sat, 10:00am – 6:00pm'],
            ['city' => 'Kannur',              'role' => 'Branch',
                'address' => 'Fort Road, Kannur, Kerala 670001',
                'phone' => '0497 270 1100',   'tel' => '+914972701100',
                'hours' => 'Mon–Sat, 10:00am – 6:00pm'],
            ['city' => 'Kollam',              'role' => 'Branch',
                'address' => 'Chinnakada, Kollam, Kerala 691001',
                'phone' => '0474 275 1100',   'tel' => '+914742751100',
                'hours' => 'Mon–Sat, 10:00am – 6:00pm'],
            ['city' => 'Palakkad',            'role' => 'Branch',
                'address' => 'Stadium Bypass Road, Palakkad, Kerala 678014',
                'phone' => '0491 250 1100',   'tel' => '+914912501100',
                'hours' => 'Mon–Sat, 10:00am – 6:00pm'],
            ['city' => 'Alappuzha',           'role' => 'Branch',
                'address' => 'Mullackal, Alappuzha, Kerala 688011',
                'phone' => '0477 226 1100',   'tel' => '+914772261100',
                'hours' => 'Mon–Sat, 10:00am – 6:00pm'],
        ];
    }

    /** @return list<StoryData> */
    public static function stories(): array
    {
        return array_map(static fn (array $story): StoryData => new StoryData(
            slug: $story['slug'],
            couple: $story['couple'],
            place: $story['place'],
            date: $story['date'],
            imageUrl: asset($story['img']),
            imageWidth: $story['w'],
            imageHeight: $story['h'],
            quote: $story['quote'],
            paragraphs: $story['story'],
        ), self::storyRows());
    }

    /**
     * The FAQ on /plans#faq (template package.php) until faq_items exists (A08, P8.1).
     *
     * @return list<array{question: string, answer: string}>
     */
    public static function faqs(): array
    {
        return [
            ['question' => 'How do I create a matrimony profile?', 'answer' => 'Create your profile for free by adding your details, preferences, and photos. Start connecting with suitable matches today.'],
            ['question' => 'How do I verify my profile?', 'answer' => 'You can verify your profile by submitting the required identity documents and contact details. Verified profiles help build trust and improve match quality.'],
            ['question' => 'How can I search for suitable matches?', 'answer' => 'Use our advanced search filters to find matches based on age, religion, community, education, profession, location, and other preferences.'],
            ['question' => 'Can I update my profile after registration?', 'answer' => 'Yes, you can update your profile details, photos, preferences, and contact information at any time through your account dashboard.'],
            ['question' => 'Is my personal information secure?', 'answer' => 'Yes, we prioritize your privacy and security. Your personal information is protected, and you have full control over who can view your profile and contact details.'],
            ['question' => 'How can I contact a matched profile?', 'answer' => 'Once you find a suitable match, you can send an interest request or communicate directly through our secure messaging system, depending on your membership plan.'],
        ];
    }

    /** @return list<array{slug: string, couple: string, place: string, date: string, img: string, w: int, h: int, quote: string, story: list<string>}> */
    private static function storyRows(): array
    {
        return [
            [
                'slug' => 'allen-riya',
                'couple' => 'Allen & Riya',
                'place' => 'Thrissur',
                'date' => 'Married January 2024',
                'img' => 'images/profile/story-couple.webp',
                'w' => 500,
                'h' => 500,
                'quote' => 'We matched in the first week. Six months later both families met in Thrissur.',
                'story' => [
                    'Riya had been on two other sites for the better part of a year and had almost given up on the whole idea. Her sister made the Oppam profile for her, filled in half the details wrong, and left it at that.',
                    'Allen sent an interest on the third day. What made the difference, she says, was that the profile was verified — her parents could see the badge, and that was the end of the argument about whether any of this was safe.',
                    'The families met in Thrissur in the monsoon, which nobody had planned for, and the wedding was in January.',
                ],
            ],
            [
                'slug' => 'vishnu-anjali',
                'couple' => 'Vishnu & Anjali',
                'place' => 'Kochi',
                'date' => 'Married March 2024',
                'img' => 'images/details/001.jpg',
                'w' => 600,
                'h' => 600,
                'quote' => 'The daily recommendations did the work. Ten minutes an evening, and one of them was him.',
                'story' => [
                    'Anjali is a consultant and travels most weeks, so scrolling through hundreds of profiles was never going to happen. The daily recommendations were the only part of the site she actually used.',
                    'Vishnu turned up in that list in October. They talked for a month before either family knew anything about it, which she recommends to everyone.',
                    'They married in Kochi in March, and her mother has since made profiles for two cousins.',
                ],
            ],
            [
                'slug' => 'arun-meera',
                'couple' => 'Arun & Meera',
                'place' => 'Kozhikode',
                'date' => 'Married August 2023',
                'img' => 'images/details/s1.jpg',
                'w' => 400,
                'h' => 400,
                'quote' => 'Both sets of parents were on the call. That is not something we could have done over a phone number.',
                'story' => [
                    'Arun was working in Bangalore and Meera in Kozhikode, so the first four conversations were video calls — twice with both sets of parents sitting in.',
                    'They credit the partner-preference filters for the fact that the shortlist was short. Caste, language and the fact that neither wanted to leave Kerala permanently ruled out most of it before anyone spoke.',
                    'The wedding was in August at Meera\'s family temple, and Arun has since moved back to Kozhikode.',
                ],
            ],
            [
                'slug' => 'jithin-sneha',
                'couple' => 'Jithin & Sneha',
                'place' => 'Thiruvananthapuram',
                'date' => 'Married November 2023',
                'img' => 'images/family/02.jpg',
                'w' => 400,
                'h' => 400,
                'quote' => 'I was the one who sent the interest first. Nobody warned me that was allowed.',
                'story' => [
                    'Sneha is a doctor and had a very specific idea of the hours a partner would have to tolerate. She sent the first interest, which she says she would never have done on a site where the messaging felt public.',
                    'Jithin replied the same evening. They met properly at a wedding neither of them wanted to attend, six weeks later.',
                    'They married in Thiruvananthapuram in November.',
                ],
            ],
            [
                'slug' => 'rahul-divya',
                'couple' => 'Rahul & Divya',
                'place' => 'Ernakulam',
                'date' => 'Married June 2024',
                'img' => 'images/family/03.jpg',
                'w' => 400,
                'h' => 400,
                'quote' => 'Two weeks of talking, one meeting, and my father was already looking at dates.',
                'story' => [
                    'Divya\'s profile had been live for three days. Rahul\'s was six months old and, by his own account, largely ignored until he finally added photographs.',
                    'They were matched on the horoscope details as well as everything else, which mattered a great deal to one family and not at all to the other — they got past it.',
                    'The wedding was in Ernakulam in June, and both of them still argue about who noticed the other first.',
                ],
            ],
            [
                'slug' => 'nikhil-aparna',
                'couple' => 'Nikhil & Aparna',
                'place' => 'Kannur',
                'date' => 'Married February 2024',
                'img' => 'images/family/04.webp',
                'w' => 400,
                'h' => 400,
                'quote' => 'We are both second marriages. That was the filter that made this site different.',
                'story' => [
                    'Both had been married before, and both had spent a while on sites where that fact turned every conversation into an interrogation.',
                    'Aparna says the difference was simply that marital status was a field, set once, and visible before anyone got in touch. Nobody had to explain themselves twice.',
                    'They married quietly in Kannur in February with about thirty people present.',
                ],
            ],
        ];
    }
}
