<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\ActiveQuery\QueryExecutor;

use PHPUnit\Framework\MockObject\MockObject;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Adapter\Pdo\PgsqlAdapter;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Connection\StatementInterface;
use Propel\Runtime\Propel;
use Propel\Runtime\ServiceContainer\StandardServiceContainer;
use Propel\Tests\Bookstore\Map\BookTableMap;
use Propel\Tests\TestCaseFixtures;

class InsertQueryExecutorTest extends TestCaseFixtures
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
    public function testCriteriaInsertUsesReturningInsteadOfLastInsertId()
    {
        $serviceContainer = Propel::getServiceContainer();
        if ($serviceContainer instanceof StandardServiceContainer) {
            $serviceContainer->setAdapter(BookTableMap::DATABASE_NAME, new PgsqlAdapter());
        }

        $criteria = new Criteria(BookTableMap::DATABASE_NAME);
        $criteria->add(BookTableMap::COL_TITLE, 'Returning Title');

        /** @var StatementInterface&MockObject $statement */
        $statement = $this->createMock(StatementInterface::class);
        $statement->method('bindValue')->willReturn(true);
        $statement->expects($this->once())
            ->method('execute')
            ->with(null)
            ->willReturn(true);
        $statement->expects($this->once())
            ->method('fetchColumn')
            ->with(0)
            ->willReturn('42');

        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('prepare')
            ->with('INSERT INTO book (title) VALUES (:p1) RETURNING "id"')
            ->willReturn($statement);
        $connection->expects($this->never())
            ->method('lastInsertId');

        $this->assertSame('42', $criteria->doInsert($connection));
    }
}
