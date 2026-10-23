<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a ban puts on the blocklist (PRD A10 "ban (email/phone/device/pHash blocklist)"). Email,
 * phone and device are stored as HMAC hashes (BanList); a photo's perceptual hash is stored as is
 * (it is not personal data and must be compared by Hamming distance).
 */
enum BannedIdentifierType: string
{
    case Email = 'EMAIL';
    case Phone = 'PHONE';
    case Device = 'DEVICE';
    case Phash = 'PHASH';

    public function label(): string
    {
        return match ($this) {
            self::Email => __('Email'),
            self::Phone => __('Mobile number'),
            self::Device => __('Device'),
            self::Phash => __('Photo'),
        };
    }
}
