<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\Map;

use Propel\Runtime\Exception\PropelException;

trait TableMapTrait
{
    /**
     * Lazily built reverse field-name lookup tables.
     *
     * @var array<string, array<string|int, int>>
     */
    protected static array $fieldKeysCache = [];

    /**
     * Returns an array of field names.
     *
     * @param string $type The type of fieldnames to return:
     *                               One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                               TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return array A list of field names
     */
    public static function getFieldNames(string $type = TableMap::TYPE_PHPNAME): array
    {
        if ($type === TableMap::TYPE_NUM) {
            $count = count(static::$fieldNames[TableMap::TYPE_FIELDNAME]);

            return $count ? range(0, $count - 1) : [];
        }

        if (!array_key_exists($type, static::$fieldNames)) {
            throw new PropelException('Method getFieldNames() expects the parameter \$type to be one of the class constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME, TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM. ' . $type . ' was given.');
        }

        return static::$fieldNames[$type];
    }

    /**
     * Translates a fieldname to another type
     *
     * @param string $name field name
     * @param string $fromType One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                                   TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM
     * @param string $toType One of the class type constants
     *
     * @throws \Propel\Runtime\Exception\PropelException - if the specified name could not be found in the fieldname mappings.
     *
     * @return string|int translated name of the field.
     */
    public static function translateFieldName(string $name, string $fromType, string $toType): string|int
    {
        $toNames = static::getFieldNames($toType);
        $key = static::getFieldKeys($fromType)[$name] ?? null;
        if ($key === null) {
            throw new PropelException("'$name' could not be found in the field names of type '$fromType'. These are: " . print_r(static::getFieldKeys($fromType), true));
        }

        return $toNames[$key];
    }

    /**
     * Gets the generated object property name for a field identifier.
     *
     * @param string|int $name One of the field names in the supported TableMap index types.
     * @param string $type One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME,
     *                     TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return string
     */
    public static function getPropertyName(string|int $name, string $type = TableMap::TYPE_COLNAME): string
    {
        if ($type === TableMap::TYPE_NUM) {
            $fieldName = static::getFieldNames(TableMap::TYPE_FIELDNAME)[$name] ?? null;
            if ($fieldName === null) {
                throw new PropelException("'$name' could not be found in the field names of type '$type'.");
            }

            return strtolower($fieldName);
        }

        return strtolower((string)static::translateFieldName((string)$name, $type, TableMap::TYPE_FIELDNAME));
    }

    /**
     * @param array $row
     * @param string $fromType
     * @param string $toType
     *
     * @return array
     */
    public static function translateFieldNames(array $row, string $fromType, string $toType): array
    {
        $toNames = static::getFieldNames($toType);
        $fieldKeys = static::getFieldKeys($fromType);
        $newRow = [];
        foreach ($row as $name => $field) {
            if (isset($fieldKeys[$name])) {
                $newRow[$toNames[$fieldKeys[$name]]] = $field;
            } else {
                $newRow[$name] = $field;
            }
        }

        return $newRow;
    }

    /**
     * @param string $type
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return array<string|int, int>
     */
    protected static function getFieldKeys(string $type): array
    {
        if ($type === TableMap::TYPE_NUM) {
            return static::$fieldKeysCache[$type] ??= static::getFieldNames($type);
        }

        if (!array_key_exists($type, static::$fieldNames)) {
            throw new PropelException('Method getFieldNames() expects the parameter \$type to be one of the class constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME, TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM. ' . $type . ' was given.');
        }

        return static::$fieldKeysCache[$type] ??= array_flip(static::$fieldNames[$type]);
    }

    /**
     * Convenience method which changes table.column to alias.column.
     *
     * Using this method you can maintain SQL abstraction while using column aliases.
     * <code>
     *        $c->addAlias("alias1", TableTableMap::TABLE_NAME);
     *        $c->addJoin(TableTableMap::alias("alias1", TableTableMap::PRIMARY_KEY_COLUMN), TableTableMap::PRIMARY_KEY_COLUMN);
     * </code>
     *
     * @param string $alias The alias for the current table.
     * @param string $column The column name for current table. (i.e. BookTableMap::COLUMN_NAME).
     *
     * @return string
     */
    public static function alias(string $alias, string $column): string
    {
        return str_replace(static::TABLE_NAME . '.', $alias . '.', $column);
    }
}
