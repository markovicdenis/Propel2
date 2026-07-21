<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Builder\Om;

use LazyLoadActiveRecord;
use LazyLoadActiveRecordQuery;
use Map\LazyLoadActiveRecordTableMap;
use Propel\Generator\Util\QuickBuilder;
use Propel\Runtime\Map\TableMap;
use Propel\Runtime\Propel;
use Propel\Tests\TestCase;

/**
 * Tests the generated Object classes for lazy load columns.
 */
class GeneratedObjectLazyLoadTest extends TestCase
{
    /**
     * @return void
     */
    public function setUp(): void
    {
        if (!class_exists('LazyLoadActiveRecord')) {
            $schema = <<<EOF
<database name="lazy_load_active_record_1">
    <table name="lazy_load_active_record">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
        <column name="foo" type="VARCHAR" size="100"/>
        <column name="bar" type="VARCHAR" size="100" lazyLoad="true"/>
        <column name="baz" type="VARCHAR" size="100" defaultValue="world" lazyLoad="true"/>
    </table>
</database>
EOF;
            //QuickBuilder::debugClassesForTable($schema, 'lazy_load_active_record');
            QuickBuilder::buildSchema($schema);
        }
    }

    /**
     * @return void
     */
    public function testNormalColumnsRequireNoQueryOnGetter()
    {
        $con = Propel::getServiceContainer()->getConnection(LazyLoadActiveRecordTableMap::DATABASE_NAME);
        $con->useDebug(true);
        $obj = new LazyLoadActiveRecord();
        $obj->setFoo('hello');
        $obj->save($con);
        LazyLoadActiveRecordTableMap::clearInstancePool();
        $obj2 = LazyLoadActiveRecordQuery::create()->findPk($obj->getId(), $con);
        $count = $con->getQueryCount();
        $this->assertEquals('hello', $obj2->getFoo());
        $this->assertEquals($count, $con->getQueryCount());
    }

    /**
     * @return void
     */
    public function testLazyLoadedColumnsRequireAnAdditionalQueryOnGetter()
    {
        $con = Propel::getServiceContainer()->getConnection(LazyLoadActiveRecordTableMap::DATABASE_NAME);
        $con->useDebug(true);
        $obj = new LazyLoadActiveRecord();
        $obj->setBar('hello');
        $obj->save($con);
        LazyLoadActiveRecordTableMap::clearInstancePool();
        $obj2 = LazyLoadActiveRecordQuery::create()->findPk($obj->getId(), $con);
        $count = $con->getQueryCount();
        $this->assertEquals('hello', $obj2->getBar($con));
        $this->assertEquals($count + 1, $con->getQueryCount());
    }

    /**
     * @return void
     */
    public function testLazyLoadedColumnsWithDefaultRequireAnAdditionalQueryOnGetter()
    {
        $con = Propel::getServiceContainer()->getConnection(LazyLoadActiveRecordTableMap::DATABASE_NAME);
        $con->useDebug(true);
        $obj = new LazyLoadActiveRecord();
        $obj->setBaz('hello');
        $obj->save($con);
        LazyLoadActiveRecordTableMap::clearInstancePool();
        $obj2 = LazyLoadActiveRecordQuery::create()->findPk($obj->getId(), $con);
        $count = $con->getQueryCount();
        $this->assertEquals('hello', $obj2->getBaz($con));
        $this->assertEquals($count + 1, $con->getQueryCount());
    }

    /**
     * @return void
     */
    public function testProjectionKeepsNonLazyHydrationAlignedAndRejectsLazyColumns()
    {
        $con = Propel::getServiceContainer()->getConnection(LazyLoadActiveRecordTableMap::DATABASE_NAME);
        $this->assertSame(['Id', 'Foo'], LazyLoadActiveRecordTableMap::HYDRATE_COLUMN_NAMES);

        $obj = new LazyLoadActiveRecord();
        $obj->setFoo('projected');
        $obj->setBar('lazy');
        $obj->save($con);
        LazyLoadActiveRecordTableMap::clearInstancePool();

        $projected = LazyLoadActiveRecordQuery::create()
            ->filterById($obj->getId())
            ->project('Foo')
            ->findOne($con);

        $this->assertTrue($projected->isPartial());
        $this->assertSame('projected', $projected->getFoo());
        $this->assertFalse($projected->isColumnLoaded('Bar'));
        $this->assertSame(['Id', 'Foo'], array_keys($projected->toArray()));
        $this->assertSame('projected', $projected->toArray()['Foo']);

        $associativeProjection = new LazyLoadActiveRecord();
        $associativeProjection->hydrateProjection(
            ['id' => $obj->getId(), 'foo' => 'associative'],
            ['Id', 'Foo'],
            TableMap::TYPE_FIELDNAME,
        );
        $this->assertSame('associative', $associativeProjection->getFoo());

        $this->expectException(\Propel\Runtime\Exception\PropelException::class);
        LazyLoadActiveRecordQuery::create()
            ->filterById($obj->getId())
            ->project('Bar')
            ->findOne($con);
    }

    /**
     * @return void
     */
    public function testBuildCriteriaIncludesModifiedLazyLoadColumns()
    {
        $obj = new LazyLoadActiveRecord();
        $obj->setBar('hello');

        $criteria = $obj->buildCriteria();

        $this->assertTrue($criteria->containsKey(LazyLoadActiveRecordTableMap::COL_BAR));
        $this->assertSame('hello', $criteria->get(LazyLoadActiveRecordTableMap::COL_BAR));
    }
}
