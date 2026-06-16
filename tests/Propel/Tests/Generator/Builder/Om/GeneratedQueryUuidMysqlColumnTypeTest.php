<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Builder\Om;

use PHPUnit\Framework\TestCase;
use Propel\Generator\Platform\MysqlPlatform;
use Propel\Generator\Util\QuickBuilder;
use Propel\Runtime\Adapter\Pdo\MysqlAdapter;
use Propel\Runtime\Propel;
use Propel\Runtime\Util\UuidConverter;

class GeneratedQueryUuidMysqlColumnTypeTest extends TestCase
{
    public function setUp(): void
    {
        if (!class_exists('GeneratedQueryUuidMysqlEntityQuery')) {
            $schema = <<<EOF
<database name="generated_query_uuid_mysql_type_test">
    <table name="generated_query_uuid_mysql_entity">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
        <column name="uuid" type="UUID"/>
    </table>
</database>
EOF;
            $builder = new QuickBuilder();
            $builder->setPlatform(new MysqlPlatform());
            $builder->setSchema($schema);
            $builder->buildClasses();
        }

        Propel::getServiceContainer()->setAdapter('generated_query_uuid_mysql_type_test', new MysqlAdapter());
    }

    public function testFilterByUuidUsesUnswappedBinaryByDefault(): void
    {
        $uuid = '0197702f-8b6c-73d0-9f02-32594f9c6a2a';
        $params = [];
        $queryClass = 'GeneratedQueryUuidMysqlEntityQuery';

        $queryClass::create()->filterByUuid($uuid)->createSelectSql($params);

        $this->assertCount(1, $params);
        $this->assertSame(UuidConverter::uuidToBin($uuid, false), $params[0]['value']);
    }

    public function testFilterByUuidArrayDefaultsToInComparison(): void
    {
        $uuid1 = '0197702f-8b6c-73d0-9f02-32594f9c6a2a';
        $uuid2 = '0197702f-8b6c-73d0-9f02-32594f9c6a2b';
        $params = [];
        $queryClass = 'GeneratedQueryUuidMysqlEntityQuery';

        $sql = $queryClass::create()->filterByUuid([$uuid1, $uuid2])->createSelectSql($params);

        $this->assertStringContainsString(' IN ', $sql);
        $this->assertCount(2, $params);
        $this->assertSame(UuidConverter::uuidToBin($uuid1, false), $params[0]['value']);
        $this->assertSame(UuidConverter::uuidToBin($uuid2, false), $params[1]['value']);
    }
}
