<?php

declare(strict_types=1);

namespace App\Services\Broker;

use App\Models\Broker;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A bureau's referral link and its QR code (B.5 materials): `oppam.in/?ref=BRK1042`, which sets
 * the 30-day first-touch cookie (R-M13-9). SVG for screens and print, PNG (GD) for messaging apps.
 * Dark modules on white so any phone camera reads it.
 */
final class ReferralQrCode
{
    public const PNG_SIZE = 1024;

    public function link(Broker $broker): string
    {
        return route('home', ['ref' => $broker->code]);
    }

    public function svg(Broker $broker, int $size = 240): string
    {
        return (new Writer(new ImageRenderer(
            new RendererStyle($size, 2, null, null, self::fill()),
            new SvgImageBackEnd,
        )))->writeString($this->link($broker));
    }

    public function png(Broker $broker, int $size = self::PNG_SIZE): string
    {
        return (new Writer(new GDLibRenderer($size, 4, 'png', 9, self::fill())))->writeString($this->link($broker));
    }

    private static function fill(): Fill
    {
        return Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(45, 45, 45));
    }
}
