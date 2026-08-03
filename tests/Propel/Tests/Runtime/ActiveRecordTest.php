<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\ActiveRecord;

require_once __DIR__ . '/ActiveRecordTestClasses.php';

use DateTime;
use InvalidArgumentException;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Map\TableMap;
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
    public function testConvertValueToPhpTypeCastsKnownPrimitiveValues()
    {
        $record = new TestableActiveRecord();

        $this->assertSame('7', $record->convertValueToPhpTypeTestValue(7, 'string', false));
        $this->assertSame(7, $record->convertValueToPhpTypeTestValue('7', 'int', false));
        $this->assertSame(7.5, $record->convertValueToPhpTypeTestValue('7.5', 'float', false));
        $this->assertTrue($record->convertValueToPhpTypeTestValue(1, 'bool', false));
        $this->assertNull($record->convertValueToPhpTypeTestValue(null, 'string', true));
        $this->assertNull($record->convertValueToPhpTypeTestValue(null, 'int', true));
        $this->assertNull($record->convertValueToPhpTypeTestValue(null, 'float', true));
        $this->assertNull($record->convertValueToPhpTypeTestValue(null, 'bool', true));
        $this->assertSame('raw', $record->convertValueToPhpTypeTestValue('raw', 'App\\ValueObject', false));
    }

    /**
     * @return void
     */
    public function testConvertValueToPhpTypeReadsArrayColumnValues()
    {
        $record = new TestableActiveRecord();

        $this->assertSame(['a', 'b'], $record->convertValueToPhpTypeTestValue(['a', 'b'], 'array', true));
        $this->assertSame([], $record->convertValueToPhpTypeTestValue('{}', 'array', true));
        $this->assertSame(['a', 'b'], $record->convertValueToPhpTypeTestValue('{a,b}', 'array', true));
        $this->assertSame(['a', 'b'], $record->convertValueToPhpTypeTestValue('[1:2]={a,b}', 'array', true));

        // Empty values are read as no value at all, or as an empty array if the column is required.
        $this->assertNull($record->convertValueToPhpTypeTestValue('', 'array', true));
        $this->assertNull($record->convertValueToPhpTypeTestValue('   ', 'array', true));
        $this->assertSame([], $record->convertValueToPhpTypeTestValue('', 'array', false));

        // Values that are no array representation at all are left to the adapter to report.
        $this->assertSame('raw', $record->convertValueToPhpTypeTestValue('raw', 'array', true));
        $this->assertSame(7, $record->convertValueToPhpTypeTestValue(7, 'array', true));
    }

    /**
     * @return void
     */
    public function testConvertValueToPhpTypeRejectsMalformedArrayLiterals()
    {
        $record = new TestableActiveRecord();

        $this->expectException(InvalidArgumentException::class);

        $record->convertValueToPhpTypeTestValue('{a,b', 'array', true);
    }

    /**
     * @return void
     */
    public function testArePhpTypeValuesEqualUsesValueEqualityForObjects()
    {
        $record = new TestableActiveRecord();

        $left = new ComparableValueObject('same');
        $right = new ComparableValueObject('same');
        $other = new ComparableValueObject('other');

        $this->assertTrue($record->arePhpTypeValuesEqualTestValue('7', '7', 'string'));
        $this->assertFalse($record->arePhpTypeValuesEqualTestValue('7', 7, 'string'));
        $this->assertTrue($record->arePhpTypeValuesEqualTestValue(null, null, 'App\\ValueObject'));
        $this->assertFalse($record->arePhpTypeValuesEqualTestValue($left, null, 'App\\ValueObject'));
        $this->assertTrue($record->arePhpTypeValuesEqualTestValue($left, $right, ComparableValueObject::class));
        $this->assertFalse($record->arePhpTypeValuesEqualTestValue($left, $other, ComparableValueObject::class));
    }

    /**
     * @return void
     */
    public function testHashCodeFromValueBuildsStableHashes()
    {
        $record = new TestableActiveRecord();

        $this->assertSame(crc32(serialize(7)), $record->hashCodeFromTestValue(7));
        $this->assertSame(
            crc32(serialize(['id' => 7, 'code' => 'A'])),
            $record->hashCodeFromTestValue(['id' => 7, 'code' => 'A'])
        );
    }

    /**
     * @return void
     */
    public function testValidatePrimaryKeyUsesGeneratedUnsetValue()
    {
        $record = new TestableActiveRecord();

        $this->assertFalse($record->validatePrimaryKeyTestValue(null, null));
        $this->assertFalse($record->validatePrimaryKeyTestValue(null, 'unset'));
        $this->assertFalse($record->validatePrimaryKeyTestValue('', ''));
        $this->assertTrue($record->validatePrimaryKeyTestValue(7, null));
    }

    /**
     * @return void
     */
    public function testValidatePrimaryKeysUsesGeneratedUnsetValues()
    {
        $record = new TestableActiveRecord();

        $this->assertFalse($record->validatePrimaryKeysTestValue([7], [null, '']));
        $this->assertFalse($record->validatePrimaryKeysTestValue([7, null], [null, 'unset']));
        $this->assertFalse($record->validatePrimaryKeysTestValue([7, ''], [null, '']));
        $this->assertTrue($record->validatePrimaryKeysTestValue([7, 'code'], [null, '']));
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
