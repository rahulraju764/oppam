<?php

declare(strict_types=1);

use App\Domain\Moderation\ModerationFlags;
use App\Models\Profile;

/*
| P1.6 — A04 automatic pre-flags: contact details in text, profanity (EN + ML), underage,
| duplicate identity (name + DOB), duplicate photo (perceptual hash).
*/

beforeEach(function (): void {
    seedMasters();
    fakeMediaDisks();
});

function flagCodes(array $flags): array
{
    return array_map(fn ($flag) => $flag->code, $flags);
}

it('flags contact details written into text', function (string $text): void {
    expect(flagCodes(app(ModerationFlags::class)->forTexts(['about me' => $text])))->toContain('contact_info');
})->with([
    'mobile' => ['Call 98470 12345 after 6'],
    'international' => ['My number is +971-50-123-4567'],
    'email' => ['Write to anju.nair@gmail.com'],
    'whatsapp' => ['Ping me on WhatsApp'],
    'instagram' => ['Find me on insta'],
]);

it('flags profanity in English and Malayalam, whole words only', function (): void {
    $flags = app(ModerationFlags::class);

    expect(flagCodes($flags->forTexts(['about' => 'What the fuck'])))->toContain('profanity')
        ->and(flagCodes($flags->forTexts(['about' => 'അവൻ ഒരു പട്ടി ആണ്'])))->toContain('profanity')
        ->and(flagCodes($flags->forTexts(['about' => 'I live in Scunthorpe and love dickens novels'])))->not->toContain('profanity');
});

it('does not flag ordinary text', function (): void {
    expect(app(ModerationFlags::class)->forTexts(['about' => 'I am a teacher from Kottayam who loves music and travel.', 'empty' => null]))->toBe([]);
});

it('flags the same name and date of birth on another profile', function (): void {
    $one = memberThroughStep(1)->profile()->firstOrFail();
    $two = memberThroughStep(1)->profile()->firstOrFail();

    $flags = app(ModerationFlags::class)->forProfile($two, []);

    expect(flagCodes($flags))->toContain('duplicate_identity')
        ->and($flags[0]->message)->toContain($one->code);
});

it('flags an underage profile', function (): void {
    $profile = memberThroughStep(1)->profile()->firstOrFail();
    $profile->forceFill(['dob' => now()->subYears(17)->toDateString()])->save();

    expect(flagCodes(app(ModerationFlags::class)->forProfile($profile->refresh(), [])))->toContain('underage');
});

it('flags a photo that is the same picture as one on another profile, not on the same profile', function (): void {
    $original = addTestPhoto(memberThroughStep(5), 600, 800);
    $sameProfileAgain = addTestPhoto(Profile::query()->whereKey($original->model_id)->firstOrFail()->user, 600, 800);
    $copy = addTestPhoto(memberThroughStep(5), 600, 800);    // identical generated image on another profile

    $flags = app(ModerationFlags::class);

    expect(flagCodes($flags->forPhoto($copy)))->toContain('duplicate_photo')
        ->and($original->phash)->toBe($sameProfileAgain->phash);
});
