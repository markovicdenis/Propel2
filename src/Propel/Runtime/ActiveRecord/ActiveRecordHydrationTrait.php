<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\ActiveRecord;

use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Map\TableMap;
use ReflectionClass;

use function array_key_exists;
use function constant;
use function count;
use function defined;
use function is_array;
use function sprintf;

trait ActiveRecordHydrationTrait
{
    /** True when this object was hydrated from a column projection. */
    private bool $partialObject = false;

    /** @var array<string, true> PHP column names present in the projection. */
    private array $loadedColumns = [];

    /**
     * Hydrate only selected columns while retaining the generated model's
     * normal enum, UUID, date and database-type conversions.
     *
     * Generated hydrate() methods expect a complete row in table-column order.
     * This fills unselected positions with null then delegates to hydrate(),
     * keeping the conversion logic in one generated implementation.
     *
     * @param array<int|string, mixed> $row
     * @param list<string> $columnNames PHP column names, in SELECT order
     */
    public function hydrateProjection(array $row, array $columnNames, string $indexType = TableMap::TYPE_NUM): void
    {
        /** @var array<class-string, array{positions: array<string, int>, lazyColumns: array<string, true>, hydrateColumnCount: int}> $plans */
        static $plans = [];
        $class = static::class;
        $tableMapClass = static::TABLE_MAP;
        if (!isset($plans[$class])) {
            $tableMap = $tableMapClass::getTableMap();
            $allColumns = $tableMapClass::getFieldNames(TableMap::TYPE_PHPNAME);

            $hydrateColumnNamesConstant = $tableMapClass . '::HYDRATE_COLUMN_NAMES';
            $hydrateColumns = defined($hydrateColumnNamesConstant)
                ? constant($hydrateColumnNamesConstant)
                : null;

            if (is_array($hydrateColumns)) {
                /** @var list<string> $hydrateColumns */
                $positions = array_flip($hydrateColumns);
                $lazyColumns = array_fill_keys(array_diff($allColumns, $hydrateColumns), true);
                $hydratePosition = count($hydrateColumns);
            } else {
                // Compatibility fallback for models generated before explicit
                // hydration metadata was added to generated table maps.
                $reflection = new ReflectionClass($this);
                $positions = [];
                $lazyColumns = [];
                $hydratePosition = 0;
                foreach ($allColumns as $columnName) {
                    $column = $tableMap->getColumnByPhpName($columnName);
                    $propertyName = strtolower($column->getName()) . '_isLoaded';
                    if ($reflection->hasProperty($propertyName)) {
                        $lazyColumns[$columnName] = true;
                        continue;
                    }

                    $positions[$columnName] = $hydratePosition++;
                }
            }

            $plans[$class] = [
                'positions' => $positions,
                'lazyColumns' => $lazyColumns,
                'hydrateColumnCount' => $hydratePosition,
            ];
        }

        $plan = $plans[$class];
        $positions = $plan['positions'];
        $lazyColumns = $plan['lazyColumns'];
        $fullRow = array_fill(0, $plan['hydrateColumnCount'], null);
        $loadedColumns = [];

        foreach ($columnNames as $position => $columnName) {
            if (isset($lazyColumns[$columnName])) {
                throw new PropelException(sprintf(
                    'Lazy-loaded column "%s" cannot be used in a projection for %s.',
                    $columnName,
                    static::class
                ));
            }
            if (!array_key_exists($columnName, $positions)) {
                throw new PropelException(sprintf('Unknown projection column "%s" for %s.', $columnName, static::class));
            }

            $rowKey = $indexType === TableMap::TYPE_NUM
                ? $position
                : $tableMapClass::translateFieldName($columnName, TableMap::TYPE_PHPNAME, $indexType);
            if (!array_key_exists($rowKey, $row)) {
                throw new PropelException(sprintf('Projection result is missing column "%s" for %s.', $columnName, static::class));
            }

            $fullRow[$positions[$columnName]] = $row[$rowKey];
            $loadedColumns[$columnName] = true;
        }

        $this->hydrate($fullRow);
        $this->partialObject = true;
        $this->loadedColumns = $loadedColumns;
    }

    public function isPartial(): bool
    {
        return $this->partialObject;
    }

    /** @return list<string> */
    public function getLoadedColumns(): array
    {
        return array_keys($this->loadedColumns);
    }

    public function isColumnLoaded(string $columnName): bool
    {
        return !$this->partialObject || isset($this->loadedColumns[$columnName]);
    }

    /**
     * Generated scalar getters call this before reading their backing property.
     * A missing value is never indistinguishable from a stored NULL.
     */
    protected function assertColumnLoaded(string $columnName): void
    {
        if (!$this->isColumnLoaded($columnName)) {
            throw new PropelException(sprintf(
                'Column "%s" was not selected for partial %s. Reload the object before accessing it.',
                $columnName,
                static::class
            ));
        }
    }

    /** Restores full-object semantics after a generated hydrate() call. */
    protected function resetProjectionState(): void
    {
        $this->partialObject = false;
        $this->loadedColumns = [];
    }

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
