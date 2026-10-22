<?php

declare(strict_types=1);

namespace App\Domain\Verification;

use App\Enums\VerificationDocType;

/**
 * A government ID number as the member typed it (M09). Normalised (upper case, spaces and hyphens
 * removed) and checked against the document type's shape — Aadhaar also against its Verhoeff check
 * digit. What is kept is only `last4` and `hash()`: an HMAC keyed by the app key, so the same
 * document on two profiles can be spotted (A05 duplicate flag) without ever storing the number.
 */
final readonly class DocumentNumber
{
    private function __construct(
        public VerificationDocType $type,
        private string $normalised,
    ) {}

    /** null when the number doesn't have this document's shape. */
    public static function parse(VerificationDocType $type, string $raw): ?self
    {
        $normalised = strtoupper((string) preg_replace('/[\s\-]+/u', '', $raw));

        if (preg_match($type->pattern(), $normalised) !== 1) {
            return null;
        }

        if ($type === VerificationDocType::Aadhaar && ! self::verhoeffValid($normalised)) {
            return null;
        }

        return new self($type, $normalised);
    }

    public function last4(): string
    {
        return substr($this->normalised, -4);
    }

    public function masked(): string
    {
        return $this->type->masked($this->last4());
    }

    public function hash(): string
    {
        return hash_hmac('sha256', $this->type->value.'|'.$this->normalised, 'verification-number|'.(string) config('app.key'));
    }

    /** Aadhaar's last digit is a Verhoeff check digit — it catches every single-digit typo. */
    private static function verhoeffValid(string $digits): bool
    {
        $d = [
            [0, 1, 2, 3, 4, 5, 6, 7, 8, 9], [1, 2, 3, 4, 0, 6, 7, 8, 9, 5], [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
            [3, 4, 0, 1, 2, 8, 9, 5, 6, 7], [4, 0, 1, 2, 3, 9, 5, 6, 7, 8], [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
            [6, 5, 9, 8, 7, 1, 0, 4, 3, 2], [7, 6, 5, 9, 8, 2, 1, 0, 4, 3], [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
            [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
        ];
        $p = [
            [0, 1, 2, 3, 4, 5, 6, 7, 8, 9], [1, 5, 7, 6, 2, 8, 3, 0, 9, 4], [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
            [8, 9, 1, 6, 0, 4, 3, 5, 2, 7], [9, 4, 5, 3, 1, 2, 6, 8, 7, 0], [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
            [2, 7, 9, 3, 8, 0, 6, 4, 1, 5], [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
        ];

        $check = 0;
        foreach (array_reverse(str_split($digits)) as $i => $digit) {
            $check = $d[$check][$p[$i % 8][(int) $digit]];
        }

        return $check === 0;
    }
}
