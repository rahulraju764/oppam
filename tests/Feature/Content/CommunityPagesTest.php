<?php

declare(strict_types=1);

use App\Actions\Admin\Content\SaveContent;
use App\Enums\ContentType;
use App\Enums\SettingKey;
use App\Http\Controllers\Public\SitemapController;
use App\Livewire\Public\CommunityLanding;
use App\Models\CommunityPage;
use App\Models\Masters\Caste;
use App\Models\Masters\Religion;
use App\Models\Profile;
use App\Models\Setting;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/*
| M12 SEO (P8.1b, owner 2026-11-06: an A08 content type): community landing pages and the sitemap /
| robots that follow A15 `seo.indexable`. Only published pages have a URL; no profile is ever shown.
*/

function communityInput(array $overrides = []): array
{
    return [...['sort_order' => 0, 'slug' => 'nair-matrimony', 'heading' => 'Nair Matrimony', 'intro' => '<p>Nair families in Kerala.</p>',
        'meta_description' => 'Verified Nair brides and grooms.', 'religion_id' => null, 'caste_id' => null], ...$overrides];
}

function indexable(bool $on): void
{
    Setting::query()->where('key', SettingKey::SeoIndexable->value)->delete();
    Setting::factory()->keyed(SettingKey::SeoIndexable, $on)->create();
    app(SettingsRepository::class)->flush();
    Cache::forget(SitemapController::CACHE_KEY);
}

it('serves a published community page with its own title and one h1 (M12)', function (): void {
    CommunityPage::factory()->published()->create(['slug' => 'christian-matrimony-kerala', 'heading' => 'Christian Matrimony Kerala']);

    $html = $this->get('/christian-matrimony-kerala')->assertOk()
        ->assertSee('<title>Christian Matrimony Kerala | Oppam Matrimony</title>', false)
        ->assertSee('Register free')->getContent();
    expect(substr_count($html, '<h1'))->toBe(1);
});

it('404s a draft, an archived or an unknown community page (M12)', function (string $state): void {
    $factory = CommunityPage::factory();
    $factory = match ($state) {
        'archived' => $factory->archived(),
        'in review' => $factory->inReview(),
        default => $factory,
    };
    $factory->create(['slug' => 'ezhava-matrimony']);

    $this->get('/ezhava-matrimony')->assertNotFound();
    $this->get('/muslim-matrimony')->assertNotFound();
})->with(['draft', 'in review', 'archived']);

it('never lets the community route shadow another page (M12)', function (): void {
    $this->get('/about')->assertOk();
    $this->get('/plans')->assertOk();
    expect(preg_match('/^'.CommunityPage::SLUG_PATTERN.'$/', 'about'))->toBe(0);
});

it('shows a rounded member count, and none for a small community (M12, privacy)', function (): void {
    $religion = Religion::factory()->create();
    Profile::factory()->count(3)->active()->create(['religion_id' => $religion->id]);
    $page = CommunityPage::factory()->published()->create(['religion_id' => $religion->id]);

    $this->get('/'.$page->slug)->assertOk()->assertDontSee('verified members from this community');

    Profile::factory()->count(CommunityLanding::MIN_SHOWN + 1)->active()->create(['religion_id' => $religion->id]);
    Cache::flush();
    $this->get('/'.$page->slug)->assertOk()->assertSee('50+ verified members from this community');
});

it('never lists a profile or a name on a community page (§6.2 guests see no members)', function (): void {
    $religion = Religion::factory()->create();
    $profile = Profile::factory()->active()->create(['religion_id' => $religion->id, 'first_name' => 'Unique', 'last_name' => 'Personname']);
    $page = CommunityPage::factory()->published()->create(['religion_id' => $religion->id]);

    $this->get('/'.$page->slug)->assertOk()->assertDontSee('Personname')->assertDontSee($profile->code);
});

it('validates the address shape, uniqueness and caste under its religion (A08)', function (array $overrides): void {
    seedAdminRoles();
    CommunityPage::factory()->create(['slug' => 'taken-matrimony']);

    expect(fn () => app(SaveContent::class)->handle(contentAdmin('content.edit'), ContentType::Community, null, communityInput($overrides)))
        ->toThrow(ValidationException::class);
})->with([
    'not a community address' => [['slug' => 'about']],
    'upper case' => [['slug' => 'Nair-Matrimony']],
    'already used' => [['slug' => 'taken-matrimony']],
    'caste without religion' => [fn () => ['caste_id' => Caste::factory()->create()->id]],
]);

it('escapes the heading and sanitises the introduction (rule 9)', function (): void {
    seedAdminRoles();
    $page = app(SaveContent::class)->handle(contentAdmin('content.edit'), ContentType::Community, null, communityInput([
        'heading' => '<b>Nair</b> Matrimony', 'intro' => '<p>Hi</p><script>alert(1)</script>',
    ]));

    expect($page->intro)->toBe('<p>Hi</p>');
    $page->forceFill(['status' => App\Enums\ContentStatus::Published])->save();
    $this->get('/nair-matrimony')->assertOk()->assertSee('&lt;b&gt;Nair&lt;/b&gt;', false);
});

it('serves an empty sitemap and disallows crawling while the site is not indexable (M12, §15)', function (): void {
    indexable(false);
    CommunityPage::factory()->published()->create(['slug' => 'nair-matrimony']);

    $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
    expect($xml)->toContain('<urlset')->not->toContain('<url>');
    $this->get('/robots.txt')->assertOk()->assertSee("Disallow: /\n", false)->assertDontSee('Sitemap:');
});

it('lists public pages and published community pages, never members’ pages or drafts (M12)', function (): void {
    indexable(true);
    CommunityPage::factory()->published()->create(['slug' => 'nair-matrimony']);
    CommunityPage::factory()->create(['slug' => 'draft-matrimony']);

    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain(route('home'))->toContain(route('terms'))->toContain(route('grievance'))
        ->toContain('/nair-matrimony')->not->toContain('draft-matrimony')
        ->not->toContain('/dashboard')->not->toContain('/profile/');
    $this->get('/robots.txt')->assertSee('Sitemap: '.route('sitemap'))->assertSee('Disallow: /dashboard');
});

it('keeps crawlers off the admin host whatever the setting (M12, §8.1)', function (): void {
    indexable(true);

    $this->get('http://'.config('oppam.admin_domain').'/robots.txt')->assertOk()->assertSee("Disallow: /\n", false);
});
