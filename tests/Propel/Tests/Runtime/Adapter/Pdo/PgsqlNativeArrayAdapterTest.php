<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\Adapter\Pdo;

use PDO;
use PHPUnit\Framework\TestCase;
use Propel\Runtime\Adapter\Pdo\PgsqlAdapter;
use Propel\Runtime\Connection\StatementInterface;
use Propel\Runtime\Map\ColumnMap;
use Propel\Runtime\Map\TableMap;

class PgsqlNativeArrayAdapterTest extends TestCase
{
    /**
     * @return void
     */
    public function testNativeArrayIsEncodedAndBoundAsString(): void
    {
        $statement = $this->createMock(StatementInterface::class);
        $statement
            ->expects($this->once())
            ->method('bindValue')
            ->with(':p1', '{"one","two"}', PDO::PARAM_STR)
            ->willReturn(true);

        $tableMap = new TableMap('items');
        $columnMap = new ColumnMap('tags', $tableMap, 'Tags', 'NATIVE_ARRAY');

        $adapter = new PgsqlAdapter();
        $this->assertTrue($adapter->bindValue($statement, ':p1', ['one', 'two'], $columnMap));
    }
}
