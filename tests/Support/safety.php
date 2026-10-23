<?php

declare(strict_types=1);

use App\Actions\Safety\ReportProfile;
use App\Data\Support\TicketRequestData;
use App\Models\AbuseCase;
use App\Models\Report;
use App\Models\User;

/*
| Safety & support test helpers (P6.2, PRD M09 / A10). Need seedMasters() + PlansSeeder (and
| seedAdminRoles() for admin actions). Members are the usual groom / bride pair; extra reporters are
| more brides on their own numbers (they see the groom; the groom sees them).
*/

function profileReport(User $reporter, User $reported, string $reason = 'FAKE_PROFILE', ?string $details = null): Report
{
    return app(ReportProfile::class)->handle($reporter, $reported->profile, $reason, $details);
}

/** The reported member's open case. */
function openCaseOf(User $reported): ?AbuseCase
{
    return AbuseCase::query()->where('open_key', $reported->profile->id)->first();
}

/** Another bride on her own number (a distinct reporter). */
function reporterNo(int $n): User
{
    return bride('+9198000005'.str_pad((string) $n, 2, '0', STR_PAD_LEFT));
}

/** @param  array<string, string|null>  $overrides */
function ticketData(array $overrides = []): TicketRequestData
{
    return new TicketRequestData(
        name: $overrides['name'] ?? 'Asha Menon',
        email: array_key_exists('email', $overrides) ? $overrides['email'] : 'asha@example.com',
        phone: $overrides['phone'] ?? null,
        category: $overrides['category'] ?? 'ACCOUNT',
        subject: $overrides['subject'] ?? 'Cannot log in',
        message: $overrides['message'] ?? 'I tried to log in this morning and the code never came.',
    );
}
