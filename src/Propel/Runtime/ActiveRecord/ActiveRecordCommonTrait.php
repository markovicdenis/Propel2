<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\ActiveRecord;

use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Map\TableMap;
use Propel\Runtime\Parser\AbstractParser;
use Propel\Runtime\Propel;
use Propel\Runtime\Util\PropelDateTime;

use function array_key_exists;
use function count;
use function crc32;
use function get_class;
use function serialize;
use function sprintf;

trait ActiveRecordCommonTrait
{
    /**
     * Validate a single-column primary key against its generated unset value.
     *
     * @param mixed $primaryKey
     * @param mixed $unsetValue
     *
     * @return bool
     */
    protected function validatePrimaryKey($primaryKey, $unsetValue): bool
    {
        return $primaryKey !== $unsetValue && $primaryKey !== null;
    }

    /**
     * Validate a composite primary key against its generated unset values.
     *
     * @param array $primaryKeys
     * @param array $unsetValues
     *
     * @return bool
     */
    protected function validatePrimaryKeys(array $primaryKeys, array $unsetValues): bool
    {
        if (count($primaryKeys) !== count($unsetValues)) {
            return false;
        }

        foreach ($unsetValues as $index => $unsetValue) {
            $primaryKey = $primaryKeys[$index] ?? null;
            if (!$this->validatePrimaryKey($primaryKey, $unsetValue)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $value
     *
     * @return int
     */
    protected function hashCodeFromValue($value): int
    {
        return crc32(serialize($value));
    }

    /**
     * Cast a value to the configured PHP scalar type while preserving nulls.
     *
     * @param mixed $value
     * @param string $type
     * @param bool $isNullable
     *
     * @return mixed
     */
    protected function castTo($value, string $type, bool $isNullable)
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int', 'integer' => (int)$value,
            'float', 'double' => (float)$value,
            'string' => (string)$value,
            'bool', 'boolean' => (bool)$value,
            default => $value,
        };
    }

    /**
     * Normalize and assign a temporal value while tracking modifications.
     *
     * @param mixed $currentValue
     * @param mixed $value
     * @param string $dateTimeClass
     * @param string $columnConstant
     * @param string $comparisonFormat
     * @param string|null $defaultValue
     * @param string|null $defaultValueFormat
     *
     * @return void
     */
    protected function setTemporalValue(&$currentValue, $value, string $dateTimeClass, string $columnConstant, string $comparisonFormat, ?string $defaultValue = null, ?string $defaultValueFormat = null): void
    {
        $dt = PropelDateTime::newInstance($value, null, $dateTimeClass);

        if ($currentValue === null && $dt === null) {
            return;
        }

        if ($defaultValue !== null) {
            $format = $defaultValueFormat ?? $comparisonFormat;
            $hasChanged = ($dt != $currentValue) || ($dt?->format($format) === $defaultValue);
        } else {
            $hasChanged = $currentValue === null
                || $dt === null
                || $dt->format($comparisonFormat) !== $currentValue->format($comparisonFormat);
        }

        if (!$hasChanged) {
            return;
        }

        $currentValue = $dt === null ? null : clone $dt;
        $this->modifiedColumns[$columnConstant] = true;
    }

    /**
     * Returns whether the object has been modified.
     *
     * @return bool
     */
    public function isModified(): bool
    {
        return (bool)$this->modifiedColumns;
    }

    /**
     * Has specified column been modified?
     *
     * @param string $col Column fully qualified name (TableMap::TYPE_COLNAME).
     *
     * @return bool
     */
    public function isColumnModified(string $col): bool
    {
        return $this->modifiedColumns && isset($this->modifiedColumns[$col]);
    }

    /**
     * Get the columns that have been modified in this object.
     *
     * @return array
     */
    public function getModifiedColumns(): array
    {
        return $this->modifiedColumns ? array_keys($this->modifiedColumns) : [];
    }

    /**
     * Returns whether the object has ever been saved.
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->new;
    }

    /**
     * Setter for the isNew attribute.
     *
     * @param bool $b
     *
     * @return void
     */
    public function setNew(bool $b): void
    {
        $this->new = $b;
    }

    /**
     * Whether this object has been deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * Specify whether this object has been deleted.
     *
     * @param bool $b
     *
     * @return void
     */
    public function setDeleted(bool $b): void
    {
        $this->deleted = $b;
    }

    /**
     * Sets the modified state for the object to be false.
     *
     * @param ?string $col If supplied, only the specified column is reset.
     *
     * @return void
     */
    public function resetModified(?string $col = null): void
    {
        if ($col !== null) {
            unset($this->modifiedColumns[$col]);

            return;
        }

        $this->modifiedColumns = [];
    }

    /**
     * Compares this object with another instance of the same type.
     *
     * @param mixed $obj The object to compare to.
     *
     * @return bool
     */
    public function equals($obj): bool
    {
        if (!$obj instanceof static) {
            return false;
        }

        if ($this === $obj) {
            return true;
        }

        if (!$this->getPrimaryKey() || !$obj->getPrimaryKey()) {
            return false;
        }

        return $this->getPrimaryKey() === $obj->getPrimaryKey();
    }

    /**
     * Get the associative array of the virtual columns in this object.
     *
     * @return array
     */
    public function getVirtualColumns(): array
    {
        return $this->virtualColumns;
    }

    /**
     * Checks the existence of a virtual column in this object.
     *
     * @param string $name The virtual column name.
     *
     * @return bool
     */
    public function hasVirtualColumn(string $name): bool
    {
        return array_key_exists($name, $this->virtualColumns);
    }

    /**
     * Get the value of a virtual column in this object.
     *
     * @param string $name The virtual column name.
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return mixed
     */
    public function getVirtualColumn(string $name)
    {
        if (!$this->hasVirtualColumn($name)) {
            throw new PropelException(sprintf('Cannot get value of nonexistent virtual column `%s`.', $name));
        }

        return $this->virtualColumns[$name];
    }

    /**
     * Get the value of a virtual column in this object or return the default if it doesn't exist.
     *
     * @param string $name The virtual column name.
     *
     * @return mixed
     */
    public function tryGetVirtualColumn(string $name, mixed $default = null)
    {
        return $this->hasVirtualColumn($name) ? $this->virtualColumns[$name] : $default;
    }

    /**
     * Set the value of a virtual column in this object.
     *
     * @param string $name The virtual column name.
     * @param mixed $value The value to give to the virtual column.
     *
     * @return $this
     */
    public function setVirtualColumn(string $name, $value)
    {
        $this->virtualColumns[$name] = $value;

        return $this;
    }

    /**
     * Logs a message using Propel::log().
     *
     * @param string $msg
     * @param int $priority One of the Propel::LOG_* logging levels.
     *
     * @return void
     */
    protected function log(string $msg, int $priority = Propel::LOG_INFO): void
    {
        Propel::log(get_class($this) . ': ' . $msg, $priority);
    }

    /**
     * Export the current object properties to a string, using a given parser format.
     *
     * @param \Propel\Runtime\Parser\AbstractParser|string $parser An AbstractParser instance, or a format name.
     * @param bool $includeLazyLoadColumns Whether to include lazy loaded columns.
     * @param string $keyType One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME, TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *
     * @return string
     */
    public function exportTo($parser, bool $includeLazyLoadColumns = true, string $keyType = TableMap::TYPE_PHPNAME): string
    {
        if (!$parser instanceof AbstractParser) {
            $parser = AbstractParser::getParser($parser);
        }

        return $parser->fromArray($this->toArray($keyType, $includeLazyLoadColumns, []));
    }
}
