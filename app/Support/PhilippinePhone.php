<?php

namespace App\Support;

/** Philippine mobile numbers: 0917 123 4567, 917 123 4567 and +63 917 123 4567 are the same number. */
class PhilippinePhone
{
    /** The number as +639XXXXXXXXX, or null when it isn't a Philippine mobile number. */
    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        $local = match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '639') => substr($digits, 2),
            strlen($digits) === 11 && str_starts_with($digits, '09') => substr($digits, 1),
            strlen($digits) === 10 && str_starts_with($digits, '9') => $digits,
            default => null,
        };

        return $local ? '+63'.$local : null;
    }

    /** +639171234567 → +63 917 123 4567 */
    public static function format(?string $phone): string
    {
        $normalized = self::normalize($phone);
        if (! $normalized) {
            return (string) $phone;
        }
        $local = substr($normalized, 3);

        return '+63 '.substr($local, 0, 3).' '.substr($local, 3, 3).' '.substr($local, 6);
    }
}
