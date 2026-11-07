<?php

declare(strict_types=1);

use App\Actions\Admin\Content\SaveContent;
use App\Actions\Admin\Content\TransitionContent;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\LegalAcceptanceContext;
use App\Enums\TicketCategory;
use App\Models\CommunityPage;
use App\Models\ContactSubmission;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Queries\Content\LegalVersions;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Dusk\Browser;

/*
| P8.1b — A08 / M12 in a real browser at 375 px: a material terms version goes live and a signed-in
| member is stopped at /legal/accept until they agree; the contact form reaches the inbox; the legal,
| contact and community pages and the new A08 screens fit 320 / 768 / 1024 / 1440 px.
| Against the dev server and database: what the test publishes is removed and the live versions it
| replaced are put back afterwards.
*/

const DUSK_CONTACT_EMAIL = 'dusk-contact@example.test';
const DUSK_COMMUNITY_SLUG = 'dusk-matrimony';

function duskLegalCleanup(): void
{
    duskMemberCleanup();   // first: its acceptances hold the Dusk versions (restrictOnDelete)

    $dusk = LegalDocument::query()->where('title', 'like', 'Dusk %')->get();
    foreach ($dusk as $version) {
        LegalAcceptance::query()->where('legal_document_id', $version->id)->delete();
        $version->forceDelete();
    }
    // The seeded versions the Dusk one replaced go live again.
    foreach ($dusk->pluck('kind')->unique() as $kind) {
        $previous = LegalDocument::query()->where('kind', $kind)->where('status', ContentStatus::Archived)->orderByDesc('version')->first();
        $hasLive = LegalDocument::query()->live()->where('kind', $kind)->exists();
        if ($previous !== null && ! $hasLive) {
            $previous->forceFill(['status' => ContentStatus::Published, 'archived_at' => null])->save();
        }
    }
    app(LegalVersions::class)->forget();

    ContactSubmission::query()->where('email', DUSK_CONTACT_EMAIL)->get()->each(fn (ContactSubmission $row) => $row->forceDelete());
    CommunityPage::query()->where('slug', DUSK_COMMUNITY_SLUG)->get()->each(fn (CommunityPage $row) => $row->forceDelete());
    foreach (['127.0.0.1', '::1'] as $ip) {
        RateLimiter::clear('contact:ip:'.$ip);
    }
    duskAdminCleanup();
}

beforeEach(fn () => duskLegalCleanup());
afterEach(fn () => duskLegalCleanup());

/** A material terms version published through the A08 workflow, as an admin does it. */
function duskPublishMaterialTerms(): LegalDocument
{
    $admin = duskAdmin();
    $row = app(SaveContent::class)->handle($admin, ContentType::Legal, null, [
        'sort_order' => 0, 'kind' => 'TERMS', 'title' => 'Dusk Terms of Use', 'summary' => 'Dusk: the refund clause was rewritten.',
        'body' => '<h3>1. About</h3><p>Dusk terms.</p>', 'effective_on' => now()->toDateString(), 'is_material' => true, 'legally_reviewed' => false,
    ]);
    app(TransitionContent::class)->submit($admin, $row);

    return app(TransitionContent::class)->publish($admin, $row->refresh());
}

it('A08: after a material terms change a member is stopped at the accept page until they agree, then goes where they were going', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')->driver->manage()->deleteAllCookies();
        $member = duskLiveMember($browser);   // agreed to the live versions at registration

        $browser->visit('/settings')->waitFor('main#main')->assertPathIs('/settings');

        $terms = duskPublishMaterialTerms();

        $browser->visit('/settings')->waitForLocation('/legal/accept')
            ->assertSee('We’ve updated our terms')->assertSee('Dusk: the refund clause was rewritten.');
        assertNoHorizontalScroll($browser);

        // Every other member page sends them back here, too.
        $browser->visit('/dashboard')->waitForLocation('/legal/accept');

        // Not ticked: refused, nothing recorded.
        $browser->click('@legal-accept')->waitForText('Tick the box to agree to the updated terms.');
        expect(LegalAcceptance::query()->where('user_id', $member->id)->where('legal_document_id', $terms->id)->exists())->toBeFalse();

        assertFitsWidths($browser, url('/favicon.ico'), [url('/legal/accept')]);

        $browser->check('#legal-agree')->click('@legal-accept')->waitForLocation('/dashboard');
        $row = LegalAcceptance::query()->where('user_id', $member->id)->where('legal_document_id', $terms->id)->sole();
        expect($row->version)->toBe($terms->version)->and($row->context)->toBe(LegalAcceptanceContext::Required);

        $browser->visit('/settings')->waitFor('main#main')->assertPathIs('/settings');
    });
});

it('M12: the contact form on a phone reaches the A08 inbox with a reference, and the honeypot stays hidden', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/')->driver->manage()->deleteAllCookies();
        $category = TicketCategory::cases()[0]->value;

        $browser->visit('/contact')->waitUntilMissing('#preloader', 10)->waitFor('#contact-first-name');
        assertNoHorizontalScroll($browser);
        expect($browser->script("const r = document.querySelector('#contact-website').getBoundingClientRect(); return r.width * r.height === 0 || r.right <= 0 || r.bottom <= 0;")[0])->toBeTrue();

        // Missing fields: the form says so and stores nothing.
        $browser->scrollIntoView('.contact-button button')->click('.contact-button button')->waitFor('#contact-message-error');
        expect(ContactSubmission::query()->where('email', DUSK_CONTACT_EMAIL)->exists())->toBeFalse();

        $browser->type('#contact-first-name', 'Dusk')
            ->type('#contact-email', DUSK_CONTACT_EMAIL)
            ->select('#contact-subject', $category)
            ->type('#contact-message', 'Dusk: how do I change my photo privacy?')
            ->scrollIntoView('.contact-button button')->click('.contact-button button')
            ->waitForText('Thank you — we’ve got your message');

        $row = ContactSubmission::query()->where('email', DUSK_CONTACT_EMAIL)->sole();
        $browser->assertSee((string) $row->code);
        assertNoHorizontalScroll($browser);
    });
});

it('the legal, contact and community pages and the new A08 screens fit every width', function (): void {
    CommunityPage::factory()->published()->create(['slug' => DUSK_COMMUNITY_SLUG, 'heading' => 'Dusk Matrimony', 'published_at' => now()]);

    $this->browse(function (Browser $browser): void {
        $public = ['/terms', '/privacy', '/grievance', '/contact', '/'.DUSK_COMMUNITY_SLUG];
        foreach ($public as $path) {
            $browser->visit($path)->waitFor('main#main');
            assertNoHorizontalScroll($browser);
        }
        // (Local isn't indexable, so its sitemap is empty by design: listing is CommunityPagesTest's.)
        $browser->visit('/'.DUSK_COMMUNITY_SLUG)->assertSee('Dusk Matrimony');

        $browser->driver->manage()->deleteAllCookies();
        duskSignInAdmin($browser);

        $admin = ['/content/legal', '/content/ads', '/content/communities', '/content/contact'];
        foreach ($admin as $path) {
            $browser->visit(adminDuskUrl($path))->waitFor('main#main');
            assertNoHorizontalScroll($browser);
        }

        assertFitsWidths($browser, adminDuskUrl('/favicon.ico'), [
            ...array_map(fn (string $path): string => adminDuskUrl($path), $admin),
            ...array_map(fn (string $path): string => url($path), $public),
        ]);
    });
});
