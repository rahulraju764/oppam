<?php

declare(strict_types=1);

use App\Actions\Admin\Content\IssueContentPreview;
use App\Actions\Admin\Content\WithdrawStory;
use App\Models\AuditLog;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\FaqItem;
use App\Models\HomeBlock;
use App\Models\SuccessStory;
use App\Queries\Content\PreviewContext;
use Database\Seeders\ContentSeeder;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\URL;

/* A08 signed preview (P8.1a): the real page against draft data, in that request alone. */

beforeEach(function (): void {
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    $this->seed(ContentSeeder::class);
    $this->admin = adminWithRole('content_editor');
});

it('A08: a preview shows the waiting text of a live row on the real page; the public page doesn’t', function (): void {
    $branch = Branch::factory()->published()->create(['city' => 'Published City', 'draft' => ['city' => 'Waiting City']]);

    $url = app(IssueContentPreview::class)->handle($this->admin, $branch);

    $this->get($url)->assertOk()->assertSee('Waiting City')->assertDontSee('Published City')
        ->assertSee('branches-section', false)
        ->assertSee('Preview: this page shows unpublished content')
        ->assertSee('<meta name="robots" content="noindex, nofollow', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->get('/branches')->assertOk()->assertSee('Published City')->assertDontSee('Waiting City')->assertDontSee('Preview: this page');
    expect(AuditLog::query()->where('action', 'content.preview_issued')->where('subject_id', $branch->id)->exists())->toBeTrue();
});

it('A08: a preview shows a draft that was never published, on the right page for its type', function (string $class, array $attributes, string $seen): void {
    $row = $class::factory()->create($attributes);

    $this->get(app(IssueContentPreview::class)->handle($this->admin, $row))->assertOk()->assertSee($seen, false);
})->with([
    'story' => [SuccessStory::class, ['couple' => 'Draft Couple', 'consent_a_at' => null], 'Draft Couple'],
    'faq' => [FaqItem::class, ['question' => 'Draft question?'], 'Draft question?'],
    'banner' => [Banner::class, ['heading' => 'Draft heading'], 'Draft heading'],
]);

it('A08: a preview of a home block opens the page that block is on', function (): void {
    $values = HomeBlock::query()->where('block_key', 'values')->sole();
    $values->forceFill(['draft' => ['content' => [...$values->content, 'heading' => 'Draft values heading']]])->save();

    $this->get(app(IssueContentPreview::class)->handle($this->admin, $values))->assertOk()->assertSee('Draft values heading');
    $this->get('/about')->assertDontSee('Draft values heading');
});

it('A08: on a preview of the home page the registration form is off', function (): void {
    $banner = Banner::factory()->create();

    $this->get(app(IssueContentPreview::class)->handle($this->admin, $banner))->assertOk()->assertSee('The registration form is switched off in a preview.');
});

it('A08: unsigned, tampered and expired preview links get 403', function (): void {
    $branch = Branch::factory()->create(['city' => 'Secret']);
    $other = Branch::factory()->create(['city' => 'Other']);
    $url = app(IssueContentPreview::class)->handle($this->admin, $branch);

    $this->get(strtok($url, '?'))->assertForbidden();
    $this->get(str_replace($branch->code, $other->code, $url))->assertForbidden();
    $this->get($url.'x')->assertForbidden();

    $this->travel(31)->minutes();
    $this->get($url)->assertForbidden();
});

it('A08: a signed link for a code of another type or no row is 404, not another row’s data', function (): void {
    $faq = FaqItem::factory()->create();

    $this->get(URL::temporarySignedRoute('content.preview.branch', now()->addMinute(), ['code' => $faq->code, 'v' => $faq->lock_version]))->assertNotFound();
    $this->get(URL::temporarySignedRoute('content.preview.branch', now()->addMinute(), ['code' => 'NOPE']))->assertNotFound();
});

it('A08: a story withdrawn after its link was issued is gone from the preview too', function (): void {
    $story = SuccessStory::factory()->published()->create(['couple' => 'Withdrawn Couple']);
    $url = app(IssueContentPreview::class)->handle($this->admin, $story);

    app(WithdrawStory::class)->handle($this->admin, $story, 'Partner asked to remove it');

    $this->get($url)->assertNotFound();
});

it('A08: issuing a preview needs a content permission', function (): void {
    expect(fn () => app(IssueContentPreview::class)->handle(adminWithRole('support'), Branch::factory()->create()))->toThrow(AuthorizationException::class);
});

it('A08: preview mode ends with its request, even in a process that stays alive', function (): void {
    $branch = Branch::factory()->published()->create(['city' => 'Live City', 'draft' => ['city' => 'Waiting City']]);

    $this->get(app(IssueContentPreview::class)->handle($this->admin, $branch))->assertOk();

    expect(app(PreviewContext::class)->active())->toBeFalse();
});

it('A08 review: previewing a banner with no image yet gives a placeholder, not an empty src', function (): void {
    $banner = Banner::factory()->create(['image_path' => null, 'heading' => 'No image yet']);

    $this->get(app(IssueContentPreview::class)->handle($this->admin, $banner))->assertOk()->assertSee('No image yet')->assertSee('photo-processing.svg', false);
});

it('A08: the preview route is rate limited', function (): void {
    $url = app(IssueContentPreview::class)->handle($this->admin, Branch::factory()->create());

    foreach (range(1, 60) as $i) {
        $this->get($url)->assertOk();
    }

    $this->get($url)->assertStatus(429);
});

it('A08 review: a preview link is for one version: it stops working when the row changes', function (): void {
    $branch = Branch::factory()->published()->create(['city' => 'Live', 'draft' => ['city' => 'Seen text']]);
    $url = app(IssueContentPreview::class)->handle($this->admin, $branch);
    $this->get($url)->assertOk()->assertSee('Seen text')->assertSee('Version '.$branch->lock_version);

    $branch->forceFill(['draft' => ['city' => 'Changed text']])->save();

    $this->get($url)->assertNotFound();
    $this->get(app(IssueContentPreview::class)->handle($this->admin, $branch->refresh()))->assertOk()->assertSee('Changed text');
});

it('A08 review: a link from before a withdrawal doesn’t come back when the story is restored', function (): void {
    $story = SuccessStory::factory()->published()->create(['couple' => 'Withdrawn Couple']);
    $url = app(IssueContentPreview::class)->handle($this->admin, $story);

    app(WithdrawStory::class)->handle($this->admin, $story, 'Partner asked to remove it');
    app(App\Actions\Admin\Content\TransitionContent::class)->backToDraft($this->admin, $story->refresh());

    $this->get($url)->assertNotFound();
});
