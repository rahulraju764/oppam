<?php

declare(strict_types=1);

use App\Livewire\Public\About;
use App\Livewire\Public\Branches;
use App\Livewire\Public\Contact;
use App\Livewire\Public\Home;
use App\Livewire\Public\Plans;
use App\Livewire\Public\SuccessStories;
use Database\Seeders\PlansSeeder;
use Livewire\Livewire;

/*
| P0.3 — the public page components render their template content (PRD M12).
*/

beforeEach(function (): void {
    $this->seed(PlansSeeder::class);
});

it('renders each public page component', function (string $component, string $expected): void {
    Livewire::test($component)->assertOk()->assertSee($expected);
})->with([
    [Home::class, 'Create your free profile'],
    [About::class, 'Marriages that begin with trust'],
    [Branches::class, 'Thiruvananthapuram'],
    [SuccessStories::class, 'Allen & Riya'],
    [Plans::class, 'Frequently Asked Questions'],
    [Contact::class, 'Get in Touch'],
]);

it('renders the six template members on the home page without links for a visitor', function (): void {
    Livewire::test(Home::class)
        ->assertSeeInOrder(['Reshma', 'Anjali', 'Ann', 'Mariya', 'Neethu', 'Athira'])
        ->assertDontSeeHtml('class="member-link"');
});

it('opens the first FAQ in each column and wires the accordion accessibly', function (): void {
    Livewire::test(Plans::class)
        ->assertSeeHtml('aria-expanded="true" aria-controls="faq-0-0"')
        ->assertSeeHtml('aria-expanded="true" aria-controls="faq-1-0"')
        ->assertSeeHtml('aria-expanded="false" aria-controls="faq-0-1"');
});
