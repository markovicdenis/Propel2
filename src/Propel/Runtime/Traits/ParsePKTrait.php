<?php

namespace Propel\Runtime\Traits;

use function is_callable;
use function is_scalar;
use function is_string;

trait ParsePKTrait
{
    /**
     * Custom function to parse primary key hash
     */
    public static function parsePKHash(mixed $key): ?string
    {
        if (is_string($key)) {
            return $key;
        }
        if ($key === null || is_scalar($key) || is_callable([$key, '__toString'])) {
            return (string) $key;
        }

        return null;
    }
}
