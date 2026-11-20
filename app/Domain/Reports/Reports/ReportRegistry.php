<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Enums\ReportKey;

/** Which class builds each A09 report (P9.1a / P9.1b). */
final class ReportRegistry
{
    public function for(ReportKey $key): ReportDefinition
    {
        return match ($key) {
            ReportKey::Growth => app(GrowthReport::class),
            ReportKey::Engagement => app(EngagementReport::class),
            ReportKey::Marketplace => app(MarketplaceReport::class),
            ReportKey::Revenue => app(RevenueReport::class),
            ReportKey::Trust => app(TrustReport::class),
        };
    }
}
