<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Builder\Om;

use Propel\Generator\Util\QuickBuilder;
use Propel\Tests\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

class GeneratedObjectUidColumnTypeTest extends TestCase
{
    public function setUp(): void
    {
        if (!class_exists('GeneratedObjectUidEntity')) {
            $schema = <<<EOF
<database name="generated_object_uid_type_test">
    <table name="generated_object_uid_entity">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
        <column name="uid_binary" type="UID_BINARY"/>
        <column name="uid" type="UID"/>
    </table>
</database>
EOF;
            QuickBuilder::buildSchema($schema);
        }
    }

    public function testSetterNormalizesStringToUidObjects(): void
    {
        $entityClass = 'GeneratedObjectUidEntity';
        $entity = new $entityClass();

        $entity->setUidBinary('0197702f-8b6c-73d0-9f02-32594f9c6a2a');
        $entity->setUid('0197702f-8b6c-73d0-9f02-32594f9c6a2b');

        $this->assertInstanceOf(UuidV7::class, $entity->getUidBinary());
        $this->assertInstanceOf(UuidV7::class, $entity->getUid());
        $this->assertSame('0197702f-8b6c-73d0-9f02-32594f9c6a2a', $entity->getUidBinary()->toRfc4122());
        $this->assertSame('0197702f-8b6c-73d0-9f02-32594f9c6a2b', $entity->getUid()->toRfc4122());
    }

    public function testUidValuesRoundTripThroughStorage(): void
    {
        $entityClass = 'GeneratedObjectUidEntity';
        $queryClass = 'GeneratedObjectUidEntityQuery';
        $tableMapClass = 'Map\\GeneratedObjectUidEntityTableMap';
        $uidBinary = UuidV7::fromString('0197702f-8b6c-73d0-9f02-32594f9c6a2a');
        $uid = UuidV7::fromString('0197702f-8b6c-73d0-9f02-32594f9c6a2b');

        $entity = new $entityClass();
        $entity->setUidBinary($uidBinary);
        $entity->setUid($uid);
        $entity->save();

        $tableMapClass::clearInstancePool();

        $reloaded = $queryClass::create()->findPk($entity->getId());

        $this->assertInstanceOf(UuidV7::class, $reloaded->getUidBinary());
        $this->assertInstanceOf(UuidV7::class, $reloaded->getUid());
        $this->assertTrue($reloaded->getUidBinary()->equals($uidBinary));
        $this->assertTrue($reloaded->getUid()->equals($uid));
    }
}