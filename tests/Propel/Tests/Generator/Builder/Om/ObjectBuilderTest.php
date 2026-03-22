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

        $uuidBin = new Column('uuid_bin');
        $uuidBin->setDomain(new Domain('UUID_BINARY'));
        $table->addColumn($uuidBin);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addBuildCriteriaToScript($script);

        $this->assertStringContainsString('foreach (FooTableMap::ALL_COLUMNS as $position => $columnConstant)', $script);
        $this->assertStringContainsString('$criteria->add($columnConstant, match ($position) {', $script);
        $this->assertStringContainsString('1 => ($this->uuid_bin) ? UuidConverter::uuidToBin($this->uuid_bin, true) : null,', $script);
        $this->assertStringNotContainsString('private function getBuildCriteriaValueByPosition(int $pos)', $script);
        $this->assertStringNotContainsString('if ($this->isColumnModified(FooTableMap::COL_ID)) {', $script);
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

    public function addSetByPositionToScript(string &$script): void
    {
        $this->addSetByPosition($script);
    }

    public function addBuildCriteriaToScript(string &$script): void
    {
        $this->addBuildCriteria($script);
    }
}
