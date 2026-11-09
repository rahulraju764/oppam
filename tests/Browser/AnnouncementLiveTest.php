<?php

declare(strict_types=1);

use App\Actions\Admin\Comms\EndAnnouncement;
use App\Actions\Admin\Comms\SaveAnnouncement;
use App\Data\Comms\AnnouncementData;
use App\Enums\AnnouncementAudience;
use App\Models\Announcement;
use App\Models\AnnouncementDismissal;
use Laravel\Dusk\Browser;

/*
| P8.3a — A14 announcements live: an admin publishes, and a member page that is already open shows
| the banner without a reload (private `announcements` channel over Reverb); Dismiss hides it;
| ending it removes it live. Then the banner at 320 / 375 / 768 / 1024 / 1440 px. Needs `composer dev`.
*/

function duskAnnouncementCleanup(): void
{
    $codes = Announcement::query()->where('title', 'like', 'Dusk %')->pluck('id');
    AnnouncementDismissal::query()->whereIn('announcement_id', $codes)->delete();
    Announcement::query()->whereIn('id', $codes)->delete();
    app(App\Domain\Comms\ActiveAnnouncements::class)->forget();
}

beforeEach(function (): void {
    duskMemberCleanup();
    duskAnnouncementCleanup();
});
afterEach(function (): void {
    duskAnnouncementCleanup();
    duskMemberCleanup();
    duskAdminCleanup();
});

function duskAnnouncement(string $title, bool $dismissible = true): Announcement
{
    return app(SaveAnnouncement::class)->handle(duskAdmin(), new AnnouncementData(
        title: $title, body: 'A very long Onam offer text that must wrap on a small phone screen without pushing the page sideways.',
        actionUrl: '/plans', actionText: 'See plans', startsAt: null, endsAt: null,
        audience: AnnouncementAudience::All, isDismissible: $dismissible,
    ));
}

it('A14: a published announcement appears on an open member page live, dismiss hides it, ending removes it', function (): void {
    $this->browse(function (Browser $browser): void {
        duskLiveMember($browser);

        $browser->resize(375, 812)->visit('/dashboard')
            ->waitUsing(15, 100, fn (): bool => (bool) $browser->script(
                "return !!window.Echo?.connector?.pusher?.channel('private-announcements')?.subscribed;")[0]);

        $first = duskAnnouncement('Dusk Onam offer');
        $browser->waitFor('@announcement-'.$first->code, 5)->assertSee('Dusk Onam offer');
        assertNoHorizontalScroll($browser);

        $browser->click('@announcement-'.$first->code.' .announcement-bar__close')
            ->waitUntilMissing('@announcement-'.$first->code, 5);

        $second = duskAnnouncement('Dusk maintenance tonight', dismissible: false);
        $browser->waitFor('@announcement-'.$second->code, 5)
            ->assertMissing('@announcement-'.$second->code.' .announcement-bar__close');

        app(EndAnnouncement::class)->handle(duskAdmin(), $second);
        $browser->waitUntilMissing('@announcement-'.$second->code, 5);
    });
});

it('the banner fits 320 / 768 / 1024 / 1440 px', function (): void {
    $this->browse(function (Browser $browser): void {
        duskLiveMember($browser);
        $row = duskAnnouncement('Dusk width check');

        foreach ([320, 768, 1024, 1440] as $width) {
            $browser->resize($width, 900)->visit('/dashboard')->waitFor('@announcement-'.$row->code, 15);
            assertNoHorizontalScroll($browser);
        }
    });
});
