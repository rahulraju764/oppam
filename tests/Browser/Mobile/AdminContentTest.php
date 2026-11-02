<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Models\FaqItem;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

/*
| P8.1a — A08 in a real browser at 375 px: a FAQ is written in the rich-text editor, sent for review,
| previewed by signed link and published; the public page shows it only then. Every A08 screen and the
| DB-driven public pages fit 320 / 768 / 1024 / 1440 px.
*/

function duskContentCleanup(): void
{
    FaqItem::query()->where('question', 'like', 'Dusk %')->delete();
    duskAdminCleanup();
}

beforeEach(fn () => duskContentCleanup());
afterEach(fn () => duskContentCleanup());

it('A08: write in the editor, send for review, preview, publish, and only then is it on the site', function (): void {
    $this->browse(function (Browser $browser): void {
        $question = 'Dusk '.Str::random(10).'?';

        $browser->visit('/')->driver->manage()->deleteAllCookies();
        duskSignInAdmin($browser);

        $browser->visit(adminDuskUrl('/content/faq'))->waitFor('@content-new');
        assertNoHorizontalScroll($browser);

        $browser->click('@content-new')->waitFor('@content-save')
            ->type('#faq-question', $question)
            ->click('@rich-answer')->keys('@rich-answer', 'Answer from the editor')
            ->click('@content-save')->waitForText($question);

        $row = FaqItem::query()->where('question', $question)->sole();
        expect($row->status)->toBe(ContentStatus::Draft)
            ->and($row->answer)->toContain('Answer from the editor');

        // Reopening shows what was saved, and a new item starts empty.
        $browser->visit(adminDuskUrl('/content/faq'))->waitFor('@content-edit-'.$row->code)
            ->click('@content-edit-'.$row->code)->waitFor('@rich-answer')->waitForTextIn('@rich-answer', 'Answer from the editor')
            ->script("window.dispatchEvent(new CustomEvent('close-modal', {detail: {name: 'content-form'}}));");
        $browser->pause(300)->click('@content-new')->waitFor('@content-save');
        $browser->waitUsing(5, 100, fn (): bool => $browser->script("return document.querySelector('#rich-answer').innerText.trim() === '';")[0] === true);

        $browser->visit('/plans')->assertDontSee($question);

        $browser->visit(adminDuskUrl('/content/faq'))->waitFor('@content-submit-'.$row->code)
            ->click('@content-submit-'.$row->code)->waitFor('@content-preview-'.$row->code);
        $browser->waitUsing(10, 100, fn (): bool => $row->refresh()->status === ContentStatus::InReview);

        $browser->click('@content-preview-'.$row->code)->waitFor('@content-preview-link');
        $preview = (string) $browser->attribute('@content-preview-link', 'href');

        $browser->visit($preview)->waitForText('Preview: this page shows unpublished content')->assertSourceHas($question);
        assertNoHorizontalScroll($browser);

        $browser->visit('/plans')->assertSourceMissing($question);

        $browser->visit(adminDuskUrl('/content/faq'))->waitFor('@content-publish-'.$row->code)
            ->click('@content-publish-'.$row->code);
        $browser->waitUsing(10, 100, fn (): bool => $row->refresh()->status === ContentStatus::Published);

        $browser->visit('/plans')->assertSourceHas($question)->assertSourceHas('Answer from the editor');
    });
});

it('the DB-driven home page runs its carousel, and A08 and the public pages fit every width', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')->waitFor('.basement-carousel')
            ->assertScript('document.querySelector(".basement-carousel").swiper !== undefined')
            ->assertScript('document.querySelectorAll(".basement-content.swiper-slide").length > 0');
        assertNoHorizontalScroll($browser);

        $public = ['/about', '/branches', '/success-stories', '/plans'];
        foreach ($public as $path) {
            $browser->visit($path)->waitFor('main#main');
            assertNoHorizontalScroll($browser);
        }

        $browser->driver->manage()->deleteAllCookies();
        duskSignInAdmin($browser);

        foreach (['/content/home-blocks', '/content/banners', '/content/stories', '/content/testimonials', '/content/branches'] as $path) {
            $browser->visit(adminDuskUrl($path))->waitFor('main#main');
            assertNoHorizontalScroll($browser);
        }

        assertFitsWidths($browser, adminDuskUrl('/favicon.ico'), [
            adminDuskUrl('/content/home-blocks'),
            adminDuskUrl('/content/banners'),
            adminDuskUrl('/content/stories'),
            adminDuskUrl('/content/testimonials'),
            adminDuskUrl('/content/branches'),
            adminDuskUrl('/content/faq'),
            ...array_map(fn (string $path): string => url($path), ['/', ...$public]),
        ]);
    });
});
