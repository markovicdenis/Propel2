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
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

class GeneratedQueryUidMysqlColumnTypeTest extends TestCase
{
    public function setUp(): void
    {
        if (!class_exists('GeneratedQueryUidMysqlEntityQuery')) {
            $schema = <<<EOF
<database name="generated_query_uid_mysql_type_test">
    <table name="generated_query_uid_mysql_entity">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
        <column name="uid_binary" type="UID_BINARY"/>
        <column name="uid" type="UID"/>
    </table>
</database>
EOF;
            $builder = new QuickBuilder();
            $builder->setPlatform(new MysqlPlatform());
            $builder->setSchema($schema);
            $builder->buildClasses();
        }

        Propel::getStandardServiceContainer()->setAdapter('generated_query_uid_mysql_type_test', new MysqlAdapter());
    }

    public function testFilterByUidBinaryUsesBinaryBinding(): void
    {
        $queryClass = 'GeneratedQueryUidMysqlEntityQuery';
        $params = [];
        $uid = '0197702f-8b6c-73d0-9f02-32594f9c6a2a';

        $queryClass::create()->filterByUidBinary($uid)->createSelectSql($params);

        $this->assertCount(1, $params);
        $this->assertSame(UuidConverter::uidToBin($uid), $params[0]['value']);
    }

    public function testFilterByUidBinaryArrayDefaultsToInComparison(): void
    {
        $queryClass = 'GeneratedQueryUidMysqlEntityQuery';
        $params = [];
        $uid1 = '0197702f-8b6c-73d0-9f02-32594f9c6a2a';
        $uid2 = Uuid::fromString('0197702f-8b6c-73d0-9f02-32594f9c6a2b');

        $sql = $queryClass::create()->filterByUidBinary([$uid1, $uid2])->createSelectSql($params);

        $this->assertStringContainsString(' IN ', $sql);
        $this->assertCount(2, $params);
        $this->assertSame(UuidConverter::uidToBin($uid1), $params[0]['value']);
        $this->assertSame(UuidConverter::uidToBin($uid2), $params[1]['value']);
    }

    public function testFilterByUidUsesStringBinding(): void
    {
        $queryClass = 'GeneratedQueryUidMysqlEntityQuery';
        $params = [];
        $uid = UuidV7::fromString('0197702f-8b6c-73d0-9f02-32594f9c6a2b');

        $queryClass::create()->filterByUid($uid)->createSelectSql($params);

        $this->assertCount(1, $params);
        $this->assertSame($uid->toRfc4122(), $params[0]['value']);
    }

    public function testGeneratedUidQueryMethodsUseBroaderUuidInputTypes(): void
    {
        $schema = <<<EOF
<database name="generated_query_uid_mysql_doc_test">
    <table name="generated_query_uid_mysql_doc_entity">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
        <column name="uid_binary" type="UID_BINARY"/>
    </table>
</database>
EOF;
        $builder = new QuickBuilder();
        $builder->setPlatform(new MysqlPlatform());
        $builder->setSchema($schema);

        $classes = $builder->getClasses();

        $this->assertStringContainsString('use Symfony\Component\Uid\Uuid;', $classes);
        $this->assertStringContainsString('findOneByUidBinary(Uuid|string $uid_binary)', $classes);
        $this->assertStringContainsString('requireOneByUidBinary(Uuid|string $uid_binary)', $classes);
        $this->assertStringContainsString('findByUidBinary(Uuid|string|array<Uuid|string> $uid_binary)', $classes);
        $this->assertStringContainsString('@param Uuid|string|array<Uuid|string>|null $uidBinary', $classes);
        $this->assertStringContainsString('public function filterByUidBinary(Uuid|string|array|null $uidBinary = null, ?string $comparison = null)', $classes);
    }

    public function testMysqlGeneratedClassesAssignV7UidToPrimaryKey(): void
    {
        $schema = <<<EOF
<database name="generated_query_uid_mysql_generation_test">
    <table name="generated_query_uid_mysql_generation_entity">
        <column name="id" primaryKey="true" type="UID_BINARY"/>
        <column name="title" type="VARCHAR"/>
    </table>
</database>
EOF;
        $builder = new QuickBuilder();
        $builder->setPlatform(new MysqlPlatform());
        $builder->setSchema($schema);

        $classes = $builder->getClasses();

        $this->assertStringContainsString('$this->id = UuidConverter::generateV7Uid(null);', $classes);
    }

    public function testMysqlGeneratedClassesUseCreatedAtForTimestampableUidPrimaryKey(): void
    {
        $schema = <<<EOF
<database name="generated_query_uid_mysql_generation_test">
    <table name="generated_query_uid_mysql_generation_entity">
        <behavior name="timestampable"/>
        <column name="id" primaryKey="true" type="UID_BINARY"/>
        <column name="title" type="VARCHAR"/>
    </table>
</database>
EOF;
        $builder = new QuickBuilder();
        $builder->setPlatform(new MysqlPlatform());
        $builder->setSchema($schema);

        $classes = $builder->getClasses();

        $this->assertStringContainsString('$this->id = UuidConverter::generateV7Uid($this->created_at);', $classes);
    }
}
