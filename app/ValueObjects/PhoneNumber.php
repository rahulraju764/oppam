<?php

declare(strict_types=1);

namespace App\ValueObjects;

use InvalidArgumentException;

/**
 * A mobile number in E.164 (+919876543210) — the member login id (PRD §7.2, R-M01-1). Only the
 * countries offered on the registration forms are accepted; India is checked strictly (10 digits
 * starting 6–9), the NRI countries by their national mobile length. No external library: the
 * forms offer a fixed country list, so a length rule per country is enough.
 */
final readonly class PhoneNumber
{
    /** Calling code => [label, national number pattern]. The first entry is the form default. */
    private const COUNTRIES = [
        '91' => ['India', '/^[6-9]\d{9}$/'],
        '971' => ['UAE', '/^5\d{8}$/'],
        '966' => ['Saudi Arabia', '/^5\d{8}$/'],
        '44' => ['UK', '/^7\d{9}$/'],
        '1' => ['USA', '/^[2-9]\d{9}$/'],
    ];

    public const DEFAULT_COUNTRY = '91';

    private function __construct(
        public string $countryCode,
        public string $nationalNumber,
    ) {}

    /** @throws InvalidArgumentException when the number is not a valid mobile for that country */
    public static function fromParts(string $countryCode, string $nationalNumber): self
    {
        $countryCode = ltrim(trim($countryCode), '+');
        $digits = self::digits($nationalNumber);

        if (! isset(self::COUNTRIES[$countryCode])) {
            throw new InvalidArgumentException('Unsupported country code.');
        }

        // People often type the trunk prefix (09876 543210) — drop one leading zero.
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        // …or repeat the country code (91 98765 43210) after choosing it in the select.
        if (strlen($digits) > 10 && str_starts_with($digits, $countryCode)) {
            $digits = substr($digits, strlen($countryCode));
        }

        if (preg_match(self::COUNTRIES[$countryCode][1], $digits) !== 1) {
            throw new InvalidArgumentException('Not a valid mobile number.');
        }

        return new self($countryCode, $digits);
    }

    public static function tryFromParts(string $countryCode, string $nationalNumber): ?self
    {
        try {
            return self::fromParts($countryCode, $nationalNumber);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @throws InvalidArgumentException */
    public static function fromE164(string $e164): self
    {
        if (preg_match('/^\+(\d{8,15})$/', $e164, $match) !== 1) {
            throw new InvalidArgumentException('Not an E.164 number.');
        }

        foreach (array_keys(self::COUNTRIES) as $code) {
            $code = (string) $code;

            if (str_starts_with($match[1], $code)) {
                return self::fromParts($code, substr($match[1], strlen($code)));
            }
        }

        throw new InvalidArgumentException('Unsupported country code.');
    }

    /**
     * Free-text input from a single field (the login "Mobile / Email / Profile ID" box): a leading
     * "+" means a full international number, otherwise the default country is assumed.
     */
    public static function tryParse(string $input, string $defaultCountry = self::DEFAULT_COUNTRY): ?self
    {
        $input = trim($input);

        try {
            return str_starts_with($input, '+')
                ? self::fromE164('+'.self::digits($input))
                : self::fromParts($defaultCountry, $input);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Calling code => "India [+91]", for the country select. PHP stores numeric array keys as
     * ints, so the keys are ints here; validate input against countryCodes().
     *
     * @return array<int, string>
     */
    public static function countryOptions(): array
    {
        $options = [];

        foreach (self::COUNTRIES as $code => $country) {
            $options[(int) $code] = __($country[0]).' [+'.$code.']';
        }

        return $options;
    }

    /** @return list<string> the supported calling codes, for Rule::in */
    public static function countryCodes(): array
    {
        return array_map(strval(...), array_keys(self::COUNTRIES));
    }

    public function e164(): string
    {
        return '+'.$this->countryCode.$this->nationalNumber;
    }

    /** "+91 98•••••210" — safe to show on screen and to put in logs. */
    public function masked(): string
    {
        $n = $this->nationalNumber;

        return '+'.$this->countryCode.' '.substr($n, 0, 2).str_repeat('•', strlen($n) - 5).substr($n, -3);
    }

    public function equals(self $other): bool
    {
        return $this->e164() === $other->e164();
    }

    public function __toString(): string
    {
        return $this->e164();
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }
}
