<?php

declare(strict_types=1);

namespace App\Enums;

/** A "download my data" request (PRD M14): being built, ready for 24 h, failed, or gone. */
enum DataExportStatus: string
{
    case Queued = 'QUEUED';
    case Ready = 'READY';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';
}
