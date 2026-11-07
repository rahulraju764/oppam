<?php

declare(strict_types=1);

use App\Actions\Admin\Content\SaveContent;
use App\Actions\Admin\Content\TransitionContent;
use App\Actions\Content\RecordLegalAcceptance;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\LegalAcceptanceContext;
use App\Enums\LegalKind;
use App\Exceptions\Admin\ContentRefused;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Http\Middleware\EnsureLegalTermsAccepted;
use App\Livewire\Member\Legal\AcceptTerms;
use App\Livewire\Member\Legal\UpdateNotice;
use App\Models\AuditLog;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Queries\Content\LegalVersions;
use Database\Seeders\PlansSeeder;
use Livewire\Livewire;

/*
| A08 / M12 versioned legal pages and forced re-acceptance (P8.1b, owner 2026-11-06): one live
| version per kind, a published version never changes, a material change sends members to a
| blocking accept page, a non-material one only shows a notice.
*/

beforeEach(function (): void {
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    $this->editor = contentAdmin('content.edit');
    $this->publisher = contentAdmin('content.publish');
    $this->flow = app(TransitionContent::class);
    $this->save = app(SaveContent::class);
});

function legalInput(array $overrides = []): array
{
    return [...['sort_order' => 0, 'kind' => 'TERMS', 'title' => 'Terms of Use', 'summary' => 'Refund clause rewritten.',
        'body' => '<h3>1. About</h3><p>New terms.</p>', 'effective_on' => '2026-12-01', 'is_material' => true, 'legally_reviewed' => false], ...$overrides];
}

/** A version published through the workflow, the way an admin does it. */
function publishLegal(array $overrides = []): LegalDocument
{
    $test = test();
    $row = $test->save->handle($test->editor, ContentType::Legal, null, legalInput($overrides));
    $test->flow->submit($test->editor, $row);

    return $test->flow->publish($test->publisher, $row->refresh());
}

it('numbers versions per page, from one (A08 legal versioning)', function (): void {
    $first = $this->save->handle($this->editor, ContentType::Legal, null, legalInput());
    $second = $this->save->handle($this->editor, ContentType::Legal, null, legalInput());
    $privacy = $this->save->handle($this->editor, ContentType::Legal, null, legalInput(['kind' => 'PRIVACY']));

    expect($first->version)->toBe(1)->and($second->version)->toBe(2)->and($privacy->version)->toBe(1);
});

it('keeps clause headings, anchors and fill-in marks but strips scripts and handlers (A08 sanitised)', function (): void {
    $row = $this->save->handle($this->editor, ContentType::Legal, null, legalInput([
        'body' => '<h3 id="grievance">15. Grievance</h3><p onclick="x()">Write to <span class="legal-fill">[name]</span>.</p><script>alert(1)</script><img src=x onerror=alert(1)>',
    ]));

    expect($row->body)->toContain('<h3 id="grievance" class="privacy-shd">')
        ->toContain('<span class="legal-fill">[name]</span>')
        ->toContain('class="p-main"')
        ->not->toContain('script')->not->toContain('onclick')->not->toContain('onerror')->not->toContain('<img');
});

it('publishing a new version archives the one it replaces, so one version is live (A08)', function (): void {
    $v1 = publishLegal();
    $v2 = publishLegal(['summary' => 'Second']);

    expect($v1->refresh()->status)->toBe(ContentStatus::Archived)
        ->and($v2->refresh()->status)->toBe(ContentStatus::Published)
        ->and(LegalDocument::query()->live()->where('kind', LegalKind::Terms)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'content.archived')->where('subject_id', $v1->id)->exists())->toBeTrue();
});

it('never edits a published version: members agreed to that exact text (A08)', function (): void {
    $live = publishLegal();

    expect(fn () => $this->save->handle($this->editor, ContentType::Legal, $live, legalInput(['body' => '<p>Changed</p>'])))
        ->toThrow(ContentRefused::class);
    expect($live->refresh()->body)->toContain('New terms.')->and($live->draft)->toBeNull();
});

it('refuses to archive the live version or take a published one back to draft (A08)', function (): void {
    $v1 = publishLegal();

    expect(fn () => $this->flow->archive($this->publisher, $v1, 'No longer needed'))->toThrow(ContentRefused::class);

    publishLegal();
    expect(fn () => $this->flow->backToDraft($this->editor, $v1->refresh()))->toThrow(ContentRefused::class);
});

it('never lets an older version replace a newer published one (A08)', function (): void {
    $older = $this->save->handle($this->editor, ContentType::Legal, null, legalInput());
    $this->flow->submit($this->editor, $older);
    publishLegal();

    expect(fn () => $this->flow->publish($this->publisher, $older->refresh()))->toThrow(ContentRefused::class);
});

it('shows the live version with its number and effective date, and the not-reviewed note (M12)', function (): void {
    publishLegal(['body' => '<p>Version two text.</p>', 'effective_on' => '2026-12-01']);

    $this->get('/terms')->assertOk()
        ->assertSee('Version two text.')->assertSee('Version 1')->assertSee('1 December 2026')
        ->assertSee('has not yet been reviewed by our lawyers');
});

it('drops the not-reviewed note once a version is marked legally reviewed (A08)', function (): void {
    publishLegal(['legally_reviewed' => true]);

    $this->get('/terms')->assertOk()->assertDontSee('has not yet been reviewed by our lawyers');
});

it('shows the reference copy with its fill-in marks before any version is published (M12)', function (string $uri): void {
    $this->get($uri)->assertOk()->assertSee('class="legal-fill"', false);
})->with(['/terms', '/privacy', '/grievance']);

it('sends a member who has not agreed to a material version to the accept page (A08 forced re-acceptance)', function (): void {
    publishLegal();
    $member = memberWithPhone();

    $this->actingAs($member)->get(memberUrl('/settings/blocked'))->assertRedirect(route('legal.accept'));
    $this->actingAs($member)->get(memberUrl('/legal/accept'))->assertOk()->assertSee('updated our terms');
});

it('refuses a background request with 409 and never makes it the page to return to (A08 forced re-acceptance)', function (): void {
    publishLegal();
    $member = memberWithPhone();
    $this->actingAs($member);

    $this->get(memberUrl('/settings/blocked'))->assertRedirect(route('legal.accept'));
    expect(session('url.intended'))->toBe(memberUrl('/settings/blocked'));

    // Echo's channel auth (pusher-js sends no JSON Accept header), a fetch, a Livewire call.
    $this->post(memberUrl('/broadcasting/auth'), ['channel_name' => 'private-App.Models.User.'.$member->id, 'socket_id' => '1.1'])->assertStatus(409);
    $this->get(memberUrl('/dashboard'), ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(409);
    $this->get(memberUrl('/dashboard'), ['X-Livewire' => '1'])->assertStatus(409);
    expect(session('url.intended'))->toBe(memberUrl('/settings/blocked'));

    Livewire::test(AcceptTerms::class)->set('agree', true)->call('accept')->assertRedirect(memberUrl('/settings/blocked'));
});

it('lets the member in once they agree, recording the live version the server chose (A08)', function (): void {
    $live = publishLegal();
    $member = memberWithPhone();
    $this->actingAs($member);

    Livewire::test(AcceptTerms::class)->call('accept')->assertHasErrors('agree');
    Livewire::test(AcceptTerms::class)->set('agree', true)->call('accept')->assertRedirect();

    $row = LegalAcceptance::query()->where('user_id', $member->id)->sole();
    expect($row->legal_document_id)->toBe($live->id)->and($row->version)->toBe(1)
        ->and($row->context)->toBe(LegalAcceptanceContext::Required);
    $this->get(memberUrl('/settings/blocked'))->assertOk();
});

it('asks again after a newer material version, not after a non-material one (A08)', function (): void {
    publishLegal();
    $member = memberWithPhone();
    app(RecordLegalAcceptance::class)->handle($member, LegalAcceptanceContext::Required, '10.0.0.1', 'test');

    publishLegal(['is_material' => false, 'summary' => 'Typo fixed']);
    $this->actingAs($member)->get(memberUrl('/settings/blocked'))->assertOk();
    expect(app(LegalVersions::class)->pendingFor($member)->notices)->toBe([LegalKind::Terms]);

    publishLegal(['is_material' => true]);
    $this->actingAs($member)->withSession([])->get(memberUrl('/settings/blocked'))->assertRedirect(route('legal.accept'));
});

it('shows a dismissible notice for a non-material update, and dismissing records it (A08)', function (): void {
    publishLegal();
    $member = memberWithPhone();
    app(RecordLegalAcceptance::class)->handle($member, LegalAcceptanceContext::Required, null, null);
    publishLegal(['is_material' => false]);
    $this->actingAs($member);

    Livewire::test(UpdateNotice::class)->assertSee('updated our')->call('dismiss')->assertDontSee('updated our');

    expect(LegalAcceptance::query()->where('user_id', $member->id)->max('version'))->toBe(2)
        ->and(app(LegalVersions::class)->pendingFor($member)->notices)->toBe([]);
});

it('never asks anything while no version is published (A08)', function (): void {
    $member = memberWithPhone();

    $this->actingAs($member)->get(memberUrl('/settings/blocked'))->assertOk();
    expect(app(LegalVersions::class)->pendingFor($member)->blocks())->toBeFalse();
});

it('checks once per session and again when a new version goes live (A08 performance)', function (): void {
    publishLegal();
    $member = memberWithPhone();
    app(RecordLegalAcceptance::class)->handle($member, LegalAcceptanceContext::Required, null, null);

    $this->actingAs($member)->get(memberUrl('/settings/blocked'))->assertOk()->assertSessionHas(EnsureLegalTermsAccepted::SESSION_KEY);

    // Same session (already checked): a new material version still stops it on the next page.
    publishLegal(['is_material' => true]);
    $this->get(memberUrl('/settings/blocked'))->assertRedirect(route('legal.accept'));
});

it('refuses an action on a page opened before a material version went live (A08, persistent middleware)', function (): void {
    $member = memberWithPhone();
    $this->actingAs($member);
    $page = $this->withCredentials()->get(memberUrl('/settings/blocked'))->assertOk()->getContent();

    publishLegal();
    bureauLivewireUpdate((string) $page)->assertStatus(409);
});

it('never stops an admin support session, and it cannot agree for the member (A08, A01)', function (): void {
    publishLegal();
    $member = memberWithPhone();
    chatImpersonate($member);

    $request = Illuminate\Http\Request::create(memberUrl('/settings/blocked'));
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $member);
    $response = app(EnsureLegalTermsAccepted::class)->handle($request, fn () => response('passed'));

    expect($response->getContent())->toBe('passed');
    expect(fn () => app(RecordLegalAcceptance::class)->handle($member, LegalAcceptanceContext::Required, null, null))
        ->toThrow(ImpersonationRestricted::class);
    expect(LegalAcceptance::query()->count())->toBe(0);
});

it('stops bureau logins at the accept page too (A08, §11A)', function (): void {
    publishLegal();
    $login = bureauLogin();

    $this->actingAs($login)->get(brokerUrl(''))->assertRedirect(route('legal.accept'));
});

it('sends guests to sign in rather than to the accept page', function (): void {
    publishLegal();

    $this->get(memberUrl('/legal/accept'))->assertRedirect(route('login'));
});

it('records acceptance only of what is live and never twice (A08)', function (): void {
    publishLegal();
    publishLegal(['kind' => 'PRIVACY']);
    $member = memberWithPhone();

    $written = app(RecordLegalAcceptance::class)->handle($member, LegalAcceptanceContext::Registration, '10.0.0.1', 'ua');
    $again = app(RecordLegalAcceptance::class)->handle($member, LegalAcceptanceContext::Registration, '10.0.0.1', 'ua');

    expect($written)->toBe(2)->and($again)->toBe(0)
        ->and(LegalAcceptance::query()->where('user_id', $member->id)->pluck('kind')->map->value->sort()->values()->all())->toBe(['PRIVACY', 'TERMS']);
});

it('previews an unpublished version on the real page through a signed link only (A08)', function (): void {
    $draft = $this->save->handle($this->editor, ContentType::Legal, null, legalInput(['body' => '<p>Draft wording.</p>']));
    $url = app(App\Actions\Admin\Content\IssueContentPreview::class)->handle($this->editor, $draft);

    $this->get($url)->assertOk()->assertSee('Draft wording.')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get('/content-preview/legal/'.$draft->code.'?v='.$draft->lock_version)->assertForbidden();
    $this->get('/terms')->assertDontSee('Draft wording.');
});
