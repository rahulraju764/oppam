<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A08 editorial workflow: DRAFT → IN_REVIEW → SCHEDULED → PUBLISHED → ARCHIVED. Only PUBLISHED is
 * on the site. The moves live here; who may make them lives in TransitionContent.
 */
enum ContentStatus: string
{
    case Draft = 'DRAFT';
    case InReview = 'IN_REVIEW';
    case Scheduled = 'SCHEDULED';
    case Published = 'PUBLISHED';
    case Archived = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::InReview => __('In review'),
            self::Scheduled => __('Scheduled'),
            self::Published => __('Published'),
            self::Archived => __('Archived'),
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::InReview => 'warning',
            self::Scheduled => 'default',
            self::Published => 'success',
            self::Archived => 'muted',
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::InReview, self::Archived],
            self::InReview => [self::Draft, self::Scheduled, self::Published, self::Archived],
            self::Scheduled => [self::Draft, self::Published, self::Archived],
            self::Published => [self::Archived],
            self::Archived => [self::Draft],
        }, true);
    }
}
