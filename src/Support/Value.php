<?php

declare(strict_types=1);

namespace MatiasYocca\MercadoPago\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Normalizes loosely typed values from Mercado Pago responses.
 *
 * @internal
 */
final class Value
{
    public static function string(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    public static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }

    public static function float(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    public static function date(mixed $value): ?CarbonImmutable
    {
        $value = self::string($value);

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
