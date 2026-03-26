<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\ActiveRecord;

use Propel\Runtime\ActiveRecord\ActiveRecordCommonTrait;
use Propel\Runtime\ActiveRecord\ActiveRecordHydrationTrait;
use Propel\Runtime\ActiveRecord\ActiveRecordInterface;
use Propel\Runtime\Map\TableMap;
use Propel\Runtime\Map\TableMapTrait;

class TestableActiveRecord implements ActiveRecordInterface
{
    use ActiveRecordCommonTrait {
        defaultConvertValueToPhpType as protected traitDefaultConvertValueToPhpType;
        defaultArePhpTypeValuesEqual as protected traitDefaultArePhpTypeValuesEqual;
    }
    use ActiveRecordHydrationTrait;

    public const TABLE_MAP = TestableActiveRecordTableMap::class;

    protected $modifiedColumns = [];

    protected $new = true;

    protected $deleted = false;

    public $virtualColumns = [];

    public $primaryKey;

    public $temporalValue;

    public function isPrimaryKeyNull(): bool
    {
        return $this->primaryKey === null;
    }

    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    public function toArray(string $keyType = 'phpName', bool $includeLazyLoadColumns = true, array $alreadyDumpedObjects = []): array
    {
        return ['foo' => 'bar'];
    }

    public function clearAllReferences(): void
    {
    }

    public function resolveFromTestRow(
        array $row,
        int $position,
        int $startcol = 0,
        string $indexType = TableMap::TYPE_NUM,
        ?callable $transformer = null
    ) {
        return $this->resolveFromRow($row, $position, $startcol, $indexType, $transformer);
    }

    public function defaultConvertValueToPhpTypeTestValue($value, string $phpType)
    {
        return $this->traitDefaultConvertValueToPhpType($value, $phpType);
    }

    public function defaultArePhpTypeValuesEqualTestValue($currentValue, $newValue, string $phpType): bool
    {
        return $this->traitDefaultArePhpTypeValuesEqual($currentValue, $newValue, $phpType);
    }

    public function hashCodeFromTestValue($value): int
    {
        return $this->hashCodeFromValue($value);
    }

    public function validatePrimaryKeyTestValue($primaryKey, $unsetValue): bool
    {
        return $this->validatePrimaryKey($primaryKey, $unsetValue);
    }

    public function validatePrimaryKeysTestValue(array $primaryKeys, array $unsetValues): bool
    {
        return $this->validatePrimaryKeys($primaryKeys, $unsetValues);
    }
}

class ComparableValueObject
{
    public function __construct(protected string $value)
    {
    }

    public function equals($other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }
}

class TestableActiveRecordTableMap extends TableMap
{
    use TableMapTrait;

    public const TABLE_NAME = 'testable_active_record';
    public const COL_CREATED_AT = 'testable_active_record.created_at';

    protected static array $fieldNames = [
        TableMap::TYPE_PHPNAME => ['Id', 'Nick'],
        TableMap::TYPE_CAMELNAME => ['id', 'nick'],
        TableMap::TYPE_COLNAME => ['testable_active_record.id', 'testable_active_record.nick'],
        TableMap::TYPE_FIELDNAME => ['id', 'nick'],
    ];
}
