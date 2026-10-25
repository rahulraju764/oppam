<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Broker\BiodataData;

/** Renders a managed client's biodata as A4 PDF bytes (§11A B.7). Implemented with dompdf (DompdfBiodataRenderer). */
interface BiodataPdfRenderer
{
    public function render(BiodataData $biodata): string;
}
