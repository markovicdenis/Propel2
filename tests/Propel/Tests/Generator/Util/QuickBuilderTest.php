<?php

declare(strict_types=1);

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use MyNameSpace\Map\QuickBuildFoo1TableMap;
use MyNameSpace\QuickBuildFoo1;
use MyNameSpace2\QuickBuildFoo2;
use MyNameSpace2\QuickBuildFoo2Query;
use MyNameSpace3\QuickBuildFoo3;
use MyNameSpace3\QuickBuildFoo3Query;
use Propel\Generator\Platform\MysqlPlatform;
use Propel\Generator\Platform\SqlitePlatform;
use Propel\Generator\Util\QuickBuilder;
use Propel\Runtime\ActiveRecord\ActiveRecordInterface;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Propel;
use Propel\Tests\TestCase;

use function count;

class QuickBuilderTest extends TestCase
{
    /**
     * @return void
     */
    public function testGetPlatform(): void
    {
        $builder = new QuickBuilder();
        $builder->setPlatform(new MysqlPlatform());
        $this->assertTrue($builder->getPlatform() instanceof MysqlPlatform);
        $builder = new QuickBuilder();
        $this->assertTrue($builder->getPlatform() instanceof SqlitePlatform);
    }

    public static function simpleSchemaProvider(): array
    {
        $xmlSchema = <<<EOF
<database name="test_quick_build_2" namespace="MyNameSpace">
    <table name="quick_build_foo_1">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
        <column name="bar" type="INTEGER"/>
    </table>
</database>
EOF;
        $builder = new QuickBuilder();
        $builder->setSchema($xmlSchema);

        return [[$builder]];
    }

    /**
     * @dataProvider simpleSchemaProvider
     *
     * @return void
     */
    #[DataProvider('simpleSchemaProvider')]
    public function testGetDatabase($builder): void
    {
        $database = $builder->getDatabase();
        $this->assertEquals('test_quick_build_2', $database->getName());
        $this->assertEquals(1, count($database->getTables()));
        $this->assertEquals(2, count($database->getTable('quick_build_foo_1')->getColumns()));
    }

    /**
     * @dataProvider simpleSchemaProvider
     *
     * @return void
     */
    #[DataProvider('simpleSchemaProvider')]
    public function testGetSQL($builder): void
    {
        $expected = <<<EOF

-----------------------------------------------------------------------
-- quick_build_foo_1
-----------------------------------------------------------------------

DROP TABLE IF EXISTS quick_build_foo_1;

CREATE TABLE quick_build_foo_1
(
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    bar INTEGER,
    UNIQUE (id)
);

EOF;
        $this->assertEquals($expected, $builder->getSQL());
    }

    /**
     * @dataProvider simpleSchemaProvider
     *
     * @return void
     */
    #[DataProvider('simpleSchemaProvider')]
    public function testGetClasses($builder): void
    {
        $script = $builder->getClasses();
        $this->assertStringContainsString('class QuickBuildFoo1 extends BaseQuickBuildFoo1', $script);
        $this->assertStringContainsString('class QuickBuildFoo1Query extends BaseQuickBuildFoo1Query', $script);
        $this->assertStringContainsString('class QuickBuildFoo1 implements ActiveRecordInterface', $script);
        $this->assertStringContainsString('class QuickBuildFoo1Query extends ModelCriteria', $script);
    }

    /**
     * @dataProvider simpleSchemaProvider
     *
     * @return void
     */
    #[DataProvider('simpleSchemaProvider')]
    public function testGetClassesLimitedClassTargets($builder): void
    {
        $script = $builder->getClasses(['tablemap', 'object', 'query']);
        $this->assertStringNotContainsString('class QuickBuildFoo1 extends BaseQuickBuildFoo1', $script);
        $this->assertStringNotContainsString('class QuickBuildFoo1Query extends BaseQuickBuildFoo1Query', $script);
        $this->assertStringContainsString('class QuickBuildFoo1 implements ActiveRecordInterface', $script);
        $this->assertStringContainsString('class QuickBuildFoo1Query extends ModelCriteria', $script);
    }

    /**
     * @dataProvider simpleSchemaProvider
     *
     * @return void
     */
    #[DataProvider('simpleSchemaProvider')]
    public function testBuildClasses($builder): void
    {
        $builder->buildClasses();
        $foo = new QuickBuildFoo1();
        $this->assertTrue($foo instanceof ActiveRecordInterface);
        $this->assertTrue(QuickBuildFoo1TableMap::getTableMap() instanceof QuickBuildFoo1TableMap);
    }

    /**
     * @return void
     */
    public function testBuild(): void
    {
        $xmlSchema = <<<EOF
<database name="test_quick_build_2" namespace="MyNameSpace2">
    <table name="quick_build_foo_2">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
        <column name="bar" type="INTEGER"/>
    </table>
</database>
EOF;
        $builder = new QuickBuilder();
        $builder->setSchema($xmlSchema);
        $builder->build();
        $this->assertEquals(0, QuickBuildFoo2Query::create()->count());
        $foo = new QuickBuildFoo2();
        $foo->setBar(3);
        $foo->save();
        $this->assertEquals(1, QuickBuildFoo2Query::create()->count());
        $this->assertEquals($foo, QuickBuildFoo2Query::create()->findOne());
    }

    public function testBuildOnPhysicalFilesystem(): void
    {
        $xmlSchema = <<<EOF
<database name="test_quick_build_3" namespace="MyNameSpace3">
    <table name="quick_build_foo_3">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
        <column name="bar" type="INTEGER"/>
    </table>
</database>
EOF;
        $builder = new QuickBuilder();
        $builder->setSchema($xmlSchema);
        $builder->setVfs(false);
        $builder->build();
        $this->assertEquals(0, QuickBuildFoo3Query::create()->count());
        $foo = new QuickBuildFoo3();
        $foo->setBar(3);
        $foo->save();
        $this->assertEquals(1, QuickBuildFoo3Query::create()->count());
        $this->assertEquals($foo, QuickBuildFoo3Query::create()->findOne());

        $this->assertDirectoryExists(
            sys_get_temp_dir() . '/propelQuickBuild-' . Propel::VERSION . '-' . substr(sha1(getcwd()), 0, 10)
        );
    }

    public function testGeneratedCrudWithAutoIncrementPrimaryKeyAccessors(): void
    {
        $xmlSchema = <<<EOF
<database name="test_quick_build_4" namespace="MyNameSpace4">
    <table name="quick_build_foo_4">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" required="true"/>
        <column name="bar" type="INTEGER"/>
    </table>
</database>
EOF;
        $builder = new QuickBuilder();
        $builder->setSchema($xmlSchema);
        $builder->build();

        $foo = new \MyNameSpace4\QuickBuildFoo4();

        $this->assertNull($foo->tryGetId());
        $this->assertNull($foo->getPrimaryKey());
        $this->assertTrue($foo->isPrimaryKeyNull());

        try {
            $foo->getId();
            $this->fail('Expected getId() to throw when the auto-increment primary key is unset.');
        } catch (PropelException $exception) {
            $this->assertSame(
                'Cannot return a null primary key from getId(). Use tryGetId() if you need the nullable value.',
                $exception->getMessage()
            );
        }

        $foo->setBar(3);
        $foo->save();

        $id = $foo->getId();
        $this->assertIsInt($id);
        $this->assertSame($id, $foo->tryGetId());
        $this->assertSame($id, $foo->getPrimaryKey());
        $this->assertFalse($foo->isPrimaryKeyNull());

        $found = \MyNameSpace4\QuickBuildFoo4Query::create()->findPk($id);
        $this->assertNotNull($found);
        $this->assertSame($id, $found->getId());
        $this->assertSame(3, $found->getBar());

        $found->setBar(5);
        $found->save();
        $this->assertSame($id, $found->getId());

        $found->reload();
        $this->assertSame($id, $found->getId());
        $this->assertSame(5, $found->getBar());

        $found->delete();
        $this->assertSame(0, \MyNameSpace4\QuickBuildFoo4Query::create()->count());
        $this->assertSame($id, $found->getId());
        $this->assertSame($id, $found->tryGetId());
    }

    public function testGeneratedCrudWithAssignedPrimaryKeyAccessors(): void
    {
        $xmlSchema = <<<EOF
<database name="test_quick_build_5" namespace="MyNameSpace5">
    <table name="quick_build_foo_5">
        <column name="id" primaryKey="true" type="INTEGER" required="true"/>
        <column name="bar" type="INTEGER"/>
    </table>
</database>
EOF;
        $builder = new QuickBuilder();
        $builder->setSchema($xmlSchema);
        $builder->build();

        $foo = new \MyNameSpace5\QuickBuildFoo5();
        $foo->setId(42);
        $foo->setBar(7);

        $this->assertSame(42, $foo->getId());
        $this->assertSame(42, $foo->tryGetId());
        $this->assertSame(42, $foo->getPrimaryKey());
        $this->assertFalse($foo->isPrimaryKeyNull());

        $foo->save();

        $found = \MyNameSpace5\QuickBuildFoo5Query::create()->findPk(42);
        $this->assertNotNull($found);
        $this->assertSame(42, $found->getId());
        $this->assertSame(7, $found->getBar());

        $found->setBar(9);
        $found->save();
        $found->reload();

        $this->assertSame(42, $found->getId());
        $this->assertSame(9, $found->getBar());

        $found->delete();
        $this->assertSame(0, \MyNameSpace5\QuickBuildFoo5Query::create()->count());
        $this->assertSame(42, $found->getId());
        $this->assertSame(42, $found->tryGetId());
    }

    public function testGeneratedRelationSettersWorkWithMultipleForeignKeysToSameTable(): void
    {
        $xmlSchema = <<<EOF
<database name="test_quick_build_6" namespace="MyNameSpace6">
    <table name="quick_build_file_6">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" required="true"/>
        <column name="name" type="VARCHAR" required="true"/>
    </table>
    <table name="quick_build_verification_6">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" required="true"/>
        <column name="identity_file_id" type="INTEGER"/>
        <column name="address_file_id" type="INTEGER"/>
        <foreign-key foreignTable="quick_build_file_6">
            <reference local="identity_file_id" foreign="id"/>
        </foreign-key>
        <foreign-key foreignTable="quick_build_file_6">
            <reference local="address_file_id" foreign="id"/>
        </foreign-key>
    </table>
</database>
EOF;
        $builder = new QuickBuilder();
        $builder->setSchema($xmlSchema);
        $builder->build();

        $fileClass = '\\MyNameSpace6\\QuickBuildFile6';
        $verificationClass = '\\MyNameSpace6\\QuickBuildVerification6';
        $verificationQueryClass = '\\MyNameSpace6\\QuickBuildVerification6Query';

        $identityFile = new $fileClass();
        $identityFile->setName('identity');

        $addressFile = new $fileClass();
        $addressFile->setName('address');

        $verification = new $verificationClass();
        $verification->setQuickBuildFile6RelatedByIdentityFileId($identityFile);
        $verification->setQuickBuildFile6RelatedByAddressFileId($addressFile);

        $identityRelatedMethod = 'getQuickBuildVerification6sRelatedByIdentityFileId';
        $addressRelatedMethod = 'getQuickBuildVerification6sRelatedByAddressFileId';

        $this->assertCount(1, $identityFile->{$identityRelatedMethod}());
        $this->assertCount(1, $addressFile->{$addressRelatedMethod}());
        $this->assertSame($verification, $identityFile->{$identityRelatedMethod}()->getFirst());
        $this->assertSame($verification, $addressFile->{$addressRelatedMethod}()->getFirst());

        $identityFile->save();
        $addressFile->save();

        $verificationId = $verification->getId();
        $this->assertSame($identityFile->getId(), $verification->getIdentityFileId());
        $this->assertSame($addressFile->getId(), $verification->getAddressFileId());

        $reloaded = ($verificationQueryClass)::create()->findPk($verificationId);
        $this->assertNotNull($reloaded);
        $this->assertSame($identityFile->getId(), $reloaded->getIdentityFileId());
        $this->assertSame($addressFile->getId(), $reloaded->getAddressFileId());

        $reloaded->setQuickBuildFile6RelatedByIdentityFileId(null);
        $reloaded->save();
        $this->assertNull($reloaded->getIdentityFileId());
    }

    public function testGeneratedOneToOneRelationSetterWorksAtRuntime(): void
    {
        $xmlSchema = <<<EOF
<database name="test_quick_build_7" namespace="MyNameSpace7">
    <table name="quick_build_group_7">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" required="true"/>
        <column name="name" type="VARCHAR" required="true"/>
    </table>
    <table name="quick_build_profile_7">
        <column name="id" primaryKey="true" type="INTEGER" required="true"/>
        <column name="label" type="VARCHAR" required="true"/>
        <foreign-key foreignTable="quick_build_group_7">
            <reference local="id" foreign="id"/>
        </foreign-key>
    </table>
</database>
EOF;
        $builder = new QuickBuilder();
        $builder->setSchema($xmlSchema);
        $builder->build();

        $groupClass = '\\MyNameSpace7\\QuickBuildGroup7';
        $profileClass = '\\MyNameSpace7\\QuickBuildProfile7';
        $groupQueryClass = '\\MyNameSpace7\\QuickBuildGroup7Query';

        $group = new $groupClass();
        $group->setName('group');
        $group->save();

        $profile = new $profileClass();
        $profile->setId($group->getId());
        $profile->setLabel('profile');

        $group->setQuickBuildProfile7($profile);
        $profile->save();

        $this->assertSame($group->getId(), $profile->getId());
        $this->assertSame($profile, $group->getQuickBuildProfile7());

        $reloadedGroup = ($groupQueryClass)::create()->findPk($group->getId());
        $this->assertNotNull($reloadedGroup);
        $this->assertNotNull($reloadedGroup->getQuickBuildProfile7());
        $this->assertSame('profile', $reloadedGroup->getQuickBuildProfile7()->getLabel());
    }
}
