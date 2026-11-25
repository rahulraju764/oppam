<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Models\FaqItem;
use App\Models\Setting;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\ContentSeeder;
use Database\Seeders\PlansSeeder;

/*
| P9.3b (PRD §14 SEO: "structured data (Organization, FAQ)"; P8.1a hand-over): schema.org JSON-LD on the
| public home (Organization, from the A15 site identity / contact / social settings) and on /plans
| (FAQPage, from the published FAQ). Content can never close the <script> (Blade @json escaping).
*/

beforeEach(function (): void {
    $this->seed(PlansSeeder::class);
    $this->seed(ContentSeeder::class);
});

function siteSetting(SettingKey $key, string $value): void
{
    (new Setting)->forceFill(['key' => $key->value, 'value' => $value])->save();
    app(SettingsRepository::class)->flush();
}

/** @return list<array<string, mixed>> every JSON-LD block in the page */
function jsonLdBlocks(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_map(fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $m[1]);
}

it('SEO: the home page describes the Organization — name, url, logo, contact point, social profiles', function (): void {
    siteSetting(SettingKey::SitePhoneDial, '+914842000000');
    siteSetting(SettingKey::SiteSupportEmail, 'help@oppam.test');
    siteSetting(SettingKey::SocialFacebook, 'https://facebook.com/oppam');
    siteSetting(SettingKey::SocialInstagram, 'https://instagram.com/oppam');

    $blocks = jsonLdBlocks($this->get('/')->assertOk()->getContent());
    $org = collect($blocks)->firstWhere('@type', 'Organization');

    expect($org)->not->toBeNull()
        ->and($org['@context'])->toBe('https://schema.org')
        ->and($org['name'])->toBe(config('oppam.site.name'))
        ->and($org['url'])->toBe(url('/'))
        ->and($org['logo'])->toBeString()->not->toBe('')
        ->and($org['contactPoint'][0])->toMatchArray(['@type' => 'ContactPoint', 'telephone' => '+914842000000', 'email' => 'help@oppam.test'])
        ->and($org['sameAs'])->toEqualCanonicalizing(['https://facebook.com/oppam', 'https://instagram.com/oppam']);
});

it('SEO: /plans describes its published FAQ as an FAQPage; drafts are left out', function (): void {
    FaqItem::query()->delete();
    FaqItem::factory()->published()->create(['question' => 'Is my phone number private?', 'answer' => '<p>Yes, until you choose to share it.</p>']);
    FaqItem::factory()->create(['question' => 'A draft question?']);

    $faq = collect(jsonLdBlocks($this->get('/plans')->assertOk()->getContent()))->firstWhere('@type', 'FAQPage');

    expect($faq['mainEntity'])->toHaveCount(1)
        ->and($faq['mainEntity'][0])->toMatchArray(['@type' => 'Question', 'name' => 'Is my phone number private?'])
        ->and($faq['mainEntity'][0]['acceptedAnswer'])->toMatchArray(['@type' => 'Answer', 'text' => '<p>Yes, until you choose to share it.</p>']);
});

it('SEO security: content can\'t close the script tag — < > & are escaped inside the JSON-LD', function (): void {
    FaqItem::query()->delete();
    FaqItem::factory()->published()->create(['question' => 'Break out? </script><script>alert(1)</script> & more', 'answer' => '<p>No.</p>']);

    $html = $this->get('/plans')->assertOk()->getContent();

    expect($html)->not->toContain('</script><script>alert(1)')
        ->and(collect(jsonLdBlocks($html))->firstWhere('@type', 'FAQPage')['mainEntity'][0]['name'])
        ->toBe('Break out? </script><script>alert(1)</script> & more');   // intact once parsed as JSON
});

it('SEO: no FAQ published → no FAQPage block; member pages carry no structured data', function (): void {
    FaqItem::query()->delete();

    expect(collect(jsonLdBlocks($this->get('/plans')->assertOk()->getContent()))->firstWhere('@type', 'FAQPage'))->toBeNull();

    $this->actingAs(memberWithPhone());
    expect(jsonLdBlocks($this->get(memberUrl('/settings'))->getContent()))->toBe([]);
});
