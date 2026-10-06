<?php

namespace App\Support;

/**
 * Turns a typed or PBX phone string into the lookup key.
 *
 * The result is digits only, 8 to 15 characters, with the configured
 * country code and a single leading trunk zero removed. A 3-digit
 * extension such as 101 is kept as-is so a lab caller can be saved as a
 * customer. Invalid input returns null and must not be stored or used
 * as a search key.
 */
class PhoneNormalizer
{
    public function __construct(private readonly string $countryCode) {}

    public function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $value = trim($input);

        if ($value === '' || str_contains($value, '@') || preg_match('/\p{L}/u', $value) === 1) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $countryCode = $this->countryCode;

        if (preg_match('/^[0-9]{1,4}$/', $countryCode) === 1 && str_starts_with($digits, $countryCode)) {
            $national = substr($digits, strlen($countryCode));

            if (strlen($national) >= 8) {
                $digits = $national;
            }
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        $length = strlen($digits);

        if ($length === 3) {
            return $digits;
        }

        if ($length < 8 || $length > 15 || preg_match('/^[0-9]+$/', $digits) !== 1) {
            return null;
        }

        return $digits;
    }
}
