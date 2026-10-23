<?php

declare(strict_types=1);

namespace App\Enums;

/** Who wrote a line in a support ticket thread: the member (or a guest from the contact form) or an admin. */
enum TicketAuthor: string
{
    case Member = 'MEMBER';
    case Admin = 'ADMIN';
}
