<?php

namespace App\Domain\Customer;

/**
 * Phone numbers are the identity of a customer, so they are stored in one normalised form: digits only, with a leading "+" when the
 * customer typed one (or "00"). "+49 170 1234567", "+49-170-1234567" and "0049 170 1234567" are the same customer.
 * A number typed without the country code ("0170 1234567") is a different number from "+49 170 1234567": the shop cannot know the country.
 */
class Phone
{
    /** Shape accepted from a form: optional +, then digits with spaces, dashes and brackets ("(555) 010-1234"); 7 to 15 digits in total (E.164 allows 15). */
    public const PATTERN = '/^\+?[0-9(][0-9 ()\-]{5,22}$/';

    public static function normalise(string $phone): string
    {
        $phone = trim($phone);
        $plus = str_starts_with($phone, '+') || str_starts_with($phone, '00');
        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($phone, '00')) {
            $digits = substr($digits, 2);
        }

        return ($plus ? '+' : '').$digits;
    }

    public static function isValid(string $phone): bool
    {
        if (! preg_match(self::PATTERN, trim($phone))) {
            return false;
        }
        $digits = strlen(preg_replace('/\D+/', '', self::normalise($phone)));

        return $digits >= 7 && $digits <= 15;
    }
}
