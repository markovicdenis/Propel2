<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\ActiveQuery\SqlBuilder;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\SqlBuilder\CountQuerySqlBuilder;
use Propel\Tests\Bookstore\BookQuery;
use Propel\Tests\TestCaseFixtures;

class CountQuerySqlBuilderTest extends TestCaseFixtures
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
    public function testPgsqlDistinctJoinCountUsesDistinctPrimaryKey()
    {
        $criteria = BookQuery::create();
        $criteria->distinct();
        $criteria->join('Book.Review r', Criteria::LEFT_JOIN);
        $criteria->configureSelectColumns();
        $criteria->addSelfSelectColumns();

        $preparedStatementDto = CountQuerySqlBuilder::createCountSql($criteria);

        $this->assertSame(
            'SELECT COUNT(DISTINCT book.id) FROM book LEFT JOIN review r ON (book.id=r.book_id)',
            $preparedStatementDto->getSqlStatement()
        );
    }

    /**
     * @return void
     */
    public function testPgsqlDistinctCountKeepsComplexSubqueryForSelectedColumns()
    {
        $criteria = BookQuery::create();
        $criteria->select(['Title']);
        $criteria->distinct();
        $criteria->configureSelectColumns();

        $preparedStatementDto = CountQuerySqlBuilder::createCountSql($criteria);

        $this->assertSame(
            'SELECT COUNT(*) FROM (SELECT DISTINCT book.title AS "Title" FROM book) propelmatch4cnt',
            $preparedStatementDto->getSqlStatement()
        );
    }
}
