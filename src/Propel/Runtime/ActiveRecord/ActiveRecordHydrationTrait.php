<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\ActiveRecord;

use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Map\TableMap;

trait ActiveRecordHydrationTrait
{
    /**
     * Resolves and optionally transforms a hydrated row value.
     *
     * @param array $row
     * @param int $position
     * @param int $startcol
     * @param string $indexType
     * @param callable|null $transformer
     *
     * @return mixed
     */
    protected function resolveFromRow(
        array $row,
        int $position,
        int $startcol = 0,
        string $indexType = TableMap::TYPE_NUM,
        ?callable $transformer = null
    ) {
        $value = $this->getRowValue($row, $position, $startcol, $indexType);

        if ($value === null) {
            return null;
        }

        return $transformer ? $transformer($value) : $value;
    }

    /**
     * Gets a row value by schema position and index type.
     *
     * @param array $row
     * @param int $position
     * @param int $offset
     * @param string $indexType
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return mixed
     */
    protected function getRowValue(array $row, int $position, int $offset = 0, string $indexType = TableMap::TYPE_NUM)
    {
        $key = $indexType === TableMap::TYPE_NUM
            ? $position + $offset
            : ((static::TABLE_MAP)::getFieldNames($indexType)[$position] ?? null);

        if ($key === null) {
            throw new PropelException("'$position' could not be found in the field names of type '$indexType'.");
        }

        return $row[$key];
    }
}
