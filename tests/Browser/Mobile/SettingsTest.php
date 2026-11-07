<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\PrivacySetting;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Dusk\Browser;

/*
| P8.2a — /settings (PRD M14) in a real browser at 375 px: the Privacy tab saves (hide from search),
| the tabs switch in place, the Notifications matrix scrolls inside its own box and saves, the
| Blocked tab shows its empty state; then every tab at 320 / 768 / 1024 / 1440 px.
| Against the dev server and database: the Dusk member is removed before and after.
*/

function duskSettingsCleanup(): void
{
    $user = App\Models\User::query()->where('phone', DUSK_MEMBER_PHONE)->first();
    if ($user !== null) {
        RateLimiter::clear('notification-settings:'.$user->id);
        if ($user->profile !== null) {
            RateLimiter::clear('privacy-settings:'.$user->profile->id);
        }
    }
    duskMemberCleanup();
}

/** Centre an element on screen: a top-aligned one would sit under the sticky mobile header. */
function duskCentre(Browser $browser, string $selector): Browser
{
    $browser->script('document.querySelector('.json_encode($selector).').scrollIntoView({block: "center"});');

    return $browser;
}

beforeEach(fn () => duskSettingsCleanup());
afterEach(fn () => duskSettingsCleanup());

it('M14 at 375 px: privacy and notification choices save, tabs switch without a reload', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')->driver->manage()->deleteAllCookies();
        $member = duskLiveMember($browser);

        $browser->visit('/settings')->waitFor('@privacy-save', 15);
        assertNoHorizontalScroll($browser);

        duskCentre($browser, '#privacyHide')->check('#privacyHide');
        duskCentre($browser, '[dusk=privacy-save]')->click('@privacy-save')->waitForText('Your privacy settings are saved.');
        expect(PrivacySetting::query()->findOrFail($member->profile->id)->hide_from_search)->toBeTrue();

        // Tabs switch in place: the page isn't reloaded (a marker set on window survives).
        $browser->script('window.__settingsMarker = 1;');
        $browser->script('window.scrollTo(0, 0);');   // the tabs sit at the top; scrollIntoView would park them under the sticky header
        $browser->click('@settings-tab-notifications')->waitFor('@notifications-save', 15);
        expect($browser->script('return window.__settingsMarker === 1 && location.search;')[0])->toBe('?tab=notifications');
        assertNoHorizontalScroll($browser);   // the matrix scrolls inside .nt-matrix-wrap, not the page

        duskCentre($browser, '[dusk=nt-interest_received-sms]')->uncheck('@nt-interest_received-sms');
        duskCentre($browser, '[dusk=notifications-save]')->click('@notifications-save')->waitForText('Your notification settings are saved.');
        expect(NotificationPreference::query()->where('user_id', $member->id)->where('event', 'interest_received')->sole()->sms)->toBeFalse();

        $browser->script('window.scrollTo(0, 0);');
        $browser->click('@settings-tab-blocked')->waitForText('You haven’t blocked anyone', 15);
        assertNoHorizontalScroll($browser);
    });
});

it('every /settings tab fits 320 / 768 / 1024 / 1440 px', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')->driver->manage()->deleteAllCookies();
        duskLiveMember($browser);

        assertFitsWidths($browser, url('/favicon.ico'), [url('/settings'), url('/settings?tab=notifications'), url('/settings?tab=blocked')]);

        // assertFitsWidths waits for the page only; the tabs load lazily, so check them loaded at 320 px too.
        $browser->resize(320, 800);
        foreach (['/settings' => '@privacy-save', '/settings?tab=notifications' => '@notifications-save', '/settings?tab=blocked' => '.safety-list__title'] as $path => $loaded) {
            $browser->visit($path)->waitFor($loaded, 15);
            assertNoHorizontalScroll($browser);
        }
    });
});
