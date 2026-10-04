<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Moderation (PRD §11 A04)
|--------------------------------------------------------------------------
| Claim length and duplicate thresholds used by the automatic pre-flags
| (App\Domain\Moderation\ModerationFlags). Flags only HELP a moderator — they never reject on
| their own, except underage (A04 "underage → auto-reject"). The profanity word lists (EN / ML /
| Manglish) are master data since P1.8: edited in A11, seeded from database/seeders/data/masters.php.
*/

return [

    'claim_minutes' => 15,

    // Perceptual-hash distance (bits of 64) at or below which two photos count as the same picture.
    'duplicate_photo_distance' => 6,

];
