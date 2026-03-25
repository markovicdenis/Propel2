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
        $foo = new QuickBuildFoo2();
        $foo->setBar(3);

        $this->assertSame(3, $foo->getBar());
        $this->assertFalse(method_exists($foo, 'tryGetId'));
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
        $foo = new QuickBuildFoo3();
        $foo->setBar(3);

        $this->assertSame(3, $foo->getBar());
        $this->assertFalse(method_exists($foo, 'tryGetId'));

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

        $this->assertSame(0, $foo->getId());
        $this->assertFalse(method_exists($foo, 'tryGetId'));

        $foo->setBar(3);

        $this->assertSame(3, $foo->getBar());
        $this->assertSame(0, $foo->getId());
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
        $this->assertFalse(method_exists($foo, 'tryGetId'));
        $this->assertSame(7, $foo->getBar());
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
        <foreign-key foreignTable="quick_build_file_6" phpName="IdentityFile">
            <reference local="identity_file_id" foreign="id"/>
        </foreign-key>
        <foreign-key foreignTable="quick_build_file_6" phpName="AddressFile">
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
        $verification = new $verificationClass();
        $this->assertTrue(method_exists($verification, 'setIdentityFile'));
        $this->assertTrue(method_exists($verification, 'setAddressFile'));
        $this->assertTrue(method_exists($verification, 'getIdentityFile'));
        $this->assertTrue(method_exists($verification, 'getAddressFile'));

        $verification->setIdentityFileId(10);
        $verification->setAddressFileId(20);
        $this->assertSame(10, $verification->getIdentityFileId());
        $this->assertSame(20, $verification->getAddressFileId());
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
        $group->setId(7);

        $profile = new $profileClass();
        $profile->setId($group->getId());
        $profile->setLabel('profile');

        $group->setQuickBuildProfile7($profile);

        $this->assertSame($group->getId(), $profile->getId());
        $this->assertSame($profile, $group->getQuickBuildProfile7());
        $this->assertSame('profile', $group->getQuickBuildProfile7()->getLabel());
    }

    public function testGeneratedRequiredForeignKeyAccessorsUseTryGetterAtRuntime(): void
    {
        $xmlSchema = <<<EOF
<database name="test_quick_build_8" namespace="MyNameSpace8">
    <table name="quick_build_group_8">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" required="true"/>
        <column name="name" type="VARCHAR" required="true"/>
    </table>
    <table name="quick_build_member_8">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" required="true"/>
        <column name="group_id" type="INTEGER" required="true"/>
        <column name="label" type="VARCHAR" required="true"/>
        <foreign-key foreignTable="quick_build_group_8">
            <reference local="group_id" foreign="id"/>
        </foreign-key>
    </table>
</database>
EOF;
        $builder = new QuickBuilder();
        $builder->setSchema($xmlSchema);
        $builder->build();

        $groupClass = '\\MyNameSpace8\\QuickBuildGroup8';
        $memberClass = '\\MyNameSpace8\\QuickBuildMember8';
        $memberQueryClass = '\\MyNameSpace8\\QuickBuildMember8Query';

        $group = new $groupClass();
        $group->setName('group');
        $group->setId(8);

        $member = new $memberClass();
        $member->setLabel('member');

        $this->assertSame(0, $member->getGroupId());
        $this->assertFalse(method_exists($member, 'tryGetGroupId'));

        $member->setGroupId($group->getId());

        $this->assertSame($group->getId(), $member->getGroupId());
    }

    public function testGeneratedRequiredRelationAccessorsUseTryGetterAtRuntime(): void
    {
        $xmlSchema = <<<EOF
<database name="test_quick_build_9" namespace="MyNameSpace9">
    <table name="quick_build_group_9">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" required="true"/>
        <column name="name" type="VARCHAR" required="true"/>
    </table>
    <table name="quick_build_member_9">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true" required="true"/>
        <column name="group_id" type="INTEGER" required="true"/>
        <column name="label" type="VARCHAR" required="true"/>
        <foreign-key foreignTable="quick_build_group_9">
            <reference local="group_id" foreign="id"/>
        </foreign-key>
    </table>
</database>
EOF;
        $builder = new QuickBuilder();
        $builder->setSchema($xmlSchema);
        $builder->build();

        $memberClass = '\\MyNameSpace9\\QuickBuildMember9';

        $member = new $memberClass();
        $member->setLabel('member');

        $this->assertNull($member->tryGetQuickBuildGroup9());

        try {
            $member->getQuickBuildGroup9();
            $this->fail('Expected getQuickBuildGroup9() to throw when the required relation is unresolved.');
        } catch (PropelException $exception) {
            $this->assertSame(
                'Cannot return a null related object from getQuickBuildGroup9() because the relation is required.',
                $exception->getMessage()
            );
        }

        $this->assertTrue(method_exists($member, 'setQuickBuildGroup9'));
    }
}
