<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\Adapter\Pdo;

use PHPUnit\Framework\MockObject\MockObject;
use Propel\Runtime\ActiveQuery\AggregationConfig;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Adapter\Pdo\PgsqlAdapter;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Propel\Runtime\ServiceContainer\StandardServiceContainer;
use Propel\Tests\Bookstore\BookQuery;
use Propel\Tests\Bookstore\Map\BookTableMap;
use Propel\Tests\TestCaseFixtures;

/**
 * Tests the Pgsql adapter
 *
 * @author Kévin Gomez <contact@kevingomez.fr>
 */
class PgsqlAdapterTest extends TestCaseFixtures
{
    /**
     * @return string
     */
    protected function getDriver()
    {
        return 'pgsql';
    }

    /**
     * @return void
     */
    public function testPrimaryKeyIsRetrievedAfterInsert()
    {
        $adapter = new PgsqlAdapter();

        $this->assertFalse($adapter->isGetIdBeforeInsert());
        $this->assertTrue($adapter->isGetIdAfterInsert());
    }

    /**
     * @return void
     */
    public function testGetIdUsesLastInsertIdWithSequenceName()
    {
        $adapter = new PgsqlAdapter();
        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection
            ->expects($this->once())
            ->method('lastInsertId')
            ->with('logs_id_seq')
            ->willReturn('42');

        $this->assertSame('42', $adapter->getId($connection, 'logs_id_seq'));
    }

    protected function createPgsqlSql(Criteria $query): string
    {
        $params = [];
        $serviceContainer = Propel::getServiceContainer();
        if ($serviceContainer instanceof StandardServiceContainer) {
            $serviceContainer->setAdapter('pgsql', new PgsqlAdapter());
        }
        $query->setDbName('pgsql');

        return $query->createSelectSql($params);
    }

    /**
     * @return void
     */
    public function testGetExplainPlanQuery()
    {
        $db = new PgsqlAdapter();
        $query = 'SELECT B.* FROM (SELECT A.*, rownum AS PROPEL_ROWNUM FROM (SELECT book.ID AS ORA_COL_ALIAS_0, book.TITLE AS ORA_COL_ALIAS_1, book.ISBN AS ORA_COL_ALIAS_2, book.PRICE AS ORA_COL_ALIAS_3, book.PUBLISHER_ID AS ORA_COL_ALIAS_4, book.AUTHOR_ID AS ORA_COL_ALIAS_5, author.ID AS ORA_COL_ALIAS_6, author.FIRST_NAME AS ORA_COL_ALIAS_7, author.LAST_NAME AS ORA_COL_ALIAS_8, author.EMAIL AS ORA_COL_ALIAS_9, author.AGE AS ORA_COL_ALIAS_10, book.PRICE AS BOOK_PRICE FROM book, author) A ) B WHERE  B.PROPEL_ROWNUM <= 1';
        $expected = 'EXPLAIN SELECT B.* FROM (SELECT A.*, rownum AS PROPEL_ROWNUM FROM (SELECT book.ID AS ORA_COL_ALIAS_0, book.TITLE AS ORA_COL_ALIAS_1, book.ISBN AS ORA_COL_ALIAS_2, book.PRICE AS ORA_COL_ALIAS_3, book.PUBLISHER_ID AS ORA_COL_ALIAS_4, book.AUTHOR_ID AS ORA_COL_ALIAS_5, author.ID AS ORA_COL_ALIAS_6, author.FIRST_NAME AS ORA_COL_ALIAS_7, author.LAST_NAME AS ORA_COL_ALIAS_8, author.EMAIL AS ORA_COL_ALIAS_9, author.AGE AS ORA_COL_ALIAS_10, book.PRICE AS BOOK_PRICE FROM book, author) A ) B WHERE  B.PROPEL_ROWNUM <= 1';

        $this->assertEquals($expected, $db->getExplainPlanQuery($query), 'getExplainPlanQuery() returns a SQL Explain query');
    }

    /**
     * Test `applyLock`
     *
     * @return void
     *
     * @group pgsql
     */
    public function testSimpleLock(): void
    {
        $c = new BookQuery();
        $c->addSelectColumn(BookTableMap::COL_ID);
        $c->lockForShare();

        $result = $this->createPgsqlSql($c);

        $expected = 'SELECT book.id FROM book FOR SHARE';

        $this->assertEquals($expected, $result);
    }

    /**
     * Test `applyLock`
     *
     * @return void
     *
     * @group pgsql
     */
    public function testComplexLock(): void
    {
        $c = new BookQuery();
        $c->addSelectColumn(BookTableMap::COL_ID);
        $c->lockForUpdate([BookTableMap::TABLE_NAME], true);

        $result = $this->createPgsqlSql($c);

        $expected = 'SELECT book.id FROM book FOR UPDATE OF "book" NOWAIT';

        $this->assertEquals($expected, $result);
    }

    /**
     * @return void
     *
     * @group pgsql
     */
    public function testSubQueryWithSharedLock()
    {
        $subCriteria = new BookQuery();
        $subCriteria->addSelectColumn(BookTableMap::COL_ID);
        $subCriteria->setDbName('pgsql');
        $subCriteria->lockForShare([BookTableMap::TABLE_NAME]);

        $c = new BookQuery();
        $c->addSelectColumn(BookTableMap::COL_ID);
        $c->addSelectQuery($subCriteria, 'subCriteriaAlias', false);
        $c->lockForShare([BookTableMap::TABLE_NAME], true);

        $expected = 'SELECT book.id FROM book, (SELECT book.id FROM book FOR SHARE OF "book") AS subCriteriaAlias FOR SHARE OF "book" NOWAIT';

        $this->assertSame($expected, $this->createPgsqlSql($c), 'Subquery contains shared read lock');
    }

    /**
     * @return void
     *
     * @group pgsql
     */
    public function testOrderByAggregateAliasUsesUnderlyingExpression()
    {
        $query = BookQuery::create()
            ->addSelectColumn(BookTableMap::COL_AUTHOR_ID)
            ->withColumn('MAX(Book.Id)', 'CreatedAt')
            ->groupBy('Book.AuthorId')
            ->orderBy('CreatedAt', Criteria::DESC);

        $generatedSql = $this->createPgsqlSql($query);

        $this->assertStringContainsString('MAX(book.id) AS "CreatedAt"', $generatedSql);
        $this->assertStringContainsString('ORDER BY MAX(book.id) DESC', $generatedSql);
        $this->assertStringNotContainsString('ANY_VALUE(MAX(book.id))', $generatedSql);
        $this->assertStringNotContainsString('ORDER BY CreatedAt DESC', $generatedSql);
    }

    /**
     * @return void
     *
     * @group pgsql
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

        $generatedSql = $this->createPgsqlSql($query);

        $this->assertStringContainsString('MIN(book.title) AS "MinTitle"', $generatedSql);
        $this->assertStringContainsString('ORDER BY MIN(book.title) DESC', $generatedSql);
        $this->assertStringNotContainsString('ORDER BY MinTitle DESC', $generatedSql);
    }
}
