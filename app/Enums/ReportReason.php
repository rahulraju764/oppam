<?php

declare(strict_types=1);

namespace App\Enums;

/** Why a member reports another (PRD M09 report reasons). Reports go to A13 (chat) and A10 (P6.2). */
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

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
