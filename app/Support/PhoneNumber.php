<?php

declare(strict_types=1);

namespace App\Support;

final class PhoneNumber
{
    private const LOCAL_LENGTH = 9;

    private const MAX_LENGTH = 15;

    private const COUNTRY_CODE = '998';

    public static function normalize(?string $phone): ?string
    {
        $first = preg_split('/[,;\/]|\s+or\s+|\s+или\s+/iu', (string) $phone)[0] ?? '';
        $digits = preg_replace('/\D+/', '', $first) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === self::LOCAL_LENGTH + 1 && str_starts_with($digits, '8')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === self::LOCAL_LENGTH) {
            $digits = self::COUNTRY_CODE.$digits;
        }

        return strlen($digits) >= 7 && strlen($digits) <= self::MAX_LENGTH ? $digits : null;
    }
}
