<?php

declare(strict_types=1);

use App\Enums\ContactStatus;
use App\Enums\ContentStatus;
use App\Enums\LegalKind;
use App\Livewire\Admin\Contact\Inbox;
use App\Livewire\Admin\Content\ContentIndex;
use App\Livewire\Member\Legal\UpdateNotice;
use App\Livewire\Public\ContactForm;
use App\Models\AdCreative;
use App\Models\ContactSubmission;
use App\Models\LegalDocument;
use App\Services\Captcha\TurnstileVerifier;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/* P8.1b screens: A08 legal versions (new version, compare, fixed once published), ads, contact inbox. */

beforeEach(function (): void {
    seedAdminRoles();
    Notification::fake();
});

it('A08 legal: a published version offers New version and Compare, never Edit or Archive', function (): void {
    $this->actingAs(contentAdmin('content.edit', 'content.publish'), 'admin');
    $live = LegalDocument::factory()->live()->create();

    Livewire::test(ContentIndex::class, ['type' => 'legal'])
        ->assertSee('New version')->assertSee('Compare')
        ->assertDontSeeHtml('dusk="content-edit-'.$live->code.'"')
        ->assertDontSeeHtml("confirm('".$live->code."', 'content-archive')");
});

it('A08 legal: New version starts from the published text, as a new draft numbered next', function (): void {
    $this->actingAs(contentAdmin('content.edit'), 'admin');
    $live = LegalDocument::factory()->live()->create(['body' => '<p class="p-main">Old clause.</p>']);

    Livewire::test(ContentIndex::class, ['type' => 'legal'])
        ->call('newVersion', $live->code)
        ->assertSet('editing', null)->assertSet('fields.kind', 'TERMS')
        ->set('fields.summary', 'Clause 7 rewritten')->set('fields.effective_on', '2026-12-01')
        ->call('save')->assertHasNoErrors();

    $next = LegalDocument::query()->where('version', 2)->sole();
    expect($next->status)->toBe(ContentStatus::Draft)->and($next->body)->toContain('Old clause.')
        ->and($live->refresh()->isLive())->toBeTrue();
});

it('A08 legal: Compare shows what was added and removed against the version before', function (): void {
    $this->actingAs(contentAdmin('content.edit'), 'admin');
    LegalDocument::factory()->archived()->create(['version' => 1, 'published_at' => now()->subMonth(), 'body' => '<p class="p-main">Kept.</p><p class="p-main">Dropped.</p>']);
    $v2 = LegalDocument::factory()->live()->create(['version' => 2, 'body' => '<p class="p-main">Kept.</p><p class="p-main">Added clause.</p>']);

    Livewire::test(ContentIndex::class, ['type' => 'legal'])
        ->call('compare', $v2->code)
        ->assertSeeHtml('is-added')->assertSee('Added clause.')
        ->assertSeeHtml('is-removed')->assertSee('Dropped.');
});

it('A08 legal: the compared version is locked against tampering', function (): void {
    $this->actingAs(contentAdmin('content.edit'), 'admin');

    expect(fn () => Livewire::test(ContentIndex::class, ['type' => 'legal'])->set('comparing', 'LGLOTHER'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('A08 ads: a new ad opens with weight 1 and shows IST dates when edited', function (): void {
    $this->actingAs(contentAdmin('content.edit'), 'admin');
    $ad = AdCreative::factory()->create(['starts_at' => '2026-12-01 04:00:00']);

    Livewire::test(ContentIndex::class, ['type' => 'ads'])
        ->call('create')->assertSet('fields.weight', 1)
        ->call('edit', $ad->code)->assertSet('fields.starts_at', '2026-12-01T09:30')->assertSet('fields.slot', 'PROMO');
});

it('A08 contact inbox: the page is behind support.view', function (): void {
    signInAdmin($this, contentAdmin('content.edit'));
    $this->get(adminUrl('/content/contact'))->assertForbidden();

    signInAdmin($this, contentAdmin('support.view'));
    $this->get(adminUrl('/content/contact'))->assertOk()->assertSee('Contact inbox');
});

it('A08 contact inbox: lists open messages, spam in its own tab, and an empty state', function (): void {
    $this->actingAs(contentAdmin('support.view'), 'admin');
    ContactSubmission::factory()->create(['name' => 'Open Person']);
    ContactSubmission::factory()->spam()->create(['name' => 'Spam Person']);

    Livewire::test(Inbox::class)
        ->assertSee('Open Person')->assertDontSee('Spam Person')
        ->set('tab', 'spam')->assertSee('Spam Person')->assertDontSee('Open Person')
        ->set('tab', 'converted')->assertSee('No contact messages in this tab.')
        ->set('tab', 'bogus')->assertSet('tab', 'open');
});

it('A08 contact inbox: reply from the drawer, with the message text escaped', function (): void {
    $this->actingAs(contentAdmin('support.view', 'support.respond'), 'admin');
    $message = ContactSubmission::factory()->create(['message' => '<script>alert(1)</script> Hello']);

    Livewire::test(Inbox::class)
        ->call('open', $message->code)
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')->assertDontSeeHtml('<script>alert(1)')
        ->set('reply', 'Thanks, we will call you.')->call('sendReply')
        ->assertHasNoErrors()->assertDispatched('toast');

    expect($message->refresh()->status)->toBe(ContactStatus::Replied);
});

it('A08 contact inbox: a viewer without support.respond sees no reply form and is refused', function (): void {
    $this->actingAs(contentAdmin('support.view'), 'admin');
    $message = ContactSubmission::factory()->create();

    Livewire::test(Inbox::class)->call('open', $message->code)->assertDontSee('Send reply')->call('convert')->assertForbidden();
    expect($message->refresh()->status)->toBe(ContactStatus::New);
});

it('A08 contact inbox: the open message is locked, and an unknown code is a 404', function (): void {
    $this->actingAs(contentAdmin('support.view', 'support.respond'), 'admin');

    expect(fn () => Livewire::test(Inbox::class)->set('openCode', 'CNTOTHER'))->toThrow(CannotUpdateLockedPropertyException::class);
    Livewire::test(Inbox::class)->call('open', 'CNTNOPE')->assertNotFound();
});

it('M12 contact form: shows the Turnstile widget only when the check is on', function (): void {
    Livewire::test(ContactForm::class)->assertDontSeeHtml('x-data="turnstile(');

    app()->instance(App\Contracts\CaptchaVerifier::class, new TurnstileVerifier('site-key-123', 'secret'));
    Livewire::test(ContactForm::class)->assertSeeHtml('turnstile(')->assertSeeHtml('site-key-123')->assertDontSeeHtml('secret');
});

it('M12 contact form: the visible result is a reference code from the inbox', function (): void {
    Livewire::test(ContactForm::class)
        ->set('firstName', 'Asha')->set('email', 'asha@example.com')->set('category', 'OTHER')
        ->set('message', 'Do you have a branch in Kollam?')
        ->call('send')->assertHasNoErrors()
        ->assertSee(ContactSubmission::query()->sole()->code);
});

it('A08 notice: hidden for a member with nothing new, and the kinds are locked', function (): void {
    $member = memberWithPhone();
    $this->actingAs($member);
    LegalDocument::factory()->live()->kind(LegalKind::Terms)->create();
    app(App\Actions\Content\RecordLegalAcceptance::class)->handle($member, App\Enums\LegalAcceptanceContext::Registration, null, null);

    Livewire::test(UpdateNotice::class)->assertDontSee('updated our');
    expect(fn () => Livewire::test(UpdateNotice::class)->set('kinds', ['TERMS']))->toThrow(CannotUpdateLockedPropertyException::class);
});
