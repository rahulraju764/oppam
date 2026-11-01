<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\CreditNote;

/** Renders a GST credit note as PDF bytes (A06). */
interface CreditNoteRenderer
{
    public function render(CreditNote $note): string;
}
