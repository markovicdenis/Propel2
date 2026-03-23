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
    use ActiveRecordCommonTrait;
    use ActiveRecordHydrationTrait;

    public const TABLE_MAP = TestableActiveRecordTableMap::class;

    protected $modifiedColumns = [];

    protected $new = true;

    protected $deleted = false;

    public $virtualColumns = [];

    public $primaryKey;

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
    )
    {
        return $this->resolveFromRow($row, $position, $startcol, $indexType, $transformer);
    }
}

class TestableActiveRecordTableMap extends TableMap
{
    use TableMapTrait;

    public const TABLE_NAME = 'testable_active_record';

    protected static array $fieldNames = [
        TableMap::TYPE_PHPNAME => ['Id', 'Nick'],
        TableMap::TYPE_CAMELNAME => ['id', 'nick'],
        TableMap::TYPE_COLNAME => ['testable_active_record.id', 'testable_active_record.nick'],
        TableMap::TYPE_FIELDNAME => ['id', 'nick'],
        TableMap::TYPE_NUM => [0, 1],
    ];

    protected static array $fieldKeys = [
        TableMap::TYPE_PHPNAME => ['Id' => 0, 'Nick' => 1],
        TableMap::TYPE_CAMELNAME => ['id' => 0, 'nick' => 1],
        TableMap::TYPE_COLNAME => ['testable_active_record.id' => 0, 'testable_active_record.nick' => 1],
        TableMap::TYPE_FIELDNAME => ['id' => 0, 'nick' => 1],
        TableMap::TYPE_NUM => [0 => 0, 1 => 1],
    ];
}
