<?php

declare(strict_types=1);

namespace App\Data\Broker;

use App\Enums\BrokerExportType;

/**
 * What an allowed ExportManagedProfile handed the controller to stream (§11A B.7): the biodata
 * data for a BIODATA_PDF, or the approved originals for a PHOTO_PACKAGE.
 */
final readonly class ExportSnapshot
{
    /** @param  list<PhotoFile>  $photos */
    public function __construct(
        public BrokerExportType $type,
        public string $profileCode,
        public ?BiodataData $biodata = null,
        public array $photos = [],
    ) {}
}
