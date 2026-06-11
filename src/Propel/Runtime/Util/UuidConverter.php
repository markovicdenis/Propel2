<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\Util;

use DateTimeInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

use function is_string;
use function is_array;

/**
 * Helps to manually convert UUIDs to byte types
 */
class UuidConverter
{
    /**
     * @param mixed $value
     *
     * @return \Symfony\Component\Uid\UuidV7|null
     */
    public static function normalizeUid($value): ?UuidV7
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof UuidV7) {
            return $value;
        }

        if ($value instanceof Uuid) {
            return UuidV7::fromString($value->toRfc4122());
        }

        return UuidV7::fromString((string)$value);
    }

    /**
     * @param string $value
     *
     * @return \Symfony\Component\Uid\UuidV7
     */
    public static function stringToUid(string $value): UuidV7
    {
        return UuidV7::fromString($value);
    }

    /**
     * @param string $value
     *
     * @return \Symfony\Component\Uid\UuidV7
     */
    public static function binToUid(string $value): UuidV7
    {
        return UuidV7::fromBinary($value);
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    public static function uidToString($value): string
    {
        $uid = self::normalizeUid($value);

        return $uid ? $uid->toRfc4122() : '';
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    public static function uidToBin($value): string
    {
        $uid = self::normalizeUid($value);

        return $uid ? $uid->toBinary() : '';
    }

    /**
     * @param array|\Symfony\Component\Uid\Uuid|string|null $value
     *
     * @return array|string|null
     */
    public static function uidToStringRecursive($value)
    {
        if (!$value) {
            return $value;
        }
        if (!is_array($value)) {
            return self::uidToString($value);
        }

        return array_map(fn ($item) => self::uidToStringRecursive($item), $value);
    }

    /**
     * @param array|\Symfony\Component\Uid\Uuid|string|null $value
     *
     * @return array|string|null
     */
    public static function uidToBinRecursive($value)
    {
        if (!$value) {
            return $value;
        }
        if (!is_array($value)) {
            return self::uidToBin($value);
        }

        return array_map(fn ($item) => self::uidToBinRecursive($item), $value);
    }

    /**
     * Transforms a UUID string to a binary string.
     *
     * @param string $uuid
     * @param bool $swapFlag Swap first four bytes for better indexing of version-1 UUIDs (@link https://dev.mysql.com/doc/refman/8.0/en/miscellaneous-functions.html#function_uuid-to-bin)
     *
     * @return string
     */
    public static function uuidToBin(string $uuid, bool $swapFlag = true): string
    {
        if (!$swapFlag) {
            return Uuid::fromString($uuid)->toBinary();
        }

        $rawHex = preg_replace(
            '/([^-]+)-([^-]+)-([^-]+)-([^-]+)-(.*)/',
            '$3$2$1$4$5',
            $uuid,
        );

        return hex2bin((string)$rawHex) ?: '';
    }

    /**
     * Transforms a binary string to a UUID string.
     *
     * @param string $bin
     * @param bool $swapFlag Assume bytes were swapped (@link https://dev.mysql.com/doc/refman/8.0/en/miscellaneous-functions.html#function_bin-to-uuid)
     *
     * @return string
     */
    public static function binToUuid(string $bin, bool $swapFlag = true): string
    {
        if (!$swapFlag) {
            return Uuid::fromBinary($bin)->toRfc4122();
        }

        $rawHex = bin2hex($bin);
        $recombineFormat = '$3$4-$2-$1-$5-$6';

        return (string)preg_replace(
            '/(\w{4})(\w{4})(\w{4})(\w{4})(\w{4})(\w{12})/',
            $recombineFormat,
            $rawHex,
        );
    }

    /**
     * @param array|string|null $uuid
     * @param bool $swapFlag
     *
     * @return array|string|null
     */
    public static function uuidToBinRecursive($uuid, bool $swapFlag = true)
    {
        if (!$uuid) {
            return $uuid;
        }
        if (is_string($uuid)) {
            return self::uuidToBin($uuid, $swapFlag);
        }

        return array_map(fn ($uuidItem) => self::uuidToBinRecursive($uuidItem, $swapFlag), $uuid);
    }

    /**
     * @param array|string|null $bin
     * @param bool $swapFlag
     *
     * @return array|string|null
     */
    public static function binToUuidRecursive($bin, bool $swapFlag = true)
    {
        if (!$bin) {
            return $bin;
        }
        if (is_string($bin)) {
            return self::binToUuid($bin, $swapFlag);
        }

        return array_map(fn ($binItem) => self::binToUuidRecursive($binItem, $swapFlag), $bin);
    }

    /**
     * Generates an RFC 4122 UUIDv7 string.
     *
     * @return string
     */
    public static function generateV7(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    /**
     * @return \Symfony\Component\Uid\UuidV7
     */
    public static function generateV7Uid(?DateTimeInterface $time = null): UuidV7
    {
        return UuidV7::fromString(UuidV7::generate($time));
    }
}
