<?php

declare(strict_types=1);

namespace Litebackup;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

/**
 * Reads the fields of decoded JSON for the generated models. Unknown fields
 * are ignored and a missing required field gets its type's zero value, so a
 * newer daemon's answers keep decoding; enum fields are strings, so values
 * this SDK does not know survive. A field of the wrong type raises
 * UnexpectedValueException, which a call reports as invalid_response.
 */
final class WireModel
{
    /** @var non-empty-string */
    private const TIME_PATTERN = '/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:\d{2})\z/i';

    /**
     * The fields of a decoded JSON object.
     *
     * @return array<string, mixed>
     */
    public static function fields(mixed $value, string $model): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new UnexpectedValueException('expected an object for ' . $model . ', got ' . get_debug_type($value));
        }
        $fields = [];
        foreach ($value as $key => $field) {
            $fields[(string) $key] = $field;
        }
        return $fields;
    }

    /**
     * @param array<string, mixed> $fields
     * @return ($required is true ? string : ?string)
     */
    public static function string(array $fields, string $key, bool $required): ?string
    {
        $value = $fields[$key] ?? null;
        if ($value === null) {
            return $required ? '' : null;
        }
        if (!is_string($value)) {
            throw self::wrongType($key, 'a string', $value);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $fields
     * @return ($required is true ? int : ?int)
     */
    public static function integer(array $fields, string $key, bool $required): ?int
    {
        $value = $fields[$key] ?? null;
        if ($value === null) {
            return $required ? 0 : null;
        }
        if (!is_int($value)) {
            throw self::wrongType($key, 'a whole number', $value);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $fields
     * @return ($required is true ? bool : ?bool)
     */
    public static function boolean(array $fields, string $key, bool $required): ?bool
    {
        $value = $fields[$key] ?? null;
        if ($value === null) {
            return $required ? false : null;
        }
        if (!is_bool($value)) {
            throw self::wrongType($key, 'true or false', $value);
        }
        return $value;
    }

    /**
     * A time, or null when it is missing.
     *
     * @param array<string, mixed> $fields
     */
    public static function time(array $fields, string $key): ?DateTimeImmutable
    {
        $value = $fields[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw self::wrongType($key, 'an RFC 3339 time', $value);
        }
        $parsed = self::parseTime($value);
        if ($parsed === null) {
            throw new UnexpectedValueException($key . ' is not an RFC 3339 time: ' . $value);
        }
        return $parsed;
    }

    /**
     * An RFC 3339 time in its own zone, nanoseconds cut to microseconds, or
     * null when the text is not one.
     */
    public static function parseTime(string $text): ?DateTimeImmutable
    {
        if (preg_match(self::TIME_PATTERN, $text, $parts) !== 1) {
            return null;
        }
        $fraction = substr(str_pad($parts[2], 6, '0'), 0, 6);
        $zone = strtoupper($parts[3]) === 'Z' ? '+00:00' : $parts[3];
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $parts[1] . '.' . $fraction . $zone);
        if ($parsed === false) {
            return null;
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }
        return strtoupper($parts[3]) === 'Z' ? $parsed->setTimezone(new DateTimeZone('UTC')) : $parsed;
    }

    /**
     * A nested model: its zero value when a required one is missing.
     *
     * @template T of object
     * @param array<string, mixed> $fields
     * @param Closure(mixed): T $decode
     * @return ($required is true ? T : ?T)
     */
    public static function model(array $fields, string $key, Closure $decode, bool $required): ?object
    {
        $value = $fields[$key] ?? null;
        if ($value === null) {
            return $required ? $decode([]) : null;
        }
        return $decode($value);
    }

    /**
     * A list of models: empty when a required one is missing.
     *
     * @template T of object
     * @param array<string, mixed> $fields
     * @param Closure(mixed): T $decode
     * @return ($required is true ? list<T> : ?list<T>)
     */
    public static function models(array $fields, string $key, Closure $decode, bool $required): ?array
    {
        $value = $fields[$key] ?? null;
        if ($value === null) {
            return $required ? [] : null;
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw self::wrongType($key, 'a list', $value);
        }
        return array_map($decode, $value);
    }

    /**
     * A list of strings: empty when a required one is missing.
     *
     * @param array<string, mixed> $fields
     * @return ($required is true ? list<string> : ?list<string>)
     */
    public static function strings(array $fields, string $key, bool $required): ?array
    {
        $value = $fields[$key] ?? null;
        if ($value === null) {
            return $required ? [] : null;
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw self::wrongType($key, 'a list', $value);
        }
        return array_map(static function (mixed $element) use ($key): string {
            if (!is_string($element)) {
                throw self::wrongType($key, 'a list of strings', $element);
            }
            return $element;
        }, $value);
    }

    private static function wrongType(string $key, string $expected, mixed $value): UnexpectedValueException
    {
        return new UnexpectedValueException($key . ' must be ' . $expected . ', got ' . get_debug_type($value));
    }
}
