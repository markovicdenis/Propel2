<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Builder\Om;

use PHPUnit\Framework\TestCase;
use Propel\Generator\Platform\PgsqlPlatform;
use Propel\Generator\Util\QuickBuilder;
use Propel\Runtime\Adapter\Pdo\PgsqlAdapter;
use Propel\Runtime\Propel;
use Symfony\Component\Uid\UuidV7;

class GeneratedQueryUidPgsqlColumnTypeTest extends TestCase
{
    public function setUp(): void
    {
        if (!class_exists('GeneratedQueryUidPgsqlEntityQuery')) {
            $schema = <<<EOF
<database name="generated_query_uid_pgsql_type_test">
    <table name="generated_query_uid_pgsql_entity">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
        <column name="uid_binary" type="UID_BINARY"/>
        <column name="uid" type="UID"/>
    </table>
</database>
EOF;
            $builder = new QuickBuilder();
            $builder->setPlatform(new PgsqlPlatform());
            $builder->setSchema($schema);
            $builder->buildClasses();
        }

        Propel::getStandardServiceContainer()->setAdapter('generated_query_uid_pgsql_type_test', new PgsqlAdapter());
    }

    public function testFilterByUidBinaryUsesStringBinding(): void
    {
        $queryClass = 'GeneratedQueryUidPgsqlEntityQuery';
        $params = [];
        $uid = UuidV7::fromString('0197702f-8b6c-73d0-9f02-32594f9c6a2a');

        $queryClass::create()->filterByUidBinary($uid)->createSelectSql($params);

        $this->assertCount(1, $params);
        $this->assertSame($uid->toRfc4122(), $params[0]['value']);
    }
}
