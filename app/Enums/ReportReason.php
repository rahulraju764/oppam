<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a member reports another (PRD M09 report reasons). Chat reports go to A13 as flags; every
 * report joins the reported member's A10 abuse case, whose priority follows priority() below.
 */
enum ReportReason: string
{
    case FakeProfile = 'FAKE_PROFILE';
    case AlreadyMarried = 'ALREADY_MARRIED';
    case InappropriatePhotos = 'INAPPROPRIATE_PHOTOS';
    case AbusiveMessages = 'ABUSIVE_MESSAGES';
    case AskingForMoney = 'ASKING_FOR_MONEY';
    case Spam = 'SPAM';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::FakeProfile => __('Fake profile'),
            self::AlreadyMarried => __('Already married'),
            self::InappropriatePhotos => __('Inappropriate photos'),
            self::AbusiveMessages => __('Abusive messages'),
            self::AskingForMoney => __('Asking for money'),
            self::Spam => __('Spam'),
            self::Other => __('Something else'),
        };
    }

    /** A10 triage (decisions 2026-10-23): money = P0, abuse / photos / fake = P1, the rest = P2. */
    public function priority(): CasePriority
    {
        return match ($this) {
            self::AskingForMoney => CasePriority::P0,
            self::AbusiveMessages, self::InappropriatePhotos, self::FakeProfile => CasePriority::P1,
            self::AlreadyMarried, self::Spam, self::Other => CasePriority::P2,
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
