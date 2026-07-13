<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Common\Util;

use BackedEnum;
use InvalidArgumentException;
use Stringable;
use UnitEnum;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function preg_replace;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * Encodes and decodes PostgreSQL's one-dimensional array text format.
 */
final class PgsqlArrayCodec
{
    /**
     * @param array<mixed> $values
     *
     * @throws \InvalidArgumentException
     *
     * @return string
     */
    public static function encode(array $values): string
    {
        $encoded = [];
        foreach ($values as $value) {
            if ($value === null) {
                $encoded[] = 'NULL';

                continue;
            }

            if ($value instanceof BackedEnum) {
                $value = $value->value;
            } elseif ($value instanceof UnitEnum) {
                $value = $value->name;
            } elseif ($value instanceof Stringable) {
                $value = (string)$value;
            }

            if (is_array($value)) {
                throw new InvalidArgumentException('NATIVE_ARRAY supports one-dimensional arrays only.');
            }
            if (is_object($value)) {
                throw new InvalidArgumentException(sprintf('Cannot encode object of type %s as a PostgreSQL array element.', $value::class));
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException(sprintf('Cannot encode value of type %s as a PostgreSQL array element.', get_debug_type($value)));
            }

            $encoded[] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$value) . '"';
        }

        return '{' . implode(',', $encoded) . '}';
    }

    /**
     * @throws \InvalidArgumentException
     *
     * @return array<mixed>|null
     */
    public static function decode(?string $value, ?string $elementType = null): ?array
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value !== '' && $value[0] === '[') {
            $equalsPosition = strpos($value, '=');
            if ($equalsPosition === false) {
                throw new InvalidArgumentException(sprintf('Malformed PostgreSQL array value: %s', $value));
            }
            $value = substr($value, $equalsPosition + 1);
        }

        if (strlen($value) < 2 || $value[0] !== '{' || $value[strlen($value) - 1] !== '}') {
            throw new InvalidArgumentException(sprintf('Malformed PostgreSQL array value: %s', $value));
        }

        $contents = substr($value, 1, -1);
        if ($contents === '') {
            return [];
        }

        $values = [];
        $current = '';
        $quoted = false;
        $wasQuoted = false;
        $escaped = false;
        $length = strlen($contents);

        for ($index = 0; $index < $length; $index++) {
            $character = $contents[$index];
            if ($escaped) {
                $current .= $character;
                $escaped = false;

                continue;
            }
            if ($character === '\\') {
                $escaped = true;

                continue;
            }
            if ($character === '"') {
                $quoted = !$quoted;
                $wasQuoted = true;

                continue;
            }
            if ($character === '{' || $character === '}') {
                throw new InvalidArgumentException('NATIVE_ARRAY supports one-dimensional arrays only.');
            }
            if ($character === ',' && !$quoted) {
                $values[] = self::decodeElement($current, $wasQuoted, $elementType);
                $current = '';
                $wasQuoted = false;

                continue;
            }

            $current .= $character;
        }

        if ($quoted || $escaped) {
            throw new InvalidArgumentException(sprintf('Malformed PostgreSQL array value: %s', $value));
        }

        $values[] = self::decodeElement($current, $wasQuoted, $elementType);

        return $values;
    }

    /**
     * @return mixed
     */
    private static function decodeElement(string $value, bool $quoted, ?string $elementType)
    {
        if (!$quoted) {
            $value = trim($value);
            if (strtoupper($value) === 'NULL') {
                return null;
            }
        }

        $elementType = strtolower(trim((string)$elementType));
        $elementType = preg_replace('/\s*\([^)]+\)$/', '', $elementType) ?? $elementType;
        if (str_ends_with($elementType, '[]')) {
            $elementType = substr($elementType, 0, -2);
        }

        return match ($elementType) {
            'bool', 'boolean' => in_array(strtolower($value), ['t', 'true', '1', 'yes', 'on'], true),
            'int', 'integer', 'int2', 'int4', 'smallint', 'serial', 'smallserial' => (int)$value,
            'real', 'float', 'float4', 'float8', 'double', 'double precision' => (float)$value,
            default => $value,
        };
    }
}
