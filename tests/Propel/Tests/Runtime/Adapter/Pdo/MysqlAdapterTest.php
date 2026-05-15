<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\Adapter\Pdo;

use PHPUnit\Framework\Attributes\DataProvider;
use Propel\Runtime\ActiveQuery\AggregationConfig;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Adapter\Pdo\MysqlAdapter;
use Propel\Runtime\Propel;
use Propel\Runtime\ServiceContainer\StandardServiceContainer;
use Propel\Tests\Bookstore\BookQuery;
use Propel\Tests\Bookstore\Map\BookTableMap;
use Propel\Tests\TestCaseFixtures;

use function is_array;

/**
 * Tests the DbMySQL adapter
 *
 * @see BookstoreDataPopulator
 * @author William Durand
 */
class MysqlAdapterTest extends TestCaseFixtures
{
    protected function createMysqlSql(Criteria $query): string
    {
        $params = [];
        $serviceContainer = Propel::getServiceContainer();
        if ($serviceContainer instanceof StandardServiceContainer) {
            $serviceContainer->setAdapter(BookTableMap::DATABASE_NAME, new MysqlAdapter());
        }
        $query->setDbName(BookTableMap::DATABASE_NAME);

        return $query->createSelectSql($params);
    }

    /**
     * @return array
     */
    public static function getConParams()
    {
        return [
            [
                [
                    'dsn' => 'dsn=my_dsn',
                    'settings' => [
                        'charset' => 'foobar',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return string
     */
    protected function getDriver()
    {
        return 'mysql';
    }

    /**
     * @dataProvider getConParams
     *
     * @param array $conparams
     *
     * @return void
     */
    #[DataProvider('getConParams')]
    public function testPrepareParamsThrowsException($conparams)
    {
        $db = new TestableMysqlAdapter();
        $result = $db->prepareParams($conparams);

        $this->assertIsArray($result);
    }

    /**
     * @dataProvider getConParams
     *
     * @return void
     */
    #[DataProvider('getConParams')]
    public function testPrepareParams($conparams)
    {
        $db = new TestableMysqlAdapter();
        $params = $db->prepareParams($conparams);

        $this->assertTrue(is_array($params));
        $this->assertEquals('dsn=my_dsn;charset=foobar', $params['dsn'], 'The given charset is in the DSN string');
        $this->assertArrayNotHasKey('charset', $params['settings'], 'The charset should be removed');
    }

    /**
     * @dataProvider getConParams
     *
     * @return void
     */
    #[DataProvider('getConParams')]
    public function testNoSetNameQueryExecuted($conparams)
    {
        $db = new TestableMysqlAdapter();
        $params = $db->prepareParams($conparams);

        $settings = [];
        if (isset($params['settings'])) {
            $settings = $params['settings'];
        }

        $db->initConnection($this->getPdoMock(), $settings);
    }

    protected function getPdoMock()
    {
        $con = $this
            ->getMockBuilder('\Propel\Runtime\Connection\ConnectionInterface')->getMock();

        $con
            ->expects($this->never())
            ->method('exec');

        return $con;
    }

    /**
     * Test `applyLock`
     *
     * @return void
     *
     * @group mysql
     */
    public function testSimpleLock(): void
    {
        $c = new BookQuery();
        $c->addSelectColumn(BookTableMap::COL_ID);
        $c->lockForShare();

        $result = $this->createMysqlSql($c);

        $expected = 'SELECT book.id FROM book LOCK IN SHARE MODE';

        $this->assertEquals($expected, $result);
    }

    /**
     * Test `applyLock`
     *
     * @return void
     *
     * @group mysql
     */
    public function testComplexLock(): void
    {
        $c = new BookQuery();
        $c->addSelectColumn(BookTableMap::COL_ID);
        $c->lockForUpdate([BookTableMap::TABLE_NAME], true);

        $result = $this->createMysqlSql($c);

        $expected = 'SELECT book.id FROM book FOR UPDATE';

        $this->assertEquals($expected, $result);
    }

    /**
     * @return void
     *
     * @group mysql
     */
    public function testSubQueryWithSharedLock()
    {
        $subquery = BookQuery::create()
            ->addSelectColumn(BookTableMap::COL_ID)
            ->lockForShare([BookTableMap::TABLE_NAME])
        ;

        $query = BookQuery::create()
            ->addSelectColumn('subCriteriaAlias.id')
            ->addSelectQuery($subquery, 'subCriteriaAlias', false)
            ->lockForShare([BookTableMap::TABLE_NAME], true)
        ;

        $expectedSql = 'SELECT subCriteriaAlias.id FROM (SELECT book.id FROM book LOCK IN SHARE MODE) AS subCriteriaAlias LOCK IN SHARE MODE';

        $generatedSql = $this->createMysqlSql($query);
        $this->assertSame($expectedSql, $generatedSql, 'Subquery should contain shared read lock');
    }

    /**
     * @return void
     *
     * @group mysql
     */
    public function testOrderByAggregateAliasUsesUnderlyingExpression()
    {
        $query = BookQuery::create()
            ->addSelectColumn(BookTableMap::COL_AUTHOR_ID)
            ->withColumn('MAX(Book.Id)', 'CreatedAt')
            ->groupBy('Book.AuthorId')
            ->orderBy('CreatedAt', Criteria::DESC);

        $generatedSql = $this->createMysqlSql($query);

        $this->assertStringContainsString('ORDER BY MAX(book.id) DESC', $generatedSql);
        $this->assertStringNotContainsString('ANY_VALUE(MAX(book.id))', $generatedSql);
    }

    /**
     * @return void
     *
     * @group mysql
     */
    public function testOrderByGroupedAliasStillWrapsNonAggregateExpression()
    {
        $query = BookQuery::create()
            ->addSelectColumn(BookTableMap::COL_AUTHOR_ID)
            ->withColumn('Book.Title', 'BookTitle')
            ->groupBy('Book.AuthorId')
            ->orderBy('BookTitle', Criteria::DESC);

        $generatedSql = $this->createMysqlSql($query);

        $this->assertStringContainsString('ORDER BY ANY_VALUE(book.title) DESC', $generatedSql);
    }

    /**
     * @return void
     *
     * @group mysql
     */
    public function testWithAggregationReusesDefaultConfigWithAlias()
    {
        $query = new class () extends BookQuery {
            public function getDefaultAggregationConfigs(): array
            {
                return [
                    BookTableMap::COL_TITLE => AggregationConfig::create(function: 'MAX'),
                ];
            }
        };

        $query
            ->addSelectColumn(BookTableMap::COL_AUTHOR_ID)
            ->addSelectColumn(BookTableMap::COL_TITLE)
            ->groupBy('Book.AuthorId')
            ->withAggregation('Book.Title', alias: 'BookTitle')
            ->orderBy(BookTableMap::COL_TITLE, Criteria::DESC);

        $generatedSql = $this->createMysqlSql($query);

        $this->assertStringContainsString('MAX(book.title) AS `BookTitle`', $generatedSql);
        $this->assertStringContainsString('ORDER BY `BookTitle` DESC', $generatedSql);
        $this->assertStringNotContainsString('ANY_VALUE(book.title)', $generatedSql);
    }

    /**
     * @return void
     *
     * @group mysql
     */
    public function testWithAggregationAddsAliasedExtraAggregationAsColumn()
    {
        $query = new class () extends BookQuery {
            public function getDefaultAggregationConfigs(): array
            {
                return [
                    BookTableMap::COL_TITLE => AggregationConfig::create(function: 'MAX'),
                ];
            }
        };

        $query
            ->addSelectColumn(BookTableMap::COL_AUTHOR_ID)
            ->addSelectColumn(BookTableMap::COL_TITLE)
            ->groupBy('Book.AuthorId')
            ->withAggregation('Book.Title')
            ->withAggregation('Book.Title', 'MIN', 'MinTitle')
            ->orderBy(BookTableMap::COL_TITLE, Criteria::DESC);

        $generatedSql = $this->createMysqlSql($query);

        $this->assertStringContainsString('MAX(book.title)', $generatedSql);
        $this->assertStringContainsString('MIN(book.title) AS `MinTitle`', $generatedSql);
        $this->assertStringContainsString('ORDER BY MAX(book.title) DESC', $generatedSql);
    }

    /**
     * @return void
     *
     * @group mysql
     */
    public function testOrderByAliasedExtraAggregationUsesUnderlyingExpression()
    {
        $query = new class () extends BookQuery {
            public function getDefaultAggregationConfigs(): array
            {
                return [
                    BookTableMap::COL_TITLE => AggregationConfig::create(function: 'MAX'),
                ];
            }
        };

        $query
            ->addSelectColumn(BookTableMap::COL_AUTHOR_ID)
            ->addSelectColumn(BookTableMap::COL_TITLE)
            ->groupBy('Book.AuthorId')
            ->withAggregation('Book.Title')
            ->withAggregation('Book.Title', 'MIN', 'MinTitle')
            ->orderBy('MinTitle', Criteria::DESC);

        $generatedSql = $this->createMysqlSql($query);

        $this->assertStringContainsString('MIN(book.title) AS `MinTitle`', $generatedSql);
        $this->assertStringContainsString('ORDER BY MIN(book.title) DESC', $generatedSql);
        $this->assertStringNotContainsString('ORDER BY `MinTitle` DESC', $generatedSql);
    }
}

class TestableMysqlAdapter extends MysqlAdapter
{
    /**
     * @param array $conparams
     *
     * @return array
     */
    public function prepareParams($conparams): array
    {
        return parent::prepareParams($conparams);
    }
}
