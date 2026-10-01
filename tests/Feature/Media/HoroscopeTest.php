<?php

declare(strict_types=1);

use App\Actions\Profile\Photos\DeleteHoroscope;
use App\Actions\Profile\Photos\UploadHoroscope;
use App\Domain\Media\HoroscopeAccess;
use App\Enums\HoroscopeVisibility;
use App\Models\AuditLog;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/*
| P1.4 — horoscope file (M11, CLAUDE.md security rule 7): private disk, short-lived signed link,
| access re-checked and audited on every view.
*/

beforeEach(function (): void {
    seedMasters();
    fakeMediaDisks();
    $this->seed(Database\Seeders\PlansSeeder::class);   // error pages render the member header (plan)
});

/** A minimal, real PDF (content sniffing must see %PDF-). */
const TEST_PDF = '%PDF-1.4
1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj
2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj
trailer << /Root 1 0 R >>
%%EOF
';

function memberWithHoroscope(HoroscopeVisibility $visibility = HoroscopeVisibility::AllMembers): User
{
    $user = memberThroughStep(5);
    app(UploadHoroscope::class)->handle($user, $user->profile()->firstOrFail(), UploadedFile::fake()->createWithContent('jathakam.pdf', TEST_PDF));
    $privacy = App\Models\PrivacySetting::query()->whereKey($user->profile->id)->first() ?? new App\Models\PrivacySetting;
    $privacy->forceFill(['profile_id' => $user->profile->id, 'horoscope_visibility' => $visibility])->save();

    return $user->refresh();
}

function horoscopeLinkFor(User $owner, ?User $viewer): ?string
{
    return app(HoroscopeAccess::class)->link($owner->profile()->firstOrFail()->unsetRelations(), $viewer);
}

it('stores the horoscope on the private disk under a random name', function (): void {
    $owner = memberWithHoroscope();
    $file = $owner->profile()->firstOrFail()->getFirstMedia(Profile::HOROSCOPE);

    expect($file?->disk)->toBe(config('oppam.media.private_disk'))
        ->and($file?->file_name)->not->toContain('jathakam')
        ->and(Storage::disk((string) config('oppam.media.private_disk'))->exists((string) $file?->getPathRelativeToRoot()))->toBeTrue();
});

it('the owner gets a signed link that streams the file without caching, and each view is audited', function (): void {
    $owner = memberWithHoroscope();
    $link = horoscopeLinkFor($owner, $owner);

    expect($link)->toContain('signature=');

    $this->actingAs($owner, 'web')->get($link)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', 'sandbox');

    expect(AuditLog::query()->where('action', 'horoscope.viewed')->where('subject_label', $owner->profile->code)->count())->toBe(1);
});

it('refuses an unsigned, tampered or expired link', function (Closure $mangle): void {
    $owner = memberWithHoroscope();

    $this->actingAs($owner, 'web')->get($mangle(horoscopeLinkFor($owner, $owner), $owner))->assertForbidden();
})->with([
    'unsigned' => fn (string $link, User $owner) => route('member.horoscope', ['profile' => $owner->profile->code]),
    'tampered' => fn (string $link) => $link.'0',
    'expired' => function (string $link, User $owner) {
        test()->travel(10)->minutes();

        return $link;
    },
]);

it('applies horoscope_visibility: all members get a link, "accepted only" gives no link and a 404 on a borrowed one', function (): void {
    $open = memberWithHoroscope(HoroscopeVisibility::AllMembers);
    $closed = memberWithHoroscope(HoroscopeVisibility::AcceptedOnly);
    $viewer = memberWithPhone('+919800000011');

    expect(horoscopeLinkFor($open, $viewer))->not->toBeNull()
        ->and(horoscopeLinkFor($closed, $viewer))->toBeNull();

    // The owner's own (valid) signed link, used by someone else: access is checked again → 404.
    $this->actingAs($viewer, 'web')->get(horoscopeLinkFor($closed, $closed))->assertNotFound();
    expect(AuditLog::query()->where('action', 'horoscope.viewed')->count())->toBe(0);
});

it('guests are sent to log in and brokers get 404', function (): void {
    $owner = memberWithHoroscope();
    $link = horoscopeLinkFor($owner, $owner);

    $this->get($link)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->broker()->create(), 'web')->get($link)->assertNotFound();
});

it('refuses a horoscope that is not a PDF / JPG / PNG, and removes it on request', function (): void {
    $owner = memberWithHoroscope();
    $profile = $owner->profile()->firstOrFail();

    expect(fn () => app(UploadHoroscope::class)->handle($owner, $profile, UploadedFile::fake()->createWithContent('h.pdf', 'MZ fake exe')))
        ->toThrow(ValidationException::class);

    app(DeleteHoroscope::class)->handle($owner, $profile);

    expect($profile->refresh()->getFirstMedia(Profile::HOROSCOPE))->toBeNull()
        ->and(horoscopeLinkFor($owner, $owner))->toBeNull();
});

it('a signed link for a profile without a horoscope is a 404', function (): void {
    $owner = memberThroughStep(5);
    $link = URL::temporarySignedRoute('member.horoscope', now()->addMinutes(5), ['profile' => $owner->profile->code]);

    $this->actingAs($owner, 'web')->get($link)->assertNotFound();
});

it('re-encodes an image horoscope so EXIF / GPS data is not kept', function (): void {
    $owner = memberThroughStep(5);

    $media = app(UploadHoroscope::class)->handle($owner, $owner->profile()->firstOrFail(), jpegWithGps('jathakam.jpg'));

    $stored = (string) Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
    expect($media->mime_type)->toBe('image/jpeg')
        ->and($stored)->not->toContain('GPSLatitude');
});
