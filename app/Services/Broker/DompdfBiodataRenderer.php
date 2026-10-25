<?php

declare(strict_types=1);

namespace App\Services\Broker;

use App\Contracts\BiodataPdfRenderer;
use App\Data\Broker\BiodataData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;

/**
 * The bureau biodata PDF (§11A B.7, resources/views/pdf/biodata.blade.php) via
 * barryvdh/laravel-dompdf: A4, Noto Sans + Noto Sans Malayalam (resources/fonts, OFL), the bureau's
 * name, code and phone in the footer and "Prepared by <bureau>, Oppam Matrimony" watermarked on
 * every page, so a leaked copy is traceable to the bureau (B.18). Rendered on request (small,
 * throttled) like the poster and invoice PDFs; the photo arrives as a data URI, so dompdf never
 * reads a file or URL itself (remote off).
 */
final class DompdfBiodataRenderer implements BiodataPdfRenderer
{
    public function render(BiodataData $biodata): string
    {
        $font = fn (string $file): string => str_replace('\\', '/', resource_path('fonts/'.$file));
        // dompdf caches the metrics of embedded @font-face fonts here (barryvdh default font_dir / font_cache).
        File::ensureDirectoryExists(storage_path('fonts'));

        return Pdf::loadView('pdf.biodata', [
            'bio' => $biodata,
            'site' => (string) config('oppam.site.name'),
            'fonts' => [
                'regular' => $font('NotoSans-Regular.ttf'),
                'bold' => $font('NotoSans-Bold.ttf'),
                'mlRegular' => $font('NotoSansMalayalam-Regular.ttf'),
                'mlBold' => $font('NotoSansMalayalam-Bold.ttf'),
            ],
        ])->setPaper('a4')->setOption(['isRemoteEnabled' => false, 'isFontSubsettingEnabled' => true])->output();
    }
}
