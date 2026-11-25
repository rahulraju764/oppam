<?php

declare(strict_types=1);

use App\Actions\Admin\Content\TransitionContent;
use App\Actions\Admin\Content\WithdrawStory;
use App\Contracts\HtmlSanitizer;
use App\Enums\ContentStatus;
use App\Enums\HomeBlockKey;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\FaqItem;
use App\Models\HomeBlock;
use App\Models\SuccessStory;
use App\Models\Testimonial;
use Database\Seeders\ContentSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoContentSeeder;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/* M12 (P8.1a): the public pages come from A08. What isn't published isn't shown, and nothing is invented. */

beforeEach(function (): void {
    $this->seed(PlansSeeder::class);
});

/** P9.3c budget: the home band shows from ONE featured member, and member photos go to the fake disks. */
function budgetFeaturedMembersOn(): void
{
    fakeMediaDisks();
    App\Models\Setting::query()->whereKey(App\Enums\SettingKey::ContentHomeMembersMin->value)->delete();
    App\Models\Setting::factory()->keyed(App\Enums\SettingKey::ContentHomeMembersMin, 1)->create();
    app(App\Services\Settings\SettingsRepository::class)->flush();
}

/** A member FeaturedMembers shows: premium, verified, opted in, photos visible to all, an approved converted photo. */
function budgetFeaturedMember(): void
{
    $user = contentMember();
    addTestPhoto($user, convert: true)->forceFill(['moderation_status' => App\Enums\PhotoStatus::Approved])->save();
    $user->profile->forceFill(['first_name' => 'Featured', 'is_premium' => true, 'is_verified' => true])->save();
    $settings = App\Models\PrivacySetting::query()->find($user->profile->id) ?? (new App\Models\PrivacySetting)->forceFill(['profile_id' => $user->profile->id]);
    $settings->forceFill(['photo_visibility' => App\Enums\PhotoVisibility::AllMembers, 'show_on_home' => true, 'hide_from_search' => false])->save();
}

it('M12: the home page is DB-driven: changing a block, banner or testimonial changes the page', function (): void {
    $this->seed(ContentSeeder::class);
    HomeBlock::query()->where('block_key', 'steps')->sole()->forceFill(['content' => [...HomeBlock::query()->where('block_key', 'steps')->sole()->content, 'heading' => 'Edited steps heading']])->save();
    Banner::factory()->published()->create(['heading' => 'Edited banner', 'sort_order' => 99]);
    Testimonial::factory()->published()->create(['name' => 'Edited Person']);

    $this->get('/')->assertOk()->assertSee('Edited steps heading')->assertSee('Edited banner')->assertSee('Edited Person');
});

it('M12: unpublished content is off the page in every other status', function (ContentStatus $status): void {
    $this->seed(ContentSeeder::class);
    Banner::factory()->create(['heading' => 'Hidden banner', 'status' => $status]);
    Testimonial::factory()->create(['name' => 'Hidden Person', 'status' => $status]);
    Branch::factory()->create(['city' => 'Hidden City', 'status' => $status]);
    FaqItem::factory()->create(['question' => 'Hidden question?', 'status' => $status]);
    SuccessStory::factory()->create(['couple' => 'Hidden Couple', 'status' => $status]);

    $this->get('/')->assertDontSee('Hidden banner')->assertDontSee('Hidden Person');
    $this->get('/branches')->assertDontSee('Hidden City');
    $this->get('/plans')->assertDontSee('Hidden question?');
    $this->get('/success-stories')->assertDontSee('Hidden Couple');
})->with([ContentStatus::Draft, ContentStatus::InReview, ContentStatus::Scheduled, ContentStatus::Archived]);

it('M12: with nothing published every page still opens, with one h1 and its empty states', function (): void {
    foreach (['/', '/about', '/branches', '/success-stories', '/plans'] as $uri) {
        expect(substr_count($this->get($uri)->assertOk()->getContent(), '<h1'))->toBe(1);
    }

    $this->get('/branches')->assertSee('Our offices will be listed here soon');
    $this->get('/success-stories')->assertSee('No stories yet');
    $this->get('/plans')->assertDontSee('Frequently Asked Questions');
    $this->get('/')->assertSee('is-empty', false)->assertSee('quick-register', false)->assertDontSee('testimonial-section', false);
});

it('M12: an archived block takes its section off the page', function (): void {
    $this->seed(ContentSeeder::class);
    $block = HomeBlock::query()->where('block_key', HomeBlockKey::Steps)->sole();
    $this->get('/')->assertSee($block->content['heading']);

    $block->forceFill(['status' => ContentStatus::Archived])->save();

    $this->get('/')->assertOk()->assertDontSee($block->content['heading'])->assertDontSee('work-section', false);
});

it('M12: counters are real figures; a figure at zero and the template’s made-up ones are gone', function (): void {
    $this->seed(ContentSeeder::class);
    Cache::flush();

    $this->get('/')->assertDontSee('Downloaded App')->assertDontSee('data-target="105"', false)->assertDontSee('about-stats', false);

    Branch::factory()->published()->count(3)->create();
    Cache::flush();

    $this->get('/about')->assertSee('data-target="3"', false)->assertSee('Branches')->assertDontSee('data-target="0"', false)->assertDontSee('images/about/heart.png', false);
});

it('M12: counters are cached for a day', function (): void {
    $this->seed(ContentSeeder::class);
    Cache::flush();
    Branch::factory()->published()->create();
    $this->get('/')->assertSee('data-target="1"', false);

    Branch::factory()->published()->create();
    $this->get('/')->assertSee('data-target="1"', false);

    $this->travel(25)->hours();
    $this->get('/')->assertSee('data-target="2"', false);
});

it('M12: published order is respected', function (): void {
    Branch::factory()->published()->create(['city' => 'Second', 'sort_order' => 2]);
    Branch::factory()->published()->create(['city' => 'First', 'sort_order' => 1]);

    $this->get('/branches')->assertSeeInOrder(['First', 'Second']);
});

it('M12: stored rich text renders, while plain fields stay escaped', function (): void {
    SuccessStory::factory()->published()->create(['couple' => '<script>x</script>Pair', 'body' => '<p><strong>Bold part</strong></p>']);
    Branch::factory()->published()->create(['city' => '<b>City</b>']);

    $this->get('/success-stories')->assertSee('<strong>Bold part</strong>', false)->assertDontSee('<script>x</script>', false);
    $this->get('/branches')->assertDontSee('<b>City</b>', false);
});

it('M12: a branch shows its map link, phone and hours; a story keeps its anchor', function (): void {
    Branch::factory()->published()->create(['map_url' => 'https://maps.example/x', 'tel' => '+914872441100']);
    SuccessStory::factory()->published()->create(['slug' => 'asha-binu']);

    $this->get('/branches')->assertSee('https://maps.example/x', false)->assertSee('tel:+914872441100', false)->assertSee('View on map');
    $this->get('/success-stories')->assertSee('id="story-asha-binu"', false);
});

it('M12: content pages don’t grow queries with their rows', function (string $uri, Closure $add): void {
    $this->seed(ContentSeeder::class);
    Cache::flush();
    $add(2);
    $this->get($uri)->assertOk();

    DB::enableQueryLog();
    $this->get($uri)->assertOk();
    $few = count(DB::getQueryLog());

    $add(20);
    DB::flushQueryLog();
    $this->get($uri)->assertOk();

    expect(count(DB::getQueryLog()))->toBe($few)
        ->and($few)->toBeGreaterThan(0);
})->with([
    'branches' => ['/branches', function (int $n): void {
        Branch::factory()->published()->count($n)->create();
        expect(Branch::query()->live()->count())->toBeGreaterThanOrEqual($n);
    }],
    'stories' => ['/success-stories', function (int $n): void {
        SuccessStory::factory()->published()->count($n)->create();
        expect(SuccessStory::query()->live()->count())->toBeGreaterThanOrEqual($n);
    }],
    'faq' => ['/plans', function (int $n): void {
        FaqItem::factory()->published()->count($n)->create();
        expect(FaqItem::query()->live()->count())->toBeGreaterThanOrEqual($n);
    }],
    'home' => ['/', function (int $n): void {
        Testimonial::factory()->published()->count($n)->create();
        expect(Testimonial::query()->live()->count())->toBeGreaterThanOrEqual($n);
    }],
    // P9.3c: the home page also grows its banners and its featured-members band — still no query per row.
    // The members are REAL featured members (premium, verified, opted in, approved photo with its card
    // conversion — FeaturedMembers' rules), and the band is asserted to show them (review round 1: a plain
    // member never reaches the band, which made this check vacuous).
    'home: banners + featured members' => ['/', function (int $n): void {
        budgetFeaturedMembersOn();
        Banner::factory()->published()->count(min($n, 6))->create();
        foreach (range(1, $n) as $_) {
            budgetFeaturedMember();
        }
        // A member abroad shows their country from the cached master list — read once, not per row. The profile
        // factory creates master rows, which bump the masters cache version (MasterDataObserver), so the list is
        // warmed AFTER the members are made: both renders then compare like for like.
        app(App\Services\Masters\Masters::class)->countries();
        expect(Banner::query()->live()->count())->toBeGreaterThanOrEqual(min($n, 6))
            ->and(count(app(App\Queries\Content\FeaturedMembers::class)->forViewer(null)))->toBeGreaterThanOrEqual(min($n, 2));
    }],
]);

it('M12: the seeded counter label says what the figure counts', function (): void {
    $this->seed(ContentSeeder::class);

    expect(HomeBlock::query()->where('block_key', 'counters')->sole()->content['members_label'])->toBe('Members');
});

it('M12: a publish or withdrawal shows in the counters at once', function (): void {
    $this->seed(ContentSeeder::class);
    seedAdminRoles();
    $admin = adminWithRole('content_editor');
    Cache::flush();
    $this->get('/')->assertDontSee('about-stats', false);

    $story = SuccessStory::factory()->inReview()->create();
    app(TransitionContent::class)->publish($admin, $story);
    $this->get('/')->assertSee('data-target="1"', false);

    app(WithdrawStory::class)->handle($admin, $story->refresh(), 'Partner asked to remove it');
    $this->get('/')->assertDontSee('about-stats', false);
});

it('DemoContentSeeder refuses to run outside local and testing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app(DemoContentSeeder::class)->run(app(HtmlSanitizer::class)))->toThrow(RuntimeException::class)
        ->and(SuccessStory::query()->count())->toBe(0);
});

it('ContentSeeder: safe everywhere, re-runnable, never overwrites an edit, and has no people in it', function (): void {
    $this->seed(ContentSeeder::class);
    $counts = [Banner::query()->count(), FaqItem::query()->count(), HomeBlock::query()->count()];
    expect(HomeBlock::query()->count())->toBe(count(HomeBlockKey::cases()))
        ->and(HomeBlock::query()->where('status', '!=', ContentStatus::Published)->count())->toBe(0);

    $banner = Banner::query()->firstOrFail();
    $banner->forceFill(['heading' => 'An admin’s edit'])->save();
    $this->seed(ContentSeeder::class);

    expect($banner->refresh()->heading)->toBe('An admin’s edit')
        ->and([Banner::query()->count(), FaqItem::query()->count(), HomeBlock::query()->count()])->toBe($counts)
        ->and(SuccessStory::query()->count())->toBe(0)
        ->and(Testimonial::query()->count())->toBe(0)
        ->and(Branch::query()->count())->toBe(0);
});

it('the seeded FAQ answers are stored clean', function (): void {
    $this->seed(ContentSeeder::class);

    expect(FaqItem::query()->firstOrFail()->answer)->toStartWith('<p>')->not->toContain('<script');
});

it('DatabaseSeeder outside local leaves demo people out of the site', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(app()->environment('local'))->toBeFalse()
        ->and(SuccessStory::query()->count())->toBe(0)
        ->and(HomeBlock::query()->count())->toBeGreaterThan(0);
});
