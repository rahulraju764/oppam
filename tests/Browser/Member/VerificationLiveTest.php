<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P6.1 — F05 in real browsers over Reverb (needs `composer dev`): a member sends an ID on /verify;
| an officer opens it in the A05 review, views the watermarked document and approves; the
| member's page shows the Verified badge WITHOUT a reload.
*/

beforeEach(function (): void {
    duskChatCleanup();
});

afterEach(function (): void {
    duskChatCleanup();
    duskAdminCleanup();
});

it('F05: upload → officer views + approves → the Verified badge appears live on /verify', function (): void {
    $this->browse(function (Browser $member, Browser $admin): void {
        $groom = duskSecondMember();

        duskSignInSecond($member, '/verify');
        $member->waitForText('Upload your ID')
            ->select('#vfDocType', 'AADHAAR')
            ->type('#vfDocNumber', TEST_AADHAAR)
            ->attach('#vfDocFront', duskPhotoFixture())
            ->waitUntilMissingText('Uploading…')
            ->check('#vfConsent')
            ->press('@verify-submit')
            ->waitForText('Your document is being checked')
            ->waitUsing(20, 100, fn (): bool => (bool) $member->script(
                "return window.Echo?.connector?.channels?.['private-App.Models.User.{$groom->id}']?.subscription?.subscribed === true;")[0]);
        $member->script('window.__samePage = true;');

        duskSignInAdmin($admin);
        $admin->visit(adminDuskUrl('/verification'))
            ->waitForText($groom->profile->code)
            ->visit(adminDuskUrl('/verification/'.$groom->profile->code))
            ->waitForText('Automatic checks')
            ->press('@view-documents')
            ->waitFor('.vf-viewer img')
            ->waitUsing(10, 100, fn (): bool => (bool) $admin->script("return document.querySelector('.vf-viewer img')?.naturalWidth > 0;")[0])
            ->assertSee('Access is logged')
            ->press('@verify-approve')
            ->waitForText('the Verified badge is on');

        $member->waitForText('You’re verified', 10)->assertSee('Verified');
        expect((bool) $member->script('return window.__samePage === true;')[0])->toBeTrue();   // no reload
    });
});
