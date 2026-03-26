<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Util;

use function implode;
use function is_array;
use function str_repeat;
use function var_export;

class PhpValueExporter
{
    /**
     * @param mixed $value
     * @param int $indentLevel
     *
     * @return string
     */
    public static function export($value, int $indentLevel = 0): string
    {
        if (!is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $items = [];
        $indent = str_repeat('    ', $indentLevel);
        $nestedIndent = str_repeat('    ', $indentLevel + 1);
        foreach ($value as $key => $arrayValue) {
            $items[] = $nestedIndent . var_export($key, true) . ' => ' . self::export($arrayValue, $indentLevel + 1);
        }

        return "[\n" . implode(",\n", $items) . ",\n" . $indent . ']';
    }
}