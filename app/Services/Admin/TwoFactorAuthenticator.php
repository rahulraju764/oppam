<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\AdminUser;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticationProvider;

/**
 * TOTP for admins (PRD A01: mandatory 2FA). Wraps Fortify's provider — which also refuses a
 * code that was already used inside its time window (replay) — and manages the 10 single-use
 * recovery codes, stored only as hashes and shown to the admin once.
 */
final class TwoFactorAuthenticator
{
    public function __construct(private readonly TwoFactorAuthenticationProvider $provider) {}

    /** 32 base32 characters = 160 bits (RFC 4226 recommends 160-bit secrets). */
    public function newSecret(): string
    {
        return $this->provider->generateSecretKey(32);
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        return preg_match('/^\d{6}$/', $code) === 1 && $this->provider->verify($secret, $code);
    }

    /** Inline SVG of the otpauth:// URL, for the authenticator app to scan. */
    public function qrCodeSvg(AdminUser $admin, string $secret): string
    {
        $url = $this->provider->qrCodeUrl(config('oppam.site.name').' Admin', $admin->email, $secret);

        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(192, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(45, 45, 45))),
            new SvgImageBackEnd,
        )))->writeString($url);

        return trim(substr($svg, (int) strpos($svg, "\n") + 1));
    }

    /**
     * @return array{plain: list<string>, hashes: list<string>}
     */
    public function newRecoveryCodes(): array
    {
        $plain = [];

        for ($i = 0; $i < config('oppam.admin.recovery_codes'); $i++) {
            $plain[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        return ['plain' => $plain, 'hashes' => array_map(fn (string $code): string => Hash::make($code), $plain)];
    }

    /** Burn a recovery code if it matches one of the admin's unused codes. */
    public function consumeRecoveryCode(AdminUser $admin, string $code): bool
    {
        $code = Str::lower(trim($code));

        // Under a row lock, so two parallel requests can't both spend the same code.
        return DB::transaction(function () use ($admin, $code): bool {
            /** @var AdminUser $locked */
            $locked = AdminUser::query()->lockForUpdate()->findOrFail($admin->id);
            $hashes = $locked->two_factor_recovery_codes ?? [];

            foreach ($hashes as $index => $hash) {
                if (Hash::check($code, $hash)) {
                    unset($hashes[$index]);
                    $locked->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

                    return true;
                }
            }

            return false;
        });
    }
}
