<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A signed mail-provider event the A14 delivery log uses (P8.3a bounces; P8.3b1 delivered and
 * opened). Opens are counted from the provider's own signed events only — Oppam adds no tracking
 * pixel (owner 2026-11-08 (3)).
 */
enum MailEventKind: string
{
    case Bounced = 'bounced';
    case Delivered = 'delivered';
    case Opened = 'opened';
}
