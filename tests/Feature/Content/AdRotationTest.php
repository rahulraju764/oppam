<?php

declare(strict_types=1);

use App\Actions\Admin\Content\SaveContent;
use App\Actions\Admin\Content\TransitionContent;
use App\Enums\AdAudience;
use App\Enums\AdSlot;
use App\Enums\ContentType;
use App\Exceptions\Admin\ContentRefused;
use App\Models\AdCreative;
use App\Models\Profile;
use App\Queries\Content\AdRotation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;

/*
| A08 ad slots (P8.1b): the member right rail (template ads.php) shows published creatives inside
| their window, for the viewer's audience (free / paid), picked by weight. No demo creatives.
*/

function rail(?App\Models\User $viewer): App\Data\Content\RailAdsData
{
    app()->forgetScopedInstances();

    return app(AdRotation::class)->forViewer($viewer);
}

it('shows only published creatives inside their running window (A08)', function (): void {
    AdCreative::factory()->published()->create(['heading' => 'Live now']);
    AdCreative::factory()->create(['heading' => 'Still a draft']);
    AdCreative::factory()->published()->create(['heading' => 'Ended', 'ends_at' => now()->subMinute()]);
    AdCreative::factory()->published()->create(['heading' => 'Not yet', 'starts_at' => now()->addDay()]);

    expect(rail(memberWithPhone())->promo?->heading)->toBe('Live now');
});

it('targets free members, paid members or everyone (A08 audience)', function (): void {
    AdCreative::factory()->published()->audience(AdAudience::FreeOnly)->create(['heading' => 'Upgrade offer']);
    AdCreative::factory()->published()->slot(AdSlot::Banner)->audience(AdAudience::Paid)->create(['heading' => 'Members event']);

    $free = memberWithPhone('+919876500001');
    $paid = memberWithPhone('+919876500002');
    Profile::query()->where('user_id', $paid->id)->update(['is_premium' => true]);

    $freeRail = rail($free);
    $paidRail = rail($paid);

    expect($freeRail->promo?->heading)->toBe('Upgrade offer')->and($freeRail->banner)->toBeNull()
        ->and($paidRail->promo)->toBeNull()->and($paidRail->banner?->heading)->toBe('Members event')
        ->and($freeRail->showUpgrade)->toBeTrue()->and($paidRail->showUpgrade)->toBeFalse();
});

it('picks one promo by weight among those competing (A08 weighted rotation)', function (): void {
    AdCreative::factory()->published()->create(['heading' => 'Heavy', 'weight' => 100]);
    AdCreative::factory()->published()->create(['heading' => 'Light', 'weight' => 1]);
    $member = memberWithPhone();

    $seen = collect(range(1, 60))->map(fn () => rail($member)->promo?->heading)->countBy();

    expect($seen->get('Heavy', 0))->toBeGreaterThan($seen->get('Light', 0));
});

it('shows every carousel slide in order, up to the cap (A08)', function (): void {
    foreach (range(1, AdRotation::MAX_SLIDES + 2) as $n) {
        AdCreative::factory()->published()->slot(AdSlot::Carousel)->create(['heading' => 'Slide '.$n, 'sort_order' => $n]);
    }

    $slides = rail(memberWithPhone())->carousel;

    expect($slides)->toHaveCount(AdRotation::MAX_SLIDES)->and($slides[0]->heading)->toBe('Slide 1');
});

it('needs an image for a banner or slide, not for a promo, and no past end date (A08)', function (): void {
    $banner = AdCreative::factory()->inReview()->create(['slot' => AdSlot::Banner, 'image_path' => null]);
    $promo = AdCreative::factory()->inReview()->create(['slot' => AdSlot::Promo]);
    $ended = AdCreative::factory()->inReview()->create(['ends_at' => now()->subDay()]);
    seedAdminRoles();
    $publisher = contentAdmin('content.publish');
    $flow = app(TransitionContent::class);

    expect(fn () => $flow->publish($publisher, $banner))->toThrow(ContentRefused::class);
    expect(fn () => $flow->publish($publisher, $ended))->toThrow(ContentRefused::class);
    expect($flow->publish($publisher, $promo)->isLive())->toBeTrue();
});

it('takes IST start and end times and stores them in UTC (A08, dates)', function (): void {
    seedAdminRoles();
    $ad = app(SaveContent::class)->handle(contentAdmin('content.edit'), ContentType::Ad, null, [
        'sort_order' => 0, 'slot' => 'PROMO', 'audience' => 'ALL', 'weight' => 5,
        'starts_at' => '2026-12-01T09:30', 'ends_at' => '2026-12-31T21:00',
        'heading' => 'Onam', 'copy' => 'Offer', 'cta_label' => 'See plans', 'cta_url' => '/plans',
    ]);

    expect($ad->starts_at?->utc()->format('Y-m-d H:i'))->toBe('2026-12-01 04:00');
});

it('refuses a link that is not a page here or https, and an end before the start (A08 validation)', function (string $field, string $value): void {
    seedAdminRoles();
    $input = ['sort_order' => 0, 'slot' => 'PROMO', 'audience' => 'ALL', 'weight' => 1, 'heading' => 'X', 'cta_label' => 'Go', 'cta_url' => '/plans', 'starts_at' => '2026-12-10T10:00'];

    expect(fn () => app(SaveContent::class)->handle(contentAdmin('content.edit'), ContentType::Ad, null, [...$input, $field => $value]))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect(AdCreative::query()->count())->toBe(0);
})->with([
    'javascript link' => ['cta_url', 'javascript:alert(1)'],
    'protocol-relative link' => ['cta_url', '//evil.example'],
    'end before start' => ['ends_at', '2026-12-01T10:00'],
    'weight too big' => ['weight', '500'],
    'unknown audience' => ['audience', 'EVERYONE'],
]);

it('renders the rail with escaped text, sponsored external links and no demo creatives (A08, rule 9)', function (): void {
    AdCreative::factory()->published()->create(['heading' => '<script>alert(1)</script>', 'cta_url' => 'https://partner.example/offer']);
    $this->actingAs(memberWithPhone());
    app()->forgetScopedInstances();

    $html = Blade::render('<x-ads.rail />');

    expect($html)->toContain('&lt;script&gt;')->not->toContain('<script>alert')
        ->toContain('rel="noopener sponsored"')
        ->not->toContain('ed-ad1.jpeg')->not->toContain('images/home/ad1.jpg');
});

it('falls back to the membership card for a free member when no promo is live, and shows paid members nothing (A08)', function (): void {
    $free = memberWithPhone('+919876500011');
    $paid = memberWithPhone('+919876500012');
    Profile::query()->where('user_id', $paid->id)->update(['is_premium' => true]);

    $this->actingAs($free);
    app()->forgetScopedInstances();
    expect(Blade::render('<x-ads.rail />'))->toContain('Go Premium');

    $this->actingAs($paid);
    app()->forgetScopedInstances();
    expect(Blade::render('<x-ads.rail />'))->not->toContain('Go Premium')->not->toContain('ad-unit');
});

it('reads the creatives in one query however many there are (A08 performance)', function (): void {
    AdCreative::factory()->count(3)->published()->create();
    AdCreative::factory()->count(3)->published()->slot(AdSlot::Carousel)->create();
    $member = memberWithPhone();
    app()->forgetScopedInstances();

    DB::enableQueryLog();
    app(AdRotation::class)->forViewer($member);
    app(AdRotation::class)->forViewer($member);

    expect(count(DB::getQueryLog()))->toBe(2);   // the viewer's plan flag + the creatives, once per request
});
