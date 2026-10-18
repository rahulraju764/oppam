<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a member filed a report (PRD M09): from a chat thread (A13, P4.3) or a profile page (A10, P6.2). */
enum ReportSource: string
{
    case Chat = 'CHAT';
    case Profile = 'PROFILE';
}
