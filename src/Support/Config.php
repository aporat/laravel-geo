<?php

declare(strict_types=1);

namespace Aporat\Geo\Support;

/**
 * Typed reads of config values, which arrive as mixed.
 */
final class Config
{
    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : $default);
    }

    public static function nullableString(mixed $value): ?string
    {
        $string = self::string($value);

        return $string === '' ? null : $string;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        return is_float($value) || is_int($value) ? (float) $value : (is_numeric($value) ? (float) $value : $default);
    }

    public static function bool(mixed $value, bool $default = false): bool
    {
        return is_bool($value) ? $value : (is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $default);
    }

    /**
     * Only the string-keyed entries of an array, or an empty array.
     *
     * @return array<string, mixed>
     */
    public static function array(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> */
        return array_filter($value, is_string(...), ARRAY_FILTER_USE_KEY);
    }
}
