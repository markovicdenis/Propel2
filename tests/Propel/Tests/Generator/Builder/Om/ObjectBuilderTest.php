<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Builder\Om;

use Propel\Generator\Builder\Om\ObjectBuilder;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\ColumnDefaultValue;
use Propel\Generator\Model\Domain;
use Propel\Generator\Model\Table;
use Propel\Generator\Platform\MysqlPlatform;
use Propel\Tests\TestCase;

/**
 * Test class for ObjectBuilder.
 *
 * @author François Zaninotto
 * @version $Id$
 */
class ObjectBuilderTest extends TestCase
{
    protected $builder;

    /**
     * @return void
     */
    public function setUp(): void
    {
        $builder = new TestableObjectBuilder(new Table('Foo'));
        $builder->setPlatform(new MysqlPlatform());
        $this->builder = $builder;
    }

    public static function getDefaultValueStringProvider()
    {
        $col1 = new Column('Bar');
        $col1->setDomain(new Domain('VARCHAR'));
        $col1->setDefaultValue(new ColumnDefaultValue('abc', ColumnDefaultValue::TYPE_VALUE));
        $val1 = "'abc'";
        $col2 = new Column('Bar');
        $col2->setDomain(new Domain('INTEGER'));
        $col2->setDefaultValue(new ColumnDefaultValue(1234, ColumnDefaultValue::TYPE_VALUE));
        $val2 = '1234';
        $col3 = new Column('Bar');
        $col3->setDomain(new Domain('DATE'));
        $col3->setDefaultValue(new ColumnDefaultValue('0000-00-00', ColumnDefaultValue::TYPE_VALUE));
        $val3 = 'null';

        return [
            [$col1, $val1],
            [$col2, $val2],
            [$col3, $val3],
        ];
    }

    /**
     * @dataProvider getDefaultValueStringProvider
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('getDefaultValueStringProvider')]
    public function testGetDefaultValueString($column, $value)
    {
        $this->assertEquals($value, $this->builder->getDefaultValueString($column));
    }

    /**
     * @return void
     */
    public function testGetDefaultKeyType()
    {
        $this->assertEquals('TYPE_PHPNAME', $this->builder->getDefaultKeyType());
    }

    /**
     * @return void
     */
    public function testBaseObjectMethodsUseSharedTrait()
    {
        $classBodyStart = '';
        $this->builder->addCommonTraitUsesToScript($classBodyStart);

        $this->assertStringContainsString('use ActiveRecordCommonTrait;', $classBodyStart);
        $this->assertStringContainsString('use ActiveRecordHydrationTrait;', $classBodyStart);
        $this->assertStringStartsWith('use ActiveRecordCommonTrait;', ltrim($classBodyStart));

        $baseObjectMethods = '';
        $this->builder->addBaseObjectMethodsToScript($baseObjectMethods);

        $this->assertStringNotContainsString('use ActiveRecordCommonTrait;', $baseObjectMethods);
        $this->assertStringNotContainsString('public function resetModified(?string $col = null): void', $baseObjectMethods);
        $this->assertStringNotContainsString('public function equals($obj): bool', $baseObjectMethods);
        $this->assertStringNotContainsString('public function getVirtualColumns(): array', $baseObjectMethods);
        $this->assertStringNotContainsString('public function exportTo($parser, bool $includeLazyLoadColumns = true, string $keyType = TableMap::TYPE_PHPNAME): string', $baseObjectMethods);
        $this->assertStringContainsString('public function __sleep(): array', $baseObjectMethods);
    }

    /**
     * @return void
     */
    public function testHydrateUsesResolveFromRowForSimpleColumnsOnly()
    {
        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));

        $title = new Column('title');
        $title->setDomain(new Domain('VARCHAR'));

        $createdAt = new Column('created_at');
        $createdAt->setDomain(new Domain('TIMESTAMP'));

        $this->assertSame(
            '$this->resolveFromRow($row, 0, $startcol, $indexType, static fn ($v) => (int) $v)',
            $this->builder->getResolveFromRowExpressionForColumn($id, 0)
        );
        $this->assertSame(
            '$this->resolveFromRow($row, 1, $startcol, $indexType, static fn ($v) => (string) $v)',
            $this->builder->getResolveFromRowExpressionForColumn($title, 1)
        );
        $this->assertNull($this->builder->getResolveFromRowExpressionForColumn($createdAt, 2));
    }

    /**
     * @return void
     */
    public function testAddSetByPositionUsesMatchExpression()
    {
        $table = new Table('Foo');

        $firstName = new Column('first_name');
        $firstName->setDomain(new Domain('VARCHAR'));
        $table->addColumn($firstName);

        $lastName = new Column('last_name');
        $lastName->setDomain(new Domain('VARCHAR'));
        $table->addColumn($lastName);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addSetByPositionToScript($script);

        $this->assertStringContainsString('match ($pos)', $script);
        $this->assertStringContainsString('0 => $this->setFirstName($value),', $script);
        $this->assertStringContainsString('1 => $this->setLastName($value),', $script);
        $this->assertStringContainsString('default => null', $script);
        $this->assertStringNotContainsString('switch ($pos)', $script);
    }

    /**
     * @return void
     */
    public function testBuildCriteriaUsesAllColumnsLoop()
    {
        $table = new Table('Foo');

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $table->addColumn($id);

        $promotionName = new Column('promotion_name');
        $promotionName->setDomain(new Domain('VARCHAR'));
        $table->addColumn($promotionName);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addBuildCriteriaToScript($script);

        $this->assertStringContainsString('foreach (FooTableMap::ALL_COLUMNS as $columnConstant)', $script);
        $this->assertStringContainsString('$propertyName = FooTableMap::getPropertyName($columnConstant);', $script);
        $this->assertStringContainsString('$criteria->add($columnConstant, $this->{$propertyName});', $script);
        $this->assertStringNotContainsString('if ($this->isColumnModified(FooTableMap::COL_ID)) {', $script);
        $this->assertStringNotContainsString('match ($position)', $script);
    }

    /**
     * @return void
     */
    public function testDoInsertUsesInsertColumnBindingDtos()
    {
        $table = new Table('Foo');

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $id->setAutoIncrement(true);
        $table->addColumn($id);

        $name = new Column('name');
        $name->setDomain(new Domain('VARCHAR'));
        $table->addColumn($name);

        $createdAt = new Column('created_at');
        $createdAt->setDomain(new Domain('TIMESTAMP'));
        $table->addColumn($createdAt);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = $builder->addDoInsertToScript();

        $this->assertStringContainsString('/** @var list<InsertColumnBindingDto> $columnBindings */', $script);
        $this->assertStringContainsString('$columnBindings[] = new InsertColumnBindingDto(":p{$index++}", \'id\', $this->id, PDO::PARAM_INT);', $script);
        $this->assertStringContainsString('$columnBindings[] = new InsertColumnBindingDto(":p{$index++}", \'name\', $this->name, PDO::PARAM_STR);', $script);
        $this->assertStringContainsString('$columnBindings[] = new InsertColumnBindingDto(":p{$index++}", \'created_at\', $this->created_at ? $this->created_at->format(\'Y-m-d H:i:s.u\') : null, PDO::PARAM_STR);', $script);
        $this->assertStringContainsString('array_map(static fn (InsertColumnBindingDto $binding): string => $binding->quotedColumnName, $columnBindings)', $script);
        $this->assertStringContainsString('array_map(static fn (InsertColumnBindingDto $binding): string => $binding->identifier, $columnBindings)', $script);
        $this->assertStringContainsString('$binding->bind($stmt);', $script);
        $this->assertStringNotContainsString('$identifier = \':p\' . $index++;', $script);
        $this->assertStringNotContainsString('match ($columnName)', $script);
    }

}

class TestableObjectBuilder extends ObjectBuilder
{
    public function getDefaultValueString(Column $col, bool $acceptNull = true): string
    {
        return parent::getDefaultValueString($col, $acceptNull);
    }

    public function getTableMapClass(): string
    {
        return $this->getTable()->getPhpName() . 'TableMap';
    }

    public function getUnprefixedClassName(): string
    {
        return $this->getTable()->getPhpName();
    }

    public function getObjectClassName(bool $fqcn = false): string
    {
        return $this->getTable()->getPhpName();
    }

    public function addSetByPositionToScript(string &$script): void
    {
        $this->addSetByPosition($script);
    }

    public function addBuildCriteriaToScript(string &$script): void
    {
        $this->addBuildCriteria($script);
    }

    public function addDoInsertToScript(): string
    {
        return $this->addDoInsert();
    }

    public function addBaseObjectMethodsToScript(string &$script): void
    {
        $this->addBaseObjectMethods($script);
    }

    public function addCommonTraitUsesToScript(string &$script): void
    {
        $this->addCommonTraitUses($script);
    }

    public function getResolveFromRowExpressionForColumn(Column $column, int $position): ?string
    {
        return $this->getResolveFromRowExpression($column, $position);
    }
}
