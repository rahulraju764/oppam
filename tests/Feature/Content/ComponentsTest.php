<?php

declare(strict_types=1);

use App\Data\Billing\PlanCardData;
use App\Data\Content\StoryData;
use App\Data\Profile\ProfileCardData;
use App\ValueObjects\Money;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/*
| P0.3 — Blade components converted from the template partials, and the base UI kit.
*/

function card(array $overrides = []): ProfileCardData
{
    return new ProfileCardData(...array_merge([
        'code' => 'OPM10001',
        'name' => 'Anna Thomas',
        'photoUrl' => '/images/matches/profile.webp',
        'url' => '/profile/OPM10001',
        'age' => '26 yrs',
        'height' => "5'0\"",
        'education' => 'MBA',
        'occupation' => 'Consultant',
        'place' => 'Thrissur',
        'meta' => 'Last seen an hour ago',
        'isNew' => true,
    ], $overrides));
}

it('renders a profile row that links by code with a stretched link, never an anchor around the card', function (): void {
    $html = Blade::render('<x-profile.row :profile="$p" />', ['p' => card()]);

    expect($html)->toContain('<a href="/profile/OPM10001" class="stretched-link"')
        ->and($html)->toContain('OPM10001')
        ->and($html)->toContain('NEWLY')
        ->and($html)->toContain('Last seen an hour ago')
        ->and($html)->not->toContain('intst-parent');   // no actions slot → no inert buttons
});

it('renders the actions slot of a profile row only when given', function (): void {
    $html = Blade::render('<x-profile.row :profile="$p"><x-slot:actions><button type="button">Accept</button></x-slot:actions></x-profile.row>', ['p' => card()]);

    expect($html)->toContain('intst-parent')->and($html)->toContain('Accept');
});

it('renders no profile link when the viewer may not open the profile', function (string $component): void {
    $html = Blade::render("<x-profile.{$component} :profile=\"\$p\" />", ['p' => card(['url' => null])]);

    expect($html)->not->toContain('href=');
})->with(['row', 'tile', 'member-card']);

it('escapes member-supplied text in every profile component (stored XSS)', function (string $component): void {
    $html = Blade::render("<x-profile.{$component} :profile=\"\$p\" />", ['p' => card([
        'name' => '<script>alert(1)</script>',
        'place' => '<img src=x onerror=alert(2)>',
    ])]);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->toContain('&lt;script&gt;');
})->with(['row', 'tile', 'member-card']);

it('renders a pricing card from paise without floats and only links a CTA that exists', function (): void {
    $plan = new PlanCardData('GOLD', 'Gold', Money::rupees(999), Money::rupees(11988), ['Chat'], isFeatured: true, badge: 'Most Popular');

    $html = Blade::render('<x-pricing.card :plan="$plan" />', ['plan' => $plan]);

    expect($html)->toContain('pricing-card featured')
        ->and($html)->toContain('<span>999</span>')
        ->and($html)->toContain('₹11,988')
        ->and($html)->not->toContain('Purchase Now');

    $withCta = new PlanCardData('GOLD', 'Gold', Money::rupees(999), Money::rupees(11988), [], ctaUrl: '/plans');

    expect(Blade::render('<x-pricing.card :plan="$plan" />', ['plan' => $withCta]))->toContain('href="/plans" class="btn-purchase"');
});

it('renders both story-card variants', function (): void {
    $story = new StoryData('allen-riya', 'Allen & Riya', 'Thrissur', 'Married January 2024', '/images/profile/story-couple.webp', 500, 500, 'We matched.', ['One.']);

    expect(Blade::render('<x-story.card :story="$s" />', ['s' => $story]))->toContain('story-card')->toContain('Allen &amp; Riya')
        ->and(Blade::render('<x-story.card :story="$s" variant="slide" />', ['s' => $story]))->toContain('parent-stories swiper-slide')->toContain($story->url());
});

it('renders the template pagination bar for a paginator, with the current page marked', function (): void {
    $paginator = new LengthAwarePaginator(range(1, 10), total: 200, perPage: 10, currentPage: 5, options: ['path' => '/profiles']);

    $html = Blade::render('<x-ui.pagination :paginator="$p" label="Results pages" />', ['p' => $paginator]);

    expect($html)->toContain('aria-label="Results pages"')
        ->and($html)->toContain('<span class="page-link is-current" aria-current="page">5</span>')
        ->and($html)->toContain('/profiles?page=4')
        ->and($html)->toContain('/profiles?page=20')
        ->and($html)->toContain('page-gap')
        ->and(substr_count($html, 'href="#"'))->toBe(0);
});

it('uses the template pagination bar as the default paginator view', function (): void {
    $paginator = new LengthAwarePaginator([], total: 30, perPage: 10, currentPage: 1, options: ['path' => '/x']);

    expect((string) $paginator->links())->toContain('pagination-bar')
        ->and((string) $paginator->links())->toContain('page-link is-disabled');
});

it('renders nothing for a single page of results', function (): void {
    $paginator = new LengthAwarePaginator([1], total: 1, perPage: 10);

    expect(trim(Blade::render('<x-ui.pagination :paginator="$p" />', ['p' => $paginator])))->toBe('');
});

it('labels every form control and shows the server error under it', function (): void {
    $errors = (new ViewErrorBag)->put('default', new MessageBag(['form.dob' => ['Enter your date of birth.']]));

    view()->share('errors', $errors);   // as ShareErrorsFromSession / Livewire do

    $html = Blade::render('<x-ui.input label="Date of birth" name="dob" wire:model="form.dob" hint="DD/MM/YYYY" required />');

    expect($html)->toContain('<label class="mat-label" for="f-dob">')
        ->and($html)->toContain('id="f-dob" name="dob"')
        ->and($html)->toContain('is-invalid')
        ->and($html)->toContain('aria-describedby="f-dob-hint f-dob-error"')
        ->and($html)->toContain('Enter your date of birth.');
});

it('maps button variants to fixed classes and ignores unknown ones', function (): void {
    expect(Blade::render('<x-ui.button variant="outline">Go</x-ui.button>'))->toContain('ui-btn ui-btn--outline')
        ->and(Blade::render('<x-ui.button variant="evil-class">Go</x-ui.button>'))->toContain('ui-btn ui-btn--primary')->not->toContain('evil-class')
        ->and(Blade::render('<x-ui.button href="/plans">Go</x-ui.button>'))->toContain('<a href="/plans"');
});

it('disables a loading button while its Livewire action runs', function (): void {
    expect(Blade::render('<x-ui.button loading="save">Save</x-ui.button>'))
        ->toContain('wire:loading.attr="disabled" wire:target="save"')
        ->toContain('ui-spinner');
});

it('renders an accessible modal and an empty state', function (): void {
    expect(Blade::render('<x-ui.modal name="confirm" title="Are you sure?">Body</x-ui.modal>'))
        ->toContain('role="dialog" aria-modal="true" aria-labelledby="modal-confirm-title"')
        ->toContain('x-trap.noscroll="open"')
        ->and(Blade::render('<x-ui.empty-state title="No matches yet" message="Complete your preferences." />'))
        ->toContain('No matches yet');
});
