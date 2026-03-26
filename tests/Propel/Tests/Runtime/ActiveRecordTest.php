<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\ActiveRecord;

use DateTime;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Map\TableMap;
use ReflectionMethod;
use Propel\Tests\TestCase;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Closure;

/**
 * Test class for ActiveRecord.
 *
 * @author François Zaninotto
 */
class ActiveRecordTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        include_once(__DIR__ . '/ActiveRecordTestClasses.php');
    }

    /**
     * @return void
     */
    public function testGetVirtualColumns()
    {
        $b = new TestableActiveRecord();
        $this->assertEquals([], $b->getVirtualColumns(), 'getVirtualColumns() returns an empty array for new objects');
        $b->virtualColumns = ['foo' => 'bar'];
        $this->assertEquals(['foo' => 'bar'], $b->getVirtualColumns(), 'getVirtualColumns() returns an associative array of virtual columns');
    }

    /**
     * @return void
     */
    public function testHasVirtualColumn()
    {
        $b = new TestableActiveRecord();
        $this->assertFalse($b->hasVirtualColumn('foo'), 'hasVirtualColumn() returns false if the virtual column is not set');
        $b->virtualColumns = ['foo' => 'bar'];
        $this->assertTrue($b->hasVirtualColumn('foo'), 'hasVirtualColumn() returns true if the virtual column is set');
        $b->virtualColumns = ['foo' => null];
        $this->assertTrue($b->hasVirtualColumn('foo'), 'hasVirtualColumn() returns true if the virtual column is set and has NULL value');
    }

    /**
     * @return void
     */
    public function testGetVirtualColumnWrongKey()
    {
        $this->expectException(PropelException::class);

        $b = new TestableActiveRecord();
        $b->getVirtualColumn('foo');
    }

    /**
     * @return void
     */
    public function testGetVirtualColumn()
    {
        $b = new TestableActiveRecord();
        $b->virtualColumns = ['foo' => 'bar'];
        $this->assertEquals('bar', $b->getVirtualColumn('foo'), 'getVirtualColumn() returns a virtual column value based on its key');
    }

    /**
     * @return void
     */
    public function testSetVirtualColumn()
    {
        $b = new TestableActiveRecord();
        $b->setVirtualColumn('foo', 'bar');
        $this->assertEquals('bar', $b->getVirtualColumn('foo'), 'setVirtualColumn() sets a virtual column value based on its key');
        $b->setVirtualColumn('foo', 'baz');
        $this->assertEquals('baz', $b->getVirtualColumn('foo'), 'setVirtualColumn() can modify the value of an existing virtual column');
        $this->assertEquals($b, $b->setVirtualColumn('foo', 'bar'), 'setVirtualColumn() returns the current object');
    }

    /**
     * @return void
     */
    public function testEquals()
    {
        $left = new TestableActiveRecord();
        $right = new TestableActiveRecord();

        $left->primaryKey = 7;
        $right->primaryKey = 7;

        $this->assertTrue($left->equals($right));
        $this->assertFalse($left->equals(new stdClass()));
    }

    /**
     * @return void
     */
    public function testResetModified()
    {
        $record = new TestableActiveRecord();
        $record->setVirtualColumn('x', 1);

        $reflection = new ReflectionProperty($record, 'modifiedColumns');
        $reflection->setValue($record, ['foo' => true, 'bar' => true]);

        $record->resetModified('foo');
        $this->assertSame(['bar'], $record->getModifiedColumns());

        $record->resetModified();
        $this->assertSame([], $record->getModifiedColumns());
    }

    /**
     * @return void
     */
    public function testExportTo()
    {
        $record = new TestableActiveRecord();

        $this->assertSame('{"foo":"bar"}', $record->exportTo('JSON'));
    }

    /**
     * @return void
     */
    public function testResolveFromRow()
    {
        $record = new TestableActiveRecord();

        $this->assertSame(7, $record->resolveFromTestRow([7, 'nick'], 0, 0, TableMap::TYPE_NUM, static fn ($v) => (int) $v));
        $this->assertSame('nick', $record->resolveFromTestRow(['Id' => 7, 'Nick' => 'nick'], 1, 0, TableMap::TYPE_PHPNAME, static fn ($v) => (string) $v));
        $this->assertNull($record->resolveFromTestRow([null, 'nick'], 0, 0, TableMap::TYPE_NUM, static fn () => throw new RuntimeException('should not run')));
    }

    /**
     * @return void
     */
    public function testCastToCastsScalarValues()
    {
        $record = new TestableActiveRecord();

        $this->assertSame('7', $record->castToTestValue(7, 'string', true));
        $this->assertSame(7, $record->castToTestValue('7', 'int', true));
        $this->assertSame(7.5, $record->castToTestValue('7.5', 'float', true));
        $this->assertTrue($record->castToTestValue(1, 'bool', true));
        $this->assertNull($record->castToTestValue(null, 'string', true));
        $this->assertSame('', $record->castToTestValue(null, 'string', false));
        $this->assertSame(0, $record->castToTestValue(null, 'int', false));
        $this->assertSame(0.0, $record->castToTestValue(null, 'float', false));
        $this->assertFalse($record->castToTestValue(null, 'bool', false));
    }

    /**
     * @return void
     */
    public function testHashCodeFromValueBuildsStableHashes()
    {
        $record = new TestableActiveRecord();

        $this->assertSame(crc32(json_encode(7, JSON_UNESCAPED_UNICODE) ?: ''), $record->hashCodeFromTestValue(7));
        $this->assertSame(
            crc32(json_encode(['id' => 7, 'code' => 'A'], JSON_UNESCAPED_UNICODE) ?: ''),
            $record->hashCodeFromTestValue(['id' => 7, 'code' => 'A'])
        );
    }

    /**
     * @return void
     */
    public function testValidatePrimaryKeyUsesGeneratedUnsetValue()
    {
        $record = new TestableActiveRecord();
        $method = new ReflectionMethod($record, 'validatePrimaryKey');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($record, null, null));
        $this->assertFalse($method->invoke($record, null, 'unset'));
        $this->assertFalse($method->invoke($record, '', ''));
        $this->assertTrue($method->invoke($record, 7, null));
    }

    /**
     * @return void
     */
    public function testValidatePrimaryKeysUsesGeneratedUnsetValues()
    {
        $record = new TestableActiveRecord();
        $method = new ReflectionMethod($record, 'validatePrimaryKeys');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($record, [7], [null, '']));
        $this->assertFalse($method->invoke($record, [7, null], [null, 'unset']));
        $this->assertFalse($method->invoke($record, [7, ''], [null, '']));
        $this->assertTrue($method->invoke($record, [7, 'code'], [null, '']));
    }

    /**
     * @return void
     */
    public function testSetTemporalValueNormalizesClonesAndMarksColumnModified()
    {
        $record = new TestableActiveRecord();
        $source = new DateTime('2024-01-02 03:04:05.123456');
        $setter = Closure::bind(
            function ($value, string $dateTimeClass, string $columnConstant, string $comparisonFormat, ?string $defaultValue = null, ?string $defaultValueFormat = null): void {
                $this->setTemporalValue($this->temporalValue, $value, $dateTimeClass, $columnConstant, $comparisonFormat, $defaultValue, $defaultValueFormat);
            },
            $record,
            $record
        );

        $setter($source, '\\DateTime', TestableActiveRecordTableMap::COL_CREATED_AT, 'Y-m-d H:i:s.u');

        $this->assertInstanceOf(DateTime::class, $record->temporalValue);
        $this->assertNotSame($source, $record->temporalValue);
        $this->assertSame(['testable_active_record.created_at'], $record->getModifiedColumns());

        $source->modify('+1 day');
        $this->assertSame('2024-01-02 03:04:05.123456', $record->temporalValue->format('Y-m-d H:i:s.u'));
    }

    /**
     * @return void
     */
    public function testSetTemporalValueSkipsModificationWhenValueDoesNotChange()
    {
        $record = new TestableActiveRecord();
        $record->temporalValue = new DateTime('2024-01-02 03:04:05.123456');
        $setter = Closure::bind(
            function ($value, string $dateTimeClass, string $columnConstant, string $comparisonFormat, ?string $defaultValue = null, ?string $defaultValueFormat = null): void {
                $this->setTemporalValue($this->temporalValue, $value, $dateTimeClass, $columnConstant, $comparisonFormat, $defaultValue, $defaultValueFormat);
            },
            $record,
            $record
        );

        $setter('2024-01-02 03:04:05.123456', '\\DateTime', TestableActiveRecordTableMap::COL_CREATED_AT, 'Y-m-d H:i:s.u');

        $this->assertSame([], $record->getModifiedColumns());
    }

    /**
     * @return void
     */
    public function testFieldNameTranslationWorksWithoutGeneratedReverseMaps()
    {
        $this->assertSame([0, 1], TestableActiveRecordTableMap::getFieldNames(TableMap::TYPE_NUM));
        $this->assertSame(1, TestableActiveRecordTableMap::translateFieldName('Nick', TableMap::TYPE_PHPNAME, TableMap::TYPE_NUM));
        $this->assertSame('nick', TestableActiveRecordTableMap::translateFieldName(1, TableMap::TYPE_NUM, TableMap::TYPE_FIELDNAME));
    }
}
