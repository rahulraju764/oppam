<?php

declare(strict_types=1);

use App\Actions\Admin\Content\SaveContent;
use App\Actions\Admin\Content\TransitionContent;
use App\Actions\Admin\Content\WithdrawStory;
use App\Contracts\HtmlSanitizer;
use App\Data\Content\ConsentInput;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Exceptions\Admin\ContentRefused;
use App\Jobs\Content\PublishScheduledContent;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\FaqItem;
use App\Models\HomeBlock;
use App\Models\SuccessStory;
use App\Models\Testimonial;
use App\Queries\Content\PublicContent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
| A08 editorial workflow (P8.1a): DRAFT → IN_REVIEW → SCHEDULED → PUBLISHED → ARCHIVED, content.edit
| and content.publish separated, consent-gated stories, withdrawal, unpublished text kept off the site.
*/

beforeEach(function (): void {
    seedAdminRoles();
    $this->editor = contentAdmin('content.edit');
    $this->publisher = contentAdmin('content.publish');
    $this->flow = app(TransitionContent::class);
});

function contentAdmin(string ...$permissions): AdminUser
{
    $admin = adminWithRole('content_editor');
    $role = Role::query()->create(['name' => 'only-'.uniqid(), 'guard_name' => 'admin']);
    $role->syncPermissions(array_map(fn (string $p) => Permission::findByName($p, 'admin'), $permissions));
    $admin->syncRoles([$role]);

    return $admin->refresh();
}

function storyInput(array $overrides = []): array
{
    return [...['sort_order' => 1, 'couple' => 'Asha & Binu', 'place' => 'Kochi', 'wedding_date' => '2024-03-01', 'quote' => 'We met here.', 'body' => '<p>Our story.</p>'], ...$overrides];
}

it('A08: a row moves draft → review → published, and only then is on the site', function (): void {
    $branch = Branch::factory()->create(['city' => 'Palakkad']);
    $site = fn (): array => array_map(fn ($b) => $b->city, app(PublicContent::class)->branches());
    expect($site())->toBe([]);

    $this->flow->submit($this->editor, $branch);
    expect($site())->toBe([]);

    $this->flow->publish($this->publisher, $branch);
    expect($site())->toBe(['Palakkad'])
        ->and($branch->refresh()->status)->toBe(ContentStatus::Published)
        ->and($branch->published_by_admin_id)->toBe($this->publisher->id)
        ->and(AuditLog::query()->where('action', 'content.published')->where('subject_id', $branch->id)->exists())->toBeTrue();

    $this->flow->archive($this->publisher, $branch->refresh(), 'Office has closed');
    expect($site())->toBe([]);
});

it('A08: moves the workflow doesn’t allow are refused', function (string $from, string $step): void {
    $branch = Branch::factory()->{$from}()->create();

    $call = match ($step) {
        'publish' => fn () => $this->flow->publish($this->publisher, $branch),
        'submit' => fn () => $this->flow->submit($this->editor, $branch),
        'back' => fn () => $this->flow->backToDraft($this->editor, $branch),
    };

    expect($call)->toThrow(ContentRefused::class);
})->with([
    'publish from archived' => ['archived', 'publish'],
    'submit from published' => ['published', 'submit'],
    'back from published' => ['published', 'back'],
]);

it('A08: draft can’t skip review', function (): void {
    expect(fn () => $this->flow->publish($this->publisher, Branch::factory()->create()))->toThrow(ContentRefused::class);
});

it('A08: content.edit can’t publish, schedule or archive; content.publish can’t edit or submit', function (): void {
    $row = Branch::factory()->inReview()->create();

    foreach ([
        fn () => $this->flow->publish($this->editor, $row),
        fn () => $this->flow->schedule($this->editor, $row, now()->addDay()->toImmutable()),
        fn () => $this->flow->archive($this->editor, $row, 'No longer needed'),
        fn () => $this->flow->submit($this->publisher, Branch::factory()->create()),
        fn () => app(SaveContent::class)->handle($this->publisher, ContentType::Branch, $row, []),
        fn () => app(WithdrawStory::class)->handle($this->editor, SuccessStory::factory()->published()->create(), 'Asked to remove'),
    ] as $call) {
        expect($call)->toThrow(AuthorizationException::class);
    }

    expect($row->refresh()->status)->toBe(ContentStatus::InReview);
});

it('A08: an admin without either content permission is refused', function (): void {
    expect(fn () => app(SaveContent::class)->handle(contentAdmin('dashboard.view'), ContentType::Branch, null, []))->toThrow(AuthorizationException::class);
});

it('A08: editing a published row leaves the site unchanged until the changes are published', function (): void {
    $branch = Branch::factory()->published()->create(['city' => 'Old name']);
    $site = fn (): array => array_map(fn ($b) => $b->city, app(PublicContent::class)->branches());

    app(SaveContent::class)->handle($this->editor, ContentType::Branch, $branch, ['sort_order' => 0, 'city' => 'New name', 'role' => 'Branch', 'address' => 'Somewhere', 'phone' => '0487', 'tel' => '+914872441100', 'hours' => '10–6']);

    expect($site())->toBe(['Old name'])->and($branch->refresh()->hasPendingChanges())->toBeTrue();

    expect(fn () => $this->flow->publishChanges($this->editor, $branch))->toThrow(AuthorizationException::class);

    $this->flow->publishChanges($this->publisher, $branch);
    expect($site())->toBe(['New name'])->and($branch->refresh()->draft)->toBeNull();
});

it('A08: waiting changes can be discarded', function (): void {
    $branch = Branch::factory()->published()->create(['city' => 'Kept', 'draft' => ['city' => 'Dropped']]);

    $this->flow->discardChanges($this->editor, $branch);

    expect($branch->refresh()->city)->toBe('Kept')->and($branch->draft)->toBeNull();
    expect(fn () => $this->flow->publishChanges($this->publisher, $branch))->toThrow(ContentRefused::class);
});

it('A08: editing a scheduled row sends it back to draft, so unapproved text can’t go live on the schedule', function (): void {
    $branch = Branch::factory()->scheduled()->create();

    app(SaveContent::class)->handle($this->editor, ContentType::Branch, $branch, ['sort_order' => 0, 'city' => 'Sneaked in', 'role' => 'Branch', 'address' => 'Somewhere', 'phone' => '0487', 'tel' => '+914872441100', 'hours' => '10–6']);

    expect($branch->refresh()->status)->toBe(ContentStatus::Draft)->and($branch->scheduled_at)->toBeNull();
});

it('A08: only the type’s own fields are taken: status, code and consent can’t be posted in', function (): void {
    $row = app(SaveContent::class)->handle($this->editor, ContentType::Branch, null, [
        'sort_order' => 0, 'city' => 'Kollam', 'role' => 'Branch', 'address' => 'Somewhere', 'phone' => '0474', 'tel' => '+914742751100', 'hours' => '10–6',
        'status' => 'PUBLISHED', 'code' => 'HACKED', 'published_by_admin_id' => $this->publisher->id,
    ]);

    expect($row->status)->toBe(ContentStatus::Draft)->and($row->code)->not->toBe('HACKED')->and($row->published_by_admin_id)->toBeNull();
});

it('A08: invalid input is refused and nothing is written', function (array $overrides, string $field): void {
    expect(fn () => app(SaveContent::class)->handle($this->editor, ContentType::Story, null, storyInput($overrides)))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($field));
    expect(SuccessStory::query()->count())->toBe(0);
})->with([
    'no couple' => [['couple' => ''], 'couple'],
    'future wedding' => [['wedding_date' => '2999-01-01'], 'wedding_date'],
    'long quote' => [['quote' => str_repeat('x', 301)], 'quote'],
    'body only scripts' => [['body' => '<script>alert(1)</script>'], 'body'],
]);

it('A08: unsafe banner and map links are refused', function (string $url): void {
    expect(fn () => app(SaveContent::class)->handle($this->editor, ContentType::Banner, null, ['sort_order' => 0, 'heading' => 'H', 'copy' => 'C', 'cta_label' => 'Go', 'cta_url' => $url]))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveContent::class)->handle($this->editor, ContentType::Branch, null, ['sort_order' => 0, 'city' => 'K', 'role' => 'B', 'address' => 'A', 'phone' => '1', 'tel' => '+914742751100', 'hours' => '9', 'map_url' => $url]))
        ->toThrow(ValidationException::class);
})->with(['javascript:alert(1)', '//evil.example', 'data:text/html,x', 'http://plain.example', '/\\evil.example']);

it('A08: a home block is seeded, never added by hand, and only its own fields are taken', function (): void {
    expect(fn () => app(SaveContent::class)->handle($this->editor, ContentType::HomeBlock, null, ['sort_order' => 0]))->toThrow(ContentRefused::class);

    $block = HomeBlock::factory()->create();
    app(SaveContent::class)->handle($this->editor, ContentType::HomeBlock, $block, ['sort_order' => 3, 'content' => ['eyebrow' => 'E', 'heading' => 'H', 'text' => 'T', 'extra' => 'dropped']]);

    expect($block->refresh()->content)->toBe(['eyebrow' => 'E', 'heading' => 'H', 'text' => 'T']);
    expect(fn () => app(SaveContent::class)->handle($this->editor, ContentType::HomeBlock, $block, ['sort_order' => 3, 'content' => ['eyebrow' => 'E']]))->toThrow(ValidationException::class);
});

it('A08 rule 9: rich text is stored sanitised, whatever the editor sends', function (string $dirty, string $absent): void {
    $row = app(SaveContent::class)->handle($this->editor, ContentType::Faq, null, ['sort_order' => 0, 'question' => 'Q', 'answer' => '<p>Safe</p>'.$dirty]);

    expect($row->answer)->toContain('Safe')->not->toContain($absent);
})->with([
    ['<script>alert(1)</script>', 'script'],
    ['<img src=x onerror=alert(1)>', 'onerror'],
    ['<a href="javascript:alert(1)">x</a>', 'javascript'],
    ['<iframe src="//evil.example"></iframe>', 'iframe'],
    ['<p style="position:fixed">x</p>', 'style'],
    ['<svg onload=alert(1)></svg>', 'svg'],
    ['<p onclick="alert(1)">x</p>', 'onclick'],
]);

it('A08: the sanitiser keeps the editor’s formatting, and links get nofollow', function (): void {
    $clean = app(HtmlSanitizer::class)->clean('<p><strong>Bold</strong> <em>it</em></p><ul><li>One</li></ul><a href="https://oppam.in/x">Link</a>');

    expect($clean)->toContain('<strong>Bold</strong>')->toContain('<li>One</li>')->toContain('href="https://oppam.in/x"')->toContain('nofollow');
});

it('A08: a story can’t be published or scheduled without both partners’ consent', function (array $missing): void {
    $story = SuccessStory::factory()->inReview()->create($missing);

    expect(fn () => $this->flow->publish($this->publisher, $story))->toThrow(ContentRefused::class)
        ->and(fn () => $this->flow->schedule($this->publisher, $story, now()->addDay()->toImmutable()))->toThrow(ContentRefused::class)
        ->and($story->refresh()->status)->toBe(ContentStatus::InReview);
})->with([
    'first' => [['consent_a_at' => null]],
    'second' => [['consent_b_at' => null]],
    'both' => [['consent_a_at' => null, 'consent_b_at' => null]],
]);

it('A08: a published row without consent never shows, even if the status says published', function (): void {
    SuccessStory::factory()->published()->create(['consent_b_at' => null]);
    Testimonial::factory()->published()->create(['consent_at' => null]);

    expect(app(PublicContent::class)->stories())->toBe([])->and(app(PublicContent::class)->testimonials())->toBe([]);
});

it('A08: consent saved with the story needs a note and is timestamped once', function (): void {
    $save = app(SaveContent::class);

    expect(fn () => $save->handle($this->editor, ContentType::Story, null, storyInput(), consent: new ConsentInput(true, true, '')))->toThrow(ValidationException::class);

    $story = $save->handle($this->editor, ContentType::Story, null, storyInput(), consent: new ConsentInput(true, false, 'Signed form received'));
    expect($story->consent_a_at)->not->toBeNull()->and($story->consent_b_at)->toBeNull();
    $this->travel(1)->hour();

    $first = $story->consent_a_at;
    $save->handle($this->editor, ContentType::Story, $story, storyInput(), consent: new ConsentInput(true, true, 'Both forms received'));
    expect($story->refresh()->consent_a_at?->equalTo($first))->toBeTrue()->and($story->consent_b_at)->not->toBeNull();
});

it('A08: consent on a live story can’t be removed by an edit, only by withdrawing', function (): void {
    $story = SuccessStory::factory()->published()->create();

    app(SaveContent::class)->handle($this->editor, ContentType::Story, $story, storyInput(), consent: new ConsentInput(false, false, ''));

    expect($story->refresh()->hasBothConsents())->toBeTrue();
});

it('A08: an image is required to publish a banner, story or testimonial', function (): void {
    $banner = Banner::factory()->inReview()->create(['image_path' => null]);

    expect(fn () => $this->flow->publish($this->publisher, $banner))->toThrow(ContentRefused::class);
});

it('A08: withdrawal takes a story off the site at once, deletes its photos and clears consent', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('content/photo.webp', 'x');
    Storage::disk('public')->put('content/waiting.webp', 'x');
    $story = SuccessStory::factory()->published()->create(['image_path' => 'content/photo.webp', 'draft' => ['image_path' => 'content/waiting.webp']]);

    app(WithdrawStory::class)->handle($this->publisher, $story, 'Partner asked to remove it');

    $story->refresh();
    expect(app(PublicContent::class)->stories())->toBe([])
        ->and($story->status)->toBe(ContentStatus::Archived)
        ->and($story->isWithdrawn())->toBeTrue()
        ->and($story->hasBothConsents())->toBeFalse()
        ->and($story->image_path)->toBeNull()
        ->and($story->draft)->toBeNull()
        ->and(AuditLog::query()->where('action', 'content.story_withdrawn')->value('reason'))->toBe('Partner asked to remove it');
    Storage::disk('public')->assertMissing('content/photo.webp');
    Storage::disk('public')->assertMissing('content/waiting.webp');
});

it('A08: a withdrawn story needs a reason, can’t be withdrawn twice, and returns only with fresh consent and a photo', function (): void {
    $story = SuccessStory::factory()->published()->create();
    $withdraw = app(WithdrawStory::class);

    expect(fn () => $withdraw->handle($this->publisher, $story, 'no'))->toThrow(ValidationException::class);
    $withdraw->handle($this->publisher, $story, 'Partner asked to remove it');
    expect(fn () => $withdraw->handle($this->publisher, $story->refresh(), 'Partner asked again'))->toThrow(ContentRefused::class);

    $this->flow->backToDraft($this->editor, $story->refresh());
    $this->flow->submit($this->editor, $story->refresh());
    expect(fn () => $this->flow->publish($this->publisher, $story->refresh()))->toThrow(ContentRefused::class, 'consent');

    app(SaveContent::class)->handle($this->editor, ContentType::Story, $story->refresh(), storyInput(), consent: new ConsentInput(true, true, 'Fresh forms received'));
    $this->flow->submit($this->editor, $story->refresh());   // an edit sends it back to draft
    expect(fn () => $this->flow->publish($this->publisher, $story->refresh()))->toThrow(ContentRefused::class, 'image');
});

it('A08: the audit log never keeps story text', function (): void {
    app(SaveContent::class)->handle($this->editor, ContentType::Story, null, storyInput(['couple' => 'Secret Names']));

    expect(json_encode(AuditLog::query()->where('action', 'content.saved')->sole()->after))->not->toContain('Secret Names');
});

it('A08: the job publishes what is due and leaves the rest; running it again changes nothing', function (): void {
    $due = Branch::factory()->scheduled(now()->subMinute())->create();
    $later = Branch::factory()->scheduled(now()->addHour())->create();

    app()->call([new PublishScheduledContent, 'handle']);
    $first = $due->refresh()->published_at;
    $this->travel(5)->minutes();
    app()->call([new PublishScheduledContent, 'handle']);

    expect($due->refresh()->status)->toBe(ContentStatus::Published)
        ->and($due->published_at?->equalTo($first))->toBeTrue()
        ->and($later->refresh()->status)->toBe(ContentStatus::Scheduled)
        ->and(AuditLog::query()->where('action', 'content.published')->where('subject_id', $due->id)->count())->toBe(1);
});

it('A08: a scheduled story whose consent has gone returns to review, unpublished', function (): void {
    $story = SuccessStory::factory()->scheduled(now()->subMinute())->create(['consent_a_at' => null]);

    app()->call([new PublishScheduledContent, 'handle']);

    expect($story->refresh()->status)->toBe(ContentStatus::InReview)
        ->and(app(PublicContent::class)->stories())->toBe([])
        ->and(AuditLog::query()->where('action', 'content.schedule_refused')->exists())->toBeTrue();
});

it('A08: scheduling needs a future time', function (): void {
    expect(fn () => $this->flow->schedule($this->publisher, Branch::factory()->inReview()->create(), now()->subHour()->toImmutable()))->toThrow(ContentRefused::class);
});

it('A08: an uploaded image is rewritten as WebP; anything that isn’t an image is refused', function (): void {
    Storage::fake('public');
    $save = app(SaveContent::class);
    $input = ['sort_order' => 0, 'name' => 'Devi', 'place' => 'Kochi', 'quote' => 'Hello'];

    $row = $save->handle($this->editor, ContentType::Testimonial, null, $input, UploadedFile::fake()->image('me.jpg', 2400, 1200));

    expect($row->getAttribute('image_path'))->toEndWith('.webp')->and($row->getAttribute('image_width'))->toBe(1920)->and($row->getAttribute('image_height'))->toBe(960);
    Storage::disk('public')->assertExists((string) $row->getAttribute('image_path'));

    expect(fn () => $save->handle($this->editor, ContentType::Testimonial, null, $input, UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;')))->toThrow(ValidationException::class);
    expect(fn () => $save->handle($this->editor, ContentType::Testimonial, null, $input, UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml')))->toThrow(ValidationException::class);
});

it('A08: publishing a replacement image removes the old upload, never a bundled asset', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('content/old.webp', 'x');
    Storage::disk('public')->put('content/new.webp', 'x');
    $row = Banner::factory()->published()->create(['image_path' => 'content/old.webp', 'draft' => ['image_path' => 'content/new.webp']]);

    $this->flow->publishChanges($this->publisher, $row);

    Storage::disk('public')->assertMissing('content/old.webp');
    Storage::disk('public')->assertExists('content/new.webp');
    expect($row->refresh()->getAttribute('image_path'))->toBe('content/new.webp');
});

it('FAQ answers render as stored rich text', function (): void {
    FaqItem::factory()->published()->create(['answer' => '<p><strong>Yes</strong></p>']);

    expect(app(PublicContent::class)->faqs()[0]->answerHtml)->toBe('<p><strong>Yes</strong></p>');
});

it('A08 review Blocker: a dialog left open can’t re-record consent on a story withdrawn meanwhile', function (): void {
    $story = SuccessStory::factory()->published()->create(['couple' => 'Asha & Binu', 'consent_note' => 'Signed forms 2024']);
    $openedOn = $story->lock_version;
    app(WithdrawStory::class)->handle($this->publisher, $story, 'Partner asked to remove it');

    expect(fn () => app(SaveContent::class)->handle($this->editor, ContentType::Story, $story->refresh(), storyInput(), consent: new ConsentInput(true, true, 'Signed forms 2024'), expectedVersion: $openedOn))
        ->toThrow(ContentRefused::class);

    // Without a version too: a withdrawn story takes no edits at all until it is restored.
    expect(fn () => app(SaveContent::class)->handle($this->editor, ContentType::Story, $story->refresh(), storyInput(), consent: new ConsentInput(true, true, 'Signed forms 2024')))
        ->toThrow(ContentRefused::class);

    expect($story->refresh()->hasBothConsents())->toBeFalse()->and($story->image_path)->toBeNull();
});

it('A08 review Blocker: restoring a story still needs consent typed in a dialog opened after the restore', function (): void {
    $story = SuccessStory::factory()->published()->create();
    $openedOn = $story->lock_version;
    app(WithdrawStory::class)->handle($this->publisher, $story, 'Partner asked to remove it');
    $this->flow->backToDraft($this->editor, $story->refresh());

    expect(fn () => app(SaveContent::class)->handle($this->editor, ContentType::Story, $story->refresh(), storyInput(), consent: new ConsentInput(true, true, 'Old note'), expectedVersion: $openedOn))
        ->toThrow(ContentRefused::class);
    expect($story->refresh()->hasBothConsents())->toBeFalse();
});

it('A08: a stale dialog can’t overwrite someone else’s edit', function (): void {
    $branch = Branch::factory()->create(['city' => 'First']);
    $openedOn = $branch->lock_version;
    $input = ['sort_order' => 0, 'role' => 'Branch', 'address' => 'Somewhere', 'phone' => '0487', 'tel' => '+914872441100', 'hours' => '10–6'];

    app(SaveContent::class)->handle($this->editor, ContentType::Branch, $branch, ['city' => 'Second', ...$input], expectedVersion: $openedOn);

    expect(fn () => app(SaveContent::class)->handle($this->editor, ContentType::Branch, $branch, ['city' => 'Stale', ...$input], expectedVersion: $openedOn))->toThrow(ContentRefused::class)
        ->and($branch->refresh()->city)->toBe('Second');
});

it('A08 review: editing a row in review sends it back to draft, so unreviewed text can’t go out under a publisher’s click', function (): void {
    $branch = Branch::factory()->inReview()->create();

    app(SaveContent::class)->handle($this->editor, ContentType::Branch, $branch, ['sort_order' => 0, 'city' => 'Changed', 'role' => 'Branch', 'address' => 'Somewhere', 'phone' => '0487', 'tel' => '+914872441100', 'hours' => '10–6']);

    expect($branch->refresh()->status)->toBe(ContentStatus::Draft);
    expect(fn () => $this->flow->publish($this->publisher, $branch))->toThrow(ContentRefused::class);
});

it('A08 review: waiting changes edited after the publisher looked aren’t released by that publisher’s click', function (): void {
    $branch = Branch::factory()->published()->create(['city' => 'Live', 'draft' => ['city' => 'Approved text']]);
    $seen = $branch->lock_version;

    app(SaveContent::class)->handle($this->editor, ContentType::Branch, $branch, ['sort_order' => 0, 'city' => 'Sneaked in', 'role' => 'Branch', 'address' => 'Somewhere', 'phone' => '0487', 'tel' => '+914872441100', 'hours' => '10–6']);

    expect(fn () => $this->flow->publishChanges($this->publisher, $branch, $seen))->toThrow(ContentRefused::class)
        ->and($branch->refresh()->city)->toBe('Live');
});

it('A08 review: the same goes for publishing and scheduling a row that moved on', function (): void {
    $branch = Branch::factory()->inReview()->create();
    $seen = $branch->lock_version - 1;

    expect(fn () => $this->flow->publish($this->publisher, $branch, $seen))->toThrow(ContentRefused::class)
        ->and(fn () => $this->flow->schedule($this->publisher, $branch, now()->addDay()->toImmutable(), $seen))->toThrow(ContentRefused::class)
        ->and($branch->refresh()->status)->toBe(ContentStatus::InReview);
});

it('A08 review: consent changes are in the audit row, the note text isn’t', function (): void {
    $story = app(SaveContent::class)->handle($this->editor, ContentType::Story, null, storyInput(), consent: new ConsentInput(true, false, 'Told us privately'));
    app(SaveContent::class)->handle($this->editor, ContentType::Story, $story, storyInput(), consent: new ConsentInput(true, true, 'Told us privately'));

    $log = AuditLog::query()->where('action', 'content.saved')->where('subject_id', $story->id)->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();

    expect($log->before['consent'])->toBe(['first' => true, 'second' => false])
        ->and($log->after['consent'])->toBe(['first' => true, 'second' => true])
        ->and(json_encode([$log->before, $log->after]))->not->toContain('Told us privately');
});

it('A08 review: a palette PNG is accepted, not a fatal error', function (): void {
    Storage::fake('public');
    $path = tempnam(sys_get_temp_dir(), 'pal');
    $palette = imagecreate(100, 100);
    imagecolorallocate($palette, 200, 30, 60);
    imagepng($palette, $path);

    $row = app(SaveContent::class)->handle($this->editor, ContentType::Banner, null, ['sort_order' => 0, 'heading' => 'H', 'copy' => 'C'], new UploadedFile($path, 'logo.png', 'image/png', null, true));

    Storage::disk('public')->assertExists((string) $row->getAttribute('image_path'));
    expect($row->getAttribute('image_width'))->toBe(100);
});

it('A08 review: an image too large to decode safely is refused before decoding', function (): void {
    Storage::fake('public');
    $path = tempnam(sys_get_temp_dir(), 'big');
    $big = imagecreate(7000, 7000);
    imagecolorallocate($big, 1, 1, 1);
    imagepng($big, $path, 9);

    expect(fn () => app(SaveContent::class)->handle($this->editor, ContentType::Banner, null, ['sort_order' => 0, 'heading' => 'H', 'copy' => 'C'], new UploadedFile($path, 'big.png', 'image/png', null, true)))
        ->toThrow(ValidationException::class);
    expect(Storage::disk('public')->allFiles('content'))->toBe([]);
});

it('A08 review: location data in a photo never reaches the public disk', function (): void {
    Storage::fake('public');

    $row = app(SaveContent::class)->handle($this->editor, ContentType::Banner, null, ['sort_order' => 0, 'heading' => 'H', 'copy' => 'C'], jpegWithGps());

    expect(Storage::disk('public')->get((string) $row->getAttribute('image_path')))->not->toContain('Exif')->not->toContain('GPS');
});

it('A08 review: a refused save leaves no uploaded file behind', function (): void {
    Storage::fake('public');
    $branch = Banner::factory()->create();

    expect(fn () => app(SaveContent::class)->handle($this->editor, ContentType::Banner, $branch, ['sort_order' => 0, 'heading' => 'H', 'copy' => 'C'], UploadedFile::fake()->image('a.jpg', 400, 400), expectedVersion: 999))->toThrow(ContentRefused::class);

    expect(Storage::disk('public')->allFiles('content'))->toBe([]);
});

it('A08 review: a tampered rich-text field is a validation error, not a crash', function (): void {
    expect(fn () => app(SaveContent::class)->handle($this->editor, ContentType::Faq, null, ['sort_order' => 0, 'question' => 'Q', 'answer' => ['x']]))->toThrow(ValidationException::class);
});

it('A08 review: a phone photo’s EXIF rotation is applied before it is dropped', function (): void {
    if (! function_exists('exif_read_data')) {
        $this->markTestSkipped('ext-exif is not loaded.');
    }

    Storage::fake('public');
    $image = imagecreatetruecolor(100, 50);
    imagefilledrectangle($image, 0, 0, 49, 49, (int) imagecolorallocate($image, 255, 0, 0));   // left half red
    ob_start();
    imagejpeg($image, null, 100);
    $jpeg = (string) ob_get_clean();

    // A minimal APP1 segment: little-endian TIFF, one tag, Orientation (0x0112) = 6.
    $tiff = "II*\0\x08\0\0\0\x01\0\x12\x01\x03\0\x01\0\0\0\x06\0\0\0\0\0\0\0";
    $app1 = "Exif\0\0".$tiff;
    $path = tempnam(sys_get_temp_dir(), 'exif');
    file_put_contents($path, substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2));
    expect(exif_read_data($path)['Orientation'] ?? null)->toBe(6);

    $row = app(SaveContent::class)->handle($this->editor, ContentType::Banner, null, ['sort_order' => 0, 'heading' => 'H', 'copy' => 'C'], new UploadedFile($path, 'phone.jpg', 'image/jpeg', null, true));

    expect($row->getAttribute('image_width'))->toBe(50)->and($row->getAttribute('image_height'))->toBe(100);

    // Orientation 6 means turn clockwise: the red left half ends up on top.
    $stored = imagecreatefromstring(Storage::disk('public')->get((string) $row->getAttribute('image_path')));
    $red = fn (int $x, int $y): int => (imagecolorat($stored, $x, $y) >> 16) & 0xFF;
    expect($red(25, 10))->toBeGreaterThan(200)->and($red(25, 90))->toBeLessThan(50);
});

it('A08 review: a failure after the upload is stored rolls back and removes the file', function (): void {
    Storage::fake('public');
    AuditLog::creating(fn () => throw new RuntimeException('audit down'));

    expect(fn () => app(SaveContent::class)->handle($this->editor, ContentType::Banner, null, ['sort_order' => 0, 'heading' => 'H', 'copy' => 'C'], UploadedFile::fake()->image('a.jpg', 400, 400)))
        ->toThrow(RuntimeException::class);

    expect(Banner::query()->count())->toBe(0)->and(Storage::disk('public')->allFiles('content'))->toBe([]);
});
