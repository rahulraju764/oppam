<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Data\Content\FaqData;
use App\Data\Content\SiteContactData;
use App\Services\Settings\SiteIdentity;

/**
 * schema.org JSON-LD for the public pages (PRD §14 SEO "structured data (Organization, FAQ)"; P8.1a → P9.3b).
 * Built only from what the site already shows: the A15 site identity / contact details / social links and
 * the published FAQ. The head renders each block with Blade's @json, which escapes < > & and quotes, so
 * CMS text can't close the <script>.
 */
final class StructuredData
{
    public function __construct(private readonly SiteIdentity $identity) {}

    /** @return array<string, mixed> */
    public function organization(): array
    {
        $contact = SiteContactData::current();
        $dial = array_key_first($contact->phones);
        $phone = $dial === null || trim((string) $dial) === '' ? null : trim((string) $dial);
        $email = $contact->emails[0] ?? null;

        $organization = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => (string) config('oppam.site.name'),
            'url' => url('/'),
            'logo' => $this->identity->logoUrl(),
        ];

        if ($phone !== null || $email !== null) {
            $organization['contactPoint'] = [array_filter([
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'telephone' => $phone,
                'email' => $email,
                'areaServed' => 'IN',
                'availableLanguage' => ['English', 'Malayalam'],
            ], fn ($value): bool => $value !== null)];
        }

        if ($contact->social !== []) {
            $organization['sameAs'] = array_values($contact->social);
        }

        return $organization;
    }

    /**
     * @param  list<FaqData>  $faqs
     * @return array<string, mixed>|null null when there is no FAQ to describe
     */
    public function faqPage(array $faqs): ?array
    {
        if ($faqs === []) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (FaqData $faq): array => [
                '@type' => 'Question',
                'name' => $faq->question,
                // The answer as published (sanitised CMS HTML) — schema.org Answer text allows basic HTML.
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq->answerHtml],
            ], $faqs),
        ];
    }
}
