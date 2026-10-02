<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Moderation (PRD §11 A04)
|--------------------------------------------------------------------------
| Claim length, duplicate thresholds and the profanity word list used by the automatic
| pre-flags (App\Domain\Moderation\ModerationFlags). Flags only HELP a moderator — they never
| reject on their own, except underage (A04 "underage → auto-reject").
| The word lists are deliberately short seeds: extend them here (lower case, whole words).
*/

return [

    'claim_minutes' => 15,

    // Perceptual-hash distance (bits of 64) at or below which two photos count as the same picture.
    'duplicate_photo_distance' => 6,

    'profanity' => [
        'en' => ['fuck', 'shit', 'bitch', 'bastard', 'asshole', 'slut', 'whore', 'dick', 'pussy', 'cunt'],
        'ml' => ['പട്ടി', 'തെണ്ടി', 'പൂറി', 'മൈര്', 'കഴുവേറി', 'പന്നി'],
    ],

];
