<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an A10 reviewer decided for an abuse case (PRD A10 outcomes). Every outcome closes the
 * case, settles its reports and is recorded in `safety_actions` and the audit log. Each needs
 * `safety.reports.act`; a ban also needs `safety.ban`, a suspension also `members.suspend`
 * (SuspendMember checks it).
 */
enum CaseOutcome: string
{
    case NoAction = 'NO_ACTION';
    case Warning = 'WARNING';
    case ContentRemoval = 'CONTENT_REMOVAL';
    case Hide = 'HIDE';
    case Suspend = 'SUSPEND';
    case Ban = 'BAN';

    public function label(): string
    {
        return match ($this) {
            self::NoAction => __('No action'),
            self::Warning => __('Warn the member'),
            self::ContentRemoval => __('Remove content'),
            self::Hide => __('Hide the profile'),
            self::Suspend => __('Suspend the account'),
            self::Ban => __('Ban the member'),
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::NoAction => 'muted',
            self::Warning, self::ContentRemoval, self::Hide => 'warning',
            self::Suspend, self::Ban => 'danger',
        };
    }

    /** The permission this outcome needs on top of `safety.reports.act`. */
    public function extraPermission(): ?string
    {
        return match ($this) {
            self::Ban => 'safety.ban',
            self::Suspend => 'members.suspend',
            self::Hide => 'members.edit',
            default => null,
        };
    }

    /** NO_ACTION dismisses the reports; everything else actions them. */
    public function reportStatus(): ReportStatus
    {
        return $this === self::NoAction ? ReportStatus::Dismissed : ReportStatus::Actioned;
    }

    /** Outcomes that change the account itself need a typed reason (A03 rule). */
    public function needsReason(): bool
    {
        return in_array($this, [self::Hide, self::Suspend, self::Ban], true);
    }
}
