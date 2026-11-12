<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Enums\ReportKey;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Which class builds each A09 report (P9.1a); a report not built yet is 404. */
final class ReportRegistry
{
    public function for(ReportKey $key): ReportDefinition
    {
        return match ($key) {
            ReportKey::Growth => app(GrowthReport::class),
            ReportKey::Engagement => app(EngagementReport::class),
            ReportKey::Marketplace => app(MarketplaceReport::class),
            default => throw new NotFoundHttpException,
        };
    }
}
