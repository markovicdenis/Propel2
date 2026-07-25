<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Reverse;

use PHPUnit\Framework\TestCase;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\Database;
use Propel\Generator\Model\Table;
use Propel\Generator\Platform\MysqlPlatform;
use Propel\Generator\Reverse\MysqlSchemaParser;

class MysqlSchemaParserUnitTest extends TestCase
{
    /**
     * @return array<string, array<string>>
     */
    public static function integerDisplayWidthProvider(): array
    {
        return [
            'tinyint' => ['tinyint unsigned', 'tinyint(4) unsigned'],
            'smallint' => ['smallint unsigned', 'smallint(6) unsigned'],
            'mediumint' => ['mediumint unsigned', 'mediumint(9) unsigned'],
            'int' => ['int unsigned', 'int(11) unsigned'],
            'bigint' => ['bigint unsigned', 'bigint(20) unsigned'],
        ];
    }

    /**
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('integerDisplayWidthProvider')]
    public function testRestoresLegacyIntegerDisplayWidth(string $reportedType, string $expectedSqlType): void
    {
        $column = $this->getColumnFromReportedType($reportedType);

        $this->assertSame($expectedSqlType, $column->getSqlType());
    }

    /**
     * @return void
     */
    public function testDoesNotAddDisplayWidthToDecimal(): void
    {
        $column = $this->getColumnFromReportedType('decimal unsigned');

        $this->assertSame('decimal unsigned', $column->getSqlType());
    }

    /**
     * @return \Propel\Generator\Model\Column
     */
    private function getColumnFromReportedType(string $reportedType): Column
    {
        $database = new Database('test', new MysqlPlatform());
        $table = $database->addTable(new Table('example'));
        $parser = new MysqlSchemaParser();

        return $parser->getColumnFromRow([
            'Field' => 'id',
            'Type' => $reportedType,
            'Null' => 'NO',
            'Key' => '',
            'Default' => null,
            'Extra' => '',
        ], $table);
    }
}
