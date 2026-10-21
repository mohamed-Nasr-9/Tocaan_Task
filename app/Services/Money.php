<?php

namespace App\Services;

use InvalidArgumentException;

class Money
{
    public function toMinorUnits(string|int|float $amount): int
    {
        $value = (string) $amount;

        if (preg_match('/^(?:0|[1-9]\d{0,5})(?:\.\d{1,2})?$/', $value) !== 1) {
            throw new InvalidArgumentException('Amount must be a positive decimal with at most two fractional digits.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        if ($minor < 1 || $minor > 99_999_999) {
            throw new InvalidArgumentException('Amount must be between 0.01 and 999999.99.');
        }

        return $minor;
    }

    public static function format(int $minorUnits): string
    {
        return intdiv($minorUnits, 100).'.'.str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }
}
