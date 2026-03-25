<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Builder\Om;

use Propel\Generator\Builder\Om\ObjectBuilder;
use Propel\Generator\Config\QuickGeneratorConfig;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\ColumnDefaultValue;
use Propel\Generator\Model\Database;
use Propel\Generator\Model\Domain;
use Propel\Generator\Model\Table;
use Propel\Generator\Platform\MysqlPlatform;
use Propel\Tests\TestCase;

/**
 * Test class for ObjectBuilder.
 *
 * @author François Zaninotto
 * @version $Id$
 */
class ObjectBuilderTest extends TestCase
{
    protected $builder;

    /**
     * @return void
     */
    public function setUp(): void
    {
        $builder = new TestableObjectBuilder(new Table('Foo'));
        $builder->setPlatform(new MysqlPlatform());
        $this->builder = $builder;
    }

    public static function getDefaultValueStringProvider()
    {
        $col1 = new Column('Bar');
        $col1->setDomain(new Domain('VARCHAR'));
        $col1->setDefaultValue(new ColumnDefaultValue('abc', ColumnDefaultValue::TYPE_VALUE));
        $val1 = "'abc'";
        $col2 = new Column('Bar');
        $col2->setDomain(new Domain('INTEGER'));
        $col2->setDefaultValue(new ColumnDefaultValue(1234, ColumnDefaultValue::TYPE_VALUE));
        $val2 = '1234';
        $col3 = new Column('Bar');
        $col3->setDomain(new Domain('DATE'));
        $col3->setDefaultValue(new ColumnDefaultValue('0000-00-00', ColumnDefaultValue::TYPE_VALUE));
        $val3 = 'null';

        return [
            [$col1, $val1],
            [$col2, $val2],
            [$col3, $val3],
        ];
    }

    /**
     * @dataProvider getDefaultValueStringProvider
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('getDefaultValueStringProvider')]
    public function testGetDefaultValueString($column, $value)
    {
        $this->assertEquals($value, $this->builder->getDefaultValueString($column));
    }

    /**
     * @return void
     */
    public function testGetDefaultKeyType()
    {
        $this->assertEquals('TYPE_PHPNAME', $this->builder->getDefaultKeyType());
    }

    /**
     * @return void
     */
    public function testBaseObjectMethodsUseSharedTrait()
    {
        $classBodyStart = '';
        $this->builder->addCommonTraitUsesToScript($classBodyStart);

        $this->assertStringContainsString('use ActiveRecordCommonTrait, ActiveRecordHydrationTrait;', $classBodyStart);
        $this->assertStringStartsWith('use ActiveRecordCommonTrait, ActiveRecordHydrationTrait;', ltrim($classBodyStart));

        $baseObjectMethods = '';
        $this->builder->addBaseObjectMethodsToScript($baseObjectMethods);

        $this->assertStringNotContainsString('use ActiveRecordCommonTrait;', $baseObjectMethods);
        $this->assertStringNotContainsString('public function resetModified(?string $col = null): void', $baseObjectMethods);
        $this->assertStringNotContainsString('public function equals($obj): bool', $baseObjectMethods);
        $this->assertStringNotContainsString('public function getVirtualColumns(): array', $baseObjectMethods);
        $this->assertStringNotContainsString('public function exportTo($parser, bool $includeLazyLoadColumns = true, string $keyType = TableMap::TYPE_PHPNAME): string', $baseObjectMethods);
        $this->assertStringContainsString('public function __sleep(): array', $baseObjectMethods);
    }

    /**
     * @return void
     */
    public function testHydrateUsesResolveFromRowForSimpleColumnsOnly()
    {
        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setNotNull(true);

        $title = new Column('title');
        $title->setDomain(new Domain('VARCHAR'));

        $createdAt = new Column('created_at');
        $createdAt->setDomain(new Domain('TIMESTAMP'));

        $this->assertSame(
            '$this->resolveFromRow($row, 0, $startcol, $indexType, fn ($v) => null !== $v ? (int) $v : null)',
            $this->builder->getResolveFromRowExpressionForColumn($id, 0)
        );
        $this->assertSame(
            '$this->resolveFromRow($row, 1, $startcol, $indexType, fn ($v) => null !== $v ? (string) $v : null)',
            $this->builder->getResolveFromRowExpressionForColumn($title, 1)
        );
        $this->assertNull($this->builder->getResolveFromRowExpressionForColumn($createdAt, 2));
    }

    /**
     * @return void
     */
    public function testAddSetByPositionUsesMatchExpression()
    {
        $table = new Table('Foo');

        $firstName = new Column('first_name');
        $firstName->setDomain(new Domain('VARCHAR'));
        $table->addColumn($firstName);

        $lastName = new Column('last_name');
        $lastName->setDomain(new Domain('VARCHAR'));
        $table->addColumn($lastName);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addSetByPositionToScript($script);

        $this->assertStringContainsString('match ($pos)', $script);
        $this->assertStringContainsString('0 => $this->setFirstName($value),', $script);
        $this->assertStringContainsString('1 => $this->setLastName($value),', $script);
        $this->assertStringContainsString('default => null', $script);
        $this->assertStringNotContainsString('switch ($pos)', $script);
    }

    /**
     * @return void
     */
    public function testBuildCriteriaUsesAllColumnsLoop()
    {
        $table = new Table('Foo');

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $table->addColumn($id);

        $promotionName = new Column('promotion_name');
        $promotionName->setDomain(new Domain('VARCHAR'));
        $table->addColumn($promotionName);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addBuildCriteriaToScript($script);

        $this->assertStringContainsString('foreach (FooTableMap::ALL_COLUMNS as $columnConstant)', $script);
        $this->assertStringContainsString('$propertyName = FooTableMap::getPropertyName($columnConstant);', $script);
        $this->assertStringContainsString('$criteria->add($columnConstant, $this->{$propertyName});', $script);
        $this->assertStringNotContainsString('if ($this->isColumnModified(FooTableMap::COL_ID)) {', $script);
        $this->assertStringNotContainsString('match ($position)', $script);
    }

    /**
     * @return void
     */
    public function testDoInsertUsesInsertColumnBindingDtos()
    {
        $table = new Table('Foo');

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $id->setAutoIncrement(true);
        $table->addColumn($id);

        $name = new Column('name');
        $name->setDomain(new Domain('VARCHAR'));
        $table->addColumn($name);

        $createdAt = new Column('created_at');
        $createdAt->setDomain(new Domain('TIMESTAMP'));
        $table->addColumn($createdAt);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = $builder->addDoInsertToScript();

        $this->assertStringContainsString('/** @var list<InsertColumnBindingDto> $columnBindings */', $script);
        $this->assertStringContainsString('$columnBindings[] = new InsertColumnBindingDto(\':p\'.$index++, \'id\', $this->id, PDO::PARAM_INT);', $script);
        $this->assertStringContainsString('$columnBindings[] = new InsertColumnBindingDto(\':p\'.$index++, \'name\', $this->name, PDO::PARAM_STR);', $script);
        $this->assertStringContainsString('$columnBindings[] = new InsertColumnBindingDto(\':p\'.$index++, \'created_at\', $this->created_at ? $this->created_at->format(\'Y-m-d H:i:s.u\') : null, PDO::PARAM_STR);', $script);
        $this->assertStringContainsString('array_map(static fn (InsertColumnBindingDto $binding): string => $binding->quotedColumnName, $columnBindings)', $script);
        $this->assertStringContainsString('array_map(static fn (InsertColumnBindingDto $binding): string => $binding->identifier, $columnBindings)', $script);
        $this->assertStringContainsString('$binding->bind($stmt);', $script);
        $this->assertStringNotContainsString('match ($columnName)', $script);
    }

    /**
     * @return void
     */
    public function testDefaultMutatorUsesSharedCastHelper()
    {
        $table = new Table('Foo');

        $resolution = new Column('resolution');
        $resolution->setDomain(new Domain('VARCHAR'));
        $table->addColumn($resolution);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addDefaultMutatorToScript($script, $resolution);

        $this->assertStringContainsString("\$v = \$this->castTo(\$v, 'string', true);", $script);
        $this->assertStringNotContainsString("\$v = (string) \$v;", $script);
    }

    /**
     * @return void
     */
    public function testReadOnlyModelsDoNotGenerateCopyMethods()
    {
        $database = new Database('test');

        $table = new Table('ReadOnlyThing');
        $table->setReadOnly(true);
        $database->addTable($table);

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $table->addColumn($id);

        $name = new Column('name');
        $name->setDomain(new Domain('VARCHAR'));
        $table->addColumn($name);

        $builder = new TestableObjectBuilder($table);
        $builder->setGeneratorConfig(new QuickGeneratorConfig());
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addClassBodyToScript($script);

        $this->assertStringNotContainsString('public function copy(bool $deepCopy = false)', $script);
        $this->assertStringNotContainsString('public function copyInto(object $copyObj, bool $deepCopy = false, bool $makeNew = true): void', $script);
    }

    /**
     * @return void
     */
    public function testReadOnlyModelsDoNotRouteMagicFromCallsToImportFrom()
    {
        $database = new Database('test');

        $table = new Table('ReadOnlyThing');
        $table->setReadOnly(true);
        $database->addTable($table);

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $table->addColumn($id);

        $builder = new TestableObjectBuilder($table);
        $builder->setGeneratorConfig(new QuickGeneratorConfig());
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addClassBodyToScript($script);

        $this->assertStringNotContainsString('return $this->importFrom($format, $inputData, $keyType);', $script);
        $this->assertStringContainsString('return $this->exportTo($format, $includeLazyLoadColumns, $keyType);', $script);
    }

    /**
     * @return void
     */
    public function testModelsWithoutGenericAccessorsDoNotRouteMagicToCallsToExportTo()
    {
        $database = new Database('test');

        $table = new Table('Thing');
        $database->addTable($table);

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $table->addColumn($id);

        $builder = new TestableObjectBuilder($table);
        $builder->setGeneratorConfig(new QuickGeneratorConfig([
            'propel' => [
                'generator' => [
                    'objectModel' => [
                        'addGenericAccessors' => false,
                    ],
                ],
            ],
        ]));
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addClassBodyToScript($script);

        $this->assertStringNotContainsString('return $this->exportTo($format, $includeLazyLoadColumns, $keyType);', $script);
        $this->assertStringContainsString('return $this->importFrom($format, $inputData, $keyType);', $script);
    }

    /**
     * @return void
     */
    public function testHashCodeUsesTraitPrimaryKeyValidatorForSinglePrimaryKey()
    {
        $table = new Table('Author');

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $id->setAutoIncrement(true);
        $table->addColumn($id);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addHashCodeToScript($script);

        $this->assertStringContainsString('$validPk = $this->validatePrimaryKey($this->getPrimaryKey(), null);', $script);
        $this->assertStringNotContainsString('null !== $this->getId()', $script);
    }

    /**
     * @return void
     */
    public function testHashCodeUsesTraitPrimaryKeyValidatorForCompositePrimaryKey()
    {
        $table = new Table('Widget');

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $id->setNotNull(true);
        $table->addColumn($id);

        $code = new Column('code');
        $code->setDomain(new Domain('VARCHAR'));
        $code->setPrimaryKey(true);
        $code->setNotNull(true);
        $table->addColumn($code);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addHashCodeToScript($script);

        $this->assertStringContainsString('$validPk = $this->validatePrimaryKeys($this->getPrimaryKey(), [0, \'\']);', $script);
    }

    /**
     * @return void
     */
    public function testPrimaryKeyAccessorThrowsOnUnsetValueAndGeneratesTryGetter()
    {
        $table = new Table('BalanceTransaction');

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $id->setNotNull(true);
        $table->addColumn($id);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $comment = '';
        $builder->addDefaultAccessorCommentToScript($comment, $id);

        $body = '';
        $builder->addDefaultAccessorBodyToScript($body, $id);

        $accessor = '';
        $builder->addDefaultAccessorToScript($accessor, $id);

        $this->assertStringContainsString('* @return int', $comment);
        $this->assertStringNotContainsString('* @return int|null', $comment);
        $this->assertStringContainsString('return $this->id ?? 0;', $body);
        $this->assertStringNotContainsString('throw new PropelException(', $body);
        $this->assertStringNotContainsString('function tryGetId()', $accessor);
    }

    /**
     * @return void
     */
    public function testIsPrimaryKeyNullUsesDirectGetterForSinglePrimaryKey()
    {
        $table = new Table('BalanceTransaction');

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $id->setNotNull(true);
        $table->addColumn($id);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addIsPrimaryKeyNullToScript($script);

        $this->assertStringContainsString('return 0 === $this->getId();', $script);
        $this->assertStringNotContainsString('return 0 === $this->tryGetId();', $script);
    }

    /**
     * @return void
     */
    public function testPrimaryKeyMutatorDoesNotUseSharedCastHelper()
    {
        $table = new Table('BalanceTransaction');

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $id->setNotNull(true);
        $table->addColumn($id);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $mutator = '';
        $builder->addDefaultMutatorToScript($mutator, $id);

        $this->assertStringNotContainsString("\$v = \$this->castTo(\$v, 'int', true);", $mutator);
        $this->assertStringContainsString('if ($this->id !== $v) {', $mutator);
    }

    /**
     * @return void
     */
    public function testOptionalForeignKeyScalarAccessorsAndMutatorsStayNullableInGeneratedApi()
    {
        $database = new Database('test');

        $groupTable = new Table('affiliate_group');
        $database->addTable($groupTable);

        $groupId = new Column('id');
        $groupId->setDomain(new Domain('INTEGER'));
        $groupId->setPrimaryKey(true);
        $groupId->setNotNull(true);
        $groupId->setAutoIncrement(true);
        $groupTable->addColumn($groupId);

        $affiliateTable = new Table('affiliate');
        $database->addTable($affiliateTable);

        $affiliateGroupId = new Column('affiliate_group_id');
        $affiliateGroupId->setDomain(new Domain('INTEGER'));
        $affiliateTable->addColumn($affiliateGroupId);
        $affiliateTable->addForeignKey(['foreignTable' => 'affiliate_group'])
            ->addReference('affiliate_group_id', 'id');

        $builder = new TestableObjectBuilder($affiliateTable);
        $builder->setPlatform(new MysqlPlatform());

        $accessorComment = '';
        $builder->addDefaultAccessorCommentToScript($accessorComment, $affiliateGroupId);

        $mutatorComment = '';
        $builder->addMutatorCommentToScript($mutatorComment, $affiliateGroupId);

        $mutator = '';
        $builder->addDefaultMutatorToScript($mutator, $affiliateGroupId);

        $this->assertStringContainsString('* @return int|null', $accessorComment);
        $this->assertStringContainsString('* @param int|null $v New value', $mutatorComment);
        $this->assertStringContainsString("\$v = \$this->castTo(\$v, 'int', true);", $mutator);
    }

    /**
     * @return void
     */
    public function testRequiredForeignKeyScalarAccessorsAndMutatorsStayNonNullableInGeneratedApi()
    {
        $database = new Database('test');

        $groupTable = new Table('affiliate_group');
        $database->addTable($groupTable);

        $groupId = new Column('id');
        $groupId->setDomain(new Domain('INTEGER'));
        $groupId->setPrimaryKey(true);
        $groupId->setNotNull(true);
        $groupId->setAutoIncrement(true);
        $groupTable->addColumn($groupId);

        $affiliateTable = new Table('affiliate');
        $database->addTable($affiliateTable);

        $affiliateGroupId = new Column('affiliate_group_id');
        $affiliateGroupId->setDomain(new Domain('INTEGER'));
        $affiliateGroupId->setNotNull(true);
        $affiliateTable->addColumn($affiliateGroupId);
        $affiliateTable->addForeignKey(['foreignTable' => 'affiliate_group'])
            ->addReference('affiliate_group_id', 'id');

        $builder = new TestableObjectBuilder($affiliateTable);
        $builder->setPlatform(new MysqlPlatform());

        $accessorComment = '';
        $builder->addDefaultAccessorCommentToScript($accessorComment, $affiliateGroupId);

        $accessorBody = '';
        $builder->addDefaultAccessorBodyToScript($accessorBody, $affiliateGroupId);

        $accessor = '';
        $builder->addDefaultAccessorToScript($accessor, $affiliateGroupId);

        $mutatorComment = '';
        $builder->addMutatorCommentToScript($mutatorComment, $affiliateGroupId);

        $mutator = '';
        $builder->addDefaultMutatorToScript($mutator, $affiliateGroupId);

        $this->assertStringContainsString('* @return int', $accessorComment);
        $this->assertStringNotContainsString('* @return int|null', $accessorComment);
        $this->assertStringContainsString('return $this->affiliate_group_id ?? 0;', $accessorBody);
        $this->assertStringNotContainsString('throw new PropelException(', $accessorBody);
        $this->assertStringNotContainsString('function tryGetAffiliateGroupId()', $accessor);
        $this->assertStringContainsString('* @param int $v New value', $mutatorComment);
        $this->assertStringNotContainsString('* @param int|null $v New value', $mutatorComment);
        $this->assertStringContainsString("\$v = \$this->castTo(\$v, 'int', false);", $mutator);
    }

    /**
     * @return void
     */
    public function testRequiredObjectAccessorCommentStaysNullableAtRuntime()
    {
        $details = new Column('details');
        $details->setDomain(new Domain('OBJECT'));
        $details->setNotNull(true);

        $builder = new TestableObjectBuilder(new Table('TypeObject'));
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addObjectAccessorToScript($script, $details);

        $this->assertStringContainsString('* @return mixed|null', $script);
    }

    /**
     * @return void
     */
    public function testRequiredJsonAccessorCommentStaysNullableAtRuntime()
    {
        $payload = new Column('payload');
        $payload->setDomain(new Domain('JSON'));
        $payload->setNotNull(true);

        $builder = new TestableObjectBuilder(new Table('Thing'));
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addJsonAccessorToScript($script, $payload);

        $this->assertStringContainsString('* @return object|array|null', $script);
    }

    /**
     * @return void
     */
    public function testNullableArrayAccessorCommentMatchesNonNullRuntimeReturn()
    {
        $tags = new Column('tags');
        $tags->setDomain(new Domain('ARRAY'));

        $builder = new TestableObjectBuilder(new Table('Thing'));
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addArrayAccessorToScript($script, $tags);

        $this->assertStringContainsString('* @return array', $script);
        $this->assertStringNotContainsString('* @return array|null', $script);
    }

    /**
     * @return void
     */
    public function testRequiredTemporalAccessorCommentStaysNullableAtRuntime()
    {
        $createdAt = new Column('created_at');
        $createdAt->setDomain(new Domain('TIMESTAMP'));
        $createdAt->setNotNull(true);

        $builder = new TestableObjectBuilder(new Table('Thing'));
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addTemporalAccessorToScript($script, $createdAt);

        $this->assertStringContainsString('* @return \DateTime|null', $script);
    }

    /**
     * @return void
     */
    public function testForeignPrimaryKeyMutatorDoesNotUseSharedCastHelper()
    {
        $database = new Database('test');

        $parentTable = new Table('parent');
        $database->addTable($parentTable);

        $parentId = new Column('id');
        $parentId->setDomain(new Domain('INTEGER'));
        $parentId->setPrimaryKey(true);
        $parentId->setNotNull(true);
        $parentTable->addColumn($parentId);

        $childTable = new Table('child');
        $database->addTable($childTable);

        $childId = new Column('parent_id');
        $childId->setDomain(new Domain('INTEGER'));
        $childId->setPrimaryKey(true);
        $childId->setNotNull(true);
        $childTable->addColumn($childId);
        $childTable->addForeignKey(['foreignTable' => 'parent'])
            ->addReference('parent_id', 'id');

        $builder = new TestableObjectBuilder($childTable);
        $builder->setPlatform(new MysqlPlatform());

        $mutator = '';
        $builder->addDefaultMutatorToScript($mutator, $childId);

        $this->assertStringNotContainsString("\$v = \$this->castTo(\$v, 'int', true);", $mutator);
        $this->assertStringContainsString('if ($this->parent_id !== $v) {', $mutator);
    }

    /**
     * @return void
     */
    public function testCompositePrimaryKeyMutatorsDoNotUseSharedCastHelper()
    {
        $table = new Table('Widget');

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $id->setNotNull(true);
        $table->addColumn($id);

        $code = new Column('code');
        $code->setDomain(new Domain('VARCHAR'));
        $code->setPrimaryKey(true);
        $code->setNotNull(true);
        $table->addColumn($code);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $idMutator = '';
        $builder->addDefaultMutatorToScript($idMutator, $id);

        $codeMutator = '';
        $builder->addDefaultMutatorToScript($codeMutator, $code);

        $this->assertStringNotContainsString("\$v = \$this->castTo(\$v, 'int', true);", $idMutator);
        $this->assertStringNotContainsString("\$v = \$this->castTo(\$v, 'string', true);", $codeMutator);
        $this->assertStringContainsString('if ($this->id !== $v) {', $idMutator);
        $this->assertStringContainsString('if ($this->code !== $v) {', $codeMutator);
    }

    /**
     * @return void
     */
    public function testRelationWriteMethodsUseChildRelatedTypes()
    {
        $database = new Database('test');

        $groupTable = new Table('affiliate_group');
        $groupTable->setNamespace('Model');
        $database->addTable($groupTable);

        $groupId = new Column('id');
        $groupId->setDomain(new Domain('INTEGER'));
        $groupId->setPrimaryKey(true);
        $groupId->setNotNull(true);
        $groupId->setAutoIncrement(true);
        $groupTable->addColumn($groupId);

        $affiliateTable = new Table('affiliate');
        $affiliateTable->setNamespace('Model');
        $database->addTable($affiliateTable);

        $affiliateId = new Column('id');
        $affiliateId->setDomain(new Domain('INTEGER'));
        $affiliateId->setPrimaryKey(true);
        $affiliateId->setNotNull(true);
        $affiliateId->setAutoIncrement(true);
        $affiliateTable->addColumn($affiliateId);

        $affiliateGroupId = new Column('affiliate_group_id');
        $affiliateGroupId->setDomain(new Domain('INTEGER'));
        $affiliateTable->addColumn($affiliateGroupId);
        $affiliateTable->addForeignKey(['foreignTable' => 'affiliate_group'])
            ->addReference('affiliate_group_id', 'id');

        $affiliateBuilder = new TestableObjectBuilder($affiliateTable);
        $affiliateBuilder->setGeneratorConfig(new QuickGeneratorConfig());
        $affiliateBuilder->setPlatform(new MysqlPlatform());

        $fk = $affiliateTable->getForeignKeys()[0];

        $fkAttributes = '';
        $affiliateBuilder->addFKAttributesToScript($fkAttributes, $fk);

        $fkMutator = '';
        $affiliateBuilder->addFKMutatorToScript($fkMutator, $fk);

        $fkAccessor = '';
        $affiliateBuilder->addFKAccessorToScript($fkAccessor, $fk);

        $groupBuilder = new TestableObjectBuilder($groupTable);
        $groupBuilder->setGeneratorConfig(new QuickGeneratorConfig());
        $groupBuilder->setPlatform(new MysqlPlatform());

        $refFk = $fk;

        $refFkAdd = '';
        $groupBuilder->addRefFKAddToScript($refFkAdd, $refFk);

        $refFkDoAdd = '';
        $groupBuilder->addRefFKDoAddToScript($refFkDoAdd, $refFk);

        $refFkRemove = '';
        $groupBuilder->addRefFKRemoveToScript($refFkRemove, $refFk);

        $this->assertStringContainsString('@var ?ChildAffiliateGroup', $fkAttributes);
        $this->assertStringContainsString('public function setAffiliateGroup(?ChildAffiliateGroup $v = null)', $fkMutator);
        $this->assertStringContainsString('assert($this instanceof ChildAffiliate);', $fkMutator);
        $this->assertStringContainsString('$v?->addAffiliate($this);', $fkMutator);
        $this->assertStringContainsString('@return ChildAffiliateGroup|null', $fkAccessor);
        $this->assertStringContainsString('public function addAffiliate(ChildAffiliate $l)', $refFkAdd);
        $this->assertStringContainsString('protected function doAddAffiliate(ChildAffiliate $affiliate): void', $refFkDoAdd);
        $this->assertStringContainsString('assert($this instanceof ChildAffiliateGroup);', $refFkDoAdd);
        $this->assertStringContainsString('$affiliate->setAffiliateGroup($this);', $refFkDoAdd);
        $this->assertStringContainsString('public function removeAffiliate(ChildAffiliate $affiliate)', $refFkRemove);
    }

    /**
     * @return void
     */
    public function testOneToOneRefFkAttributesUseChildRelatedTypeInDocComment()
    {
        $database = new Database('test');

        $groupTable = new Table('affiliate_group');
        $groupTable->setNamespace('Model');
        $database->addTable($groupTable);

        $groupId = new Column('id');
        $groupId->setDomain(new Domain('INTEGER'));
        $groupId->setPrimaryKey(true);
        $groupId->setNotNull(true);
        $groupId->setAutoIncrement(true);
        $groupTable->addColumn($groupId);

        $affiliateTable = new Table('affiliate');
        $affiliateTable->setNamespace('Model');
        $database->addTable($affiliateTable);

        $affiliateId = new Column('id');
        $affiliateId->setDomain(new Domain('INTEGER'));
        $affiliateId->setPrimaryKey(true);
        $affiliateId->setNotNull(true);
        $affiliateTable->addColumn($affiliateId);
        $affiliateTable->addForeignKey(['foreignTable' => 'affiliate_group'])
            ->addReference('id', 'id');

        $groupBuilder = new TestableObjectBuilder($groupTable);
        $groupBuilder->setGeneratorConfig(new QuickGeneratorConfig());
        $groupBuilder->setPlatform(new MysqlPlatform());

        $refFkAttributes = '';
        $groupBuilder->addRefFKAttributesToScript($refFkAttributes, $affiliateTable->getForeignKeys()[0]);

        $this->assertStringContainsString('@var ?ChildAffiliate one-to-one related ChildAffiliate object', $refFkAttributes);
    }

    /**
     * @return void
     */
    public function testOneToOneRelationSetterUsesChildRelatedType()
    {
        $database = new Database('test');

        $groupTable = new Table('affiliate_group');
        $groupTable->setNamespace('Model');
        $database->addTable($groupTable);

        $groupId = new Column('id');
        $groupId->setDomain(new Domain('INTEGER'));
        $groupId->setPrimaryKey(true);
        $groupId->setNotNull(true);
        $groupId->setAutoIncrement(true);
        $groupTable->addColumn($groupId);

        $affiliateTable = new Table('affiliate');
        $affiliateTable->setNamespace('Model');
        $database->addTable($affiliateTable);

        $affiliateId = new Column('id');
        $affiliateId->setDomain(new Domain('INTEGER'));
        $affiliateId->setPrimaryKey(true);
        $affiliateId->setNotNull(true);
        $affiliateTable->addColumn($affiliateId);
        $affiliateTable->addForeignKey(['foreignTable' => 'affiliate_group'])
            ->addReference('id', 'id');

        $groupBuilder = new TestableObjectBuilder($groupTable);
        $groupBuilder->setGeneratorConfig(new QuickGeneratorConfig());
        $groupBuilder->setPlatform(new MysqlPlatform());

        $script = '';
        $groupBuilder->addPKRefFKSetToScript($script, $affiliateTable->getForeignKeys()[0]);

        $this->assertStringContainsString('public function setAffiliate(?ChildAffiliate $v = null)', $script);
        $this->assertStringContainsString('assert($this instanceof ChildAffiliateGroup);', $script);
        $this->assertStringContainsString('$v->setAffiliateGroup($this);', $script);
    }

    /**
     * @return void
     */
    public function testOneToOneRelationAccessorUsesCurrentObjectWithoutEscaping()
    {
        $database = new Database('test');

        $groupTable = new Table('affiliate_group');
        $groupTable->setNamespace('Model');
        $database->addTable($groupTable);

        $groupId = new Column('id');
        $groupId->setDomain(new Domain('INTEGER'));
        $groupId->setPrimaryKey(true);
        $groupId->setNotNull(true);
        $groupId->setAutoIncrement(true);
        $groupTable->addColumn($groupId);

        $affiliateTable = new Table('affiliate');
        $affiliateTable->setNamespace('Model');
        $database->addTable($affiliateTable);

        $affiliateId = new Column('id');
        $affiliateId->setDomain(new Domain('INTEGER'));
        $affiliateId->setPrimaryKey(true);
        $affiliateId->setNotNull(true);
        $affiliateTable->addColumn($affiliateId);
        $affiliateTable->addForeignKey(['foreignTable' => 'affiliate_group'])
            ->addReference('id', 'id');

        $affiliateBuilder = new TestableObjectBuilder($affiliateTable);
        $affiliateBuilder->setGeneratorConfig(new QuickGeneratorConfig());
        $affiliateBuilder->setPlatform(new MysqlPlatform());

        $script = '';
        $affiliateBuilder->addFKAccessorToScript($script, $affiliateTable->getForeignKeys()[0]);

        $this->assertStringContainsString('assert($this instanceof ChildAffiliate);', $script);
        $this->assertStringContainsString('$this->aAffiliateGroup?->setAffiliate($this);', $script);
        $this->assertStringNotContainsString('$this->aAffiliateGroup?->setAffiliate(\$currentObject);', $script);
    }

    /**
     * @return void
     */
    public function testRequiredRelationAccessorThrowsWhenRelationCannotBeResolved()
    {
        $database = new Database('test');

        $groupTable = new Table('affiliate_group');
        $groupTable->setNamespace('Model');
        $database->addTable($groupTable);

        $groupId = new Column('id');
        $groupId->setDomain(new Domain('INTEGER'));
        $groupId->setPrimaryKey(true);
        $groupId->setNotNull(true);
        $groupId->setAutoIncrement(true);
        $groupTable->addColumn($groupId);

        $affiliateTable = new Table('affiliate');
        $affiliateTable->setNamespace('Model');
        $database->addTable($affiliateTable);

        $affiliateId = new Column('id');
        $affiliateId->setDomain(new Domain('INTEGER'));
        $affiliateId->setPrimaryKey(true);
        $affiliateId->setNotNull(true);
        $affiliateId->setAutoIncrement(true);
        $affiliateTable->addColumn($affiliateId);

        $affiliateGroupId = new Column('affiliate_group_id');
        $affiliateGroupId->setDomain(new Domain('INTEGER'));
        $affiliateGroupId->setNotNull(true);
        $affiliateTable->addColumn($affiliateGroupId);
        $affiliateTable->addForeignKey(['foreignTable' => 'affiliate_group'])
            ->addReference('affiliate_group_id', 'id');

        $affiliateBuilder = new TestableObjectBuilder($affiliateTable);
        $affiliateBuilder->setGeneratorConfig(new QuickGeneratorConfig());
        $affiliateBuilder->setPlatform(new MysqlPlatform());

        $script = '';
        $affiliateBuilder->addFKAccessorToScript($script, $affiliateTable->getForeignKeys()[0]);

        $this->assertStringContainsString('@return ChildAffiliateGroup The associated ChildAffiliateGroup object.', $script);
        $this->assertStringContainsString("throw new PropelException('Cannot return a null related object from getAffiliateGroup() because the relation is required.');", $script);
        $this->assertStringContainsString('return $this->aAffiliateGroup;', $script);
        $this->assertStringContainsString('* Try to get the associated ChildAffiliateGroup object', $script);
        $this->assertStringContainsString('@return ChildAffiliateGroup|null The associated ChildAffiliateGroup object.', $script);
        $this->assertStringContainsString('public function tryGetAffiliateGroup(?ConnectionInterface $con = null)', $script);
    }

    /**
     * @return void
     */
    public function testClearUsesAssertedChildObjectForReverseRelationRemoval()
    {
        $database = new Database('test');

        $affiliateTable = new Table('affiliate');
        $affiliateTable->setNamespace('Model');
        $database->addTable($affiliateTable);

        $affiliateId = new Column('id');
        $affiliateId->setDomain(new Domain('INTEGER'));
        $affiliateId->setPrimaryKey(true);
        $affiliateId->setNotNull(true);
        $affiliateId->setAutoIncrement(true);
        $affiliateTable->addColumn($affiliateId);

        $affiliatePlayerTable = new Table('affiliate_player');
        $affiliatePlayerTable->setNamespace('Model');
        $database->addTable($affiliatePlayerTable);

        $affiliatePlayerId = new Column('id');
        $affiliatePlayerId->setDomain(new Domain('INTEGER'));
        $affiliatePlayerId->setPrimaryKey(true);
        $affiliatePlayerId->setNotNull(true);
        $affiliatePlayerId->setAutoIncrement(true);
        $affiliatePlayerTable->addColumn($affiliatePlayerId);

        $affiliateForeignKey = new Column('affiliate_id');
        $affiliateForeignKey->setDomain(new Domain('INTEGER'));
        $affiliatePlayerTable->addColumn($affiliateForeignKey);
        $affiliatePlayerTable->addForeignKey(['foreignTable' => 'affiliate'])
            ->addReference('affiliate_id', 'id');

        $builder = new TestableObjectBuilder($affiliatePlayerTable);
        $builder->setGeneratorConfig(new QuickGeneratorConfig());
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addClearToScript($script);

        $this->assertStringContainsString('assert($this instanceof ChildAffiliatePlayer);', $script);
        $this->assertStringContainsString('$this->aAffiliate->removeAffiliatePlayer($this);', $script);
        $this->assertStringNotContainsString('$this->aAffiliate->removeAffiliatePlayer($currentObject);', $script);
    }

    /**
     * @return void
     */
    public function testSetPrimaryKeyUsesNullUnsetValueForForeignPrimaryKeys()
    {
        $database = new Database('test');

        $parentTable = new Table('Parent');
        $database->addTable($parentTable);

        $parentId = new Column('id');
        $parentId->setDomain(new Domain('INTEGER'));
        $parentId->setPrimaryKey(true);
        $parentId->setNotNull(true);
        $parentId->setAutoIncrement(true);
        $parentTable->addColumn($parentId);

        $childTable = new Table('Child');
        $database->addTable($childTable);
        $childId = clone $parentId;
        $childId->setAutoIncrement(false);
        $childTable->addColumn($childId);
        $childTable->addForeignKey([
            'foreignTable' => 'Parent',
            'onDelete' => 'CASCADE',
        ])->addReference('id', 'id');

        $builder = new TestableObjectBuilder($childTable);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addSetPrimaryKeyToScript($script);

        $this->assertStringContainsString('public function setPrimaryKey(?int $key = null): void', $script);
        $this->assertStringContainsString('* @param int|null $key Primary key.', $script);
    }

    /**
     * @return void
     */
    public function testGetPrimaryKeyUsesNullableDocTypeForSinglePrimaryKey()
    {
        $table = new Table('BalanceTransaction');

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $id->setNotNull(true);
        $table->addColumn($id);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addGetPrimaryKeyToScript($script);

        $this->assertStringContainsString('* @return int|null', $script);
        $this->assertStringContainsString('return $this->getId();', $script);
        $this->assertStringNotContainsString('return $this->tryGetId();', $script);
    }

    /**
     * @return void
     */
    public function testSetPrimaryKeyUsesNonNullableDocTypeForSinglePrimaryKey()
    {
        $table = new Table('BalanceTransaction');

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $id->setNotNull(true);
        $table->addColumn($id);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addSetPrimaryKeyToScript($script);

        $this->assertStringContainsString('* @param int $key Primary key.', $script);
        $this->assertStringNotContainsString('* @param int|null $key Primary key.', $script);
    }

    /**
     * @return void
     */
    public function testTemporalAccessorCommentUsesNullableReturnTypeWithoutThrows()
    {
        $column = new Column('created_at');
        $column->setDomain(new Domain('TIMESTAMP'));

        $script = '';
        $this->builder->addTemporalAccessorCommentToScript($script, $column);

        $this->assertStringContainsString('* @return \DateTime|null', $script);
        $this->assertStringNotContainsString('@throws \Propel\Runtime\Exception\PropelException', $script);
    }

    /**
     * @return void
     */
    public function testTemporalAccessorCommentStaysNullableWhenRequired()
    {
        $column = new Column('created_at');
        $column->setDomain(new Domain('TIMESTAMP'));
        $column->setNotNull(true);

        $script = '';
        $this->builder->addTemporalAccessorCommentToScript($script, $column);

        $this->assertStringContainsString('* @return \DateTime|null', $script);
    }

    /**
     * @return void
     */
    public function testRequiredTemporalAccessorGenerationUsesNullableReturnDocType()
    {
        $table = new Table('Foo');

        $column = new Column('register_stamp');
        $column->setDomain(new Domain('TIMESTAMP'));
        $column->setNotNull(true);
        $table->addColumn($column);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addTemporalAccessorToScript($script, $column);

        $this->assertStringContainsString('public function getRegisterStamp()', $script);
        $this->assertStringContainsString('* @return \DateTime|null', $script);
        $this->assertStringNotContainsString('* @return \DateTime', str_replace('* @return \DateTime|null', '', $script));
    }

    /**
     * @return void
     */
    public function testTemporalAccessorOpenDoesNotUsePhpReturnTypeForNullableColumn()
    {
        $column = new Column('blocking_limit');
        $column->setDomain(new Domain('TIMESTAMP'));

        $script = '';
        $this->builder->addTemporalAccessorOpenToScript($script, $column);

        $this->assertStringContainsString('public function getBlockingLimit()', $script);
        $this->assertStringNotContainsString(': ?\DateTime', $script);
        $this->assertStringNotContainsString(': \DateTime', $script);
    }

    /**
     * @return void
     */
    public function testTemporalAccessorOpenDoesNotUsePhpReturnTypeWhenRequired()
    {
        $column = new Column('blocking_limit');
        $column->setDomain(new Domain('TIMESTAMP'));
        $column->setNotNull(true);

        $script = '';
        $this->builder->addTemporalAccessorOpenToScript($script, $column);

        $this->assertStringContainsString('public function getBlockingLimit()', $script);
        $this->assertStringNotContainsString(': ?\DateTime', $script);
        $this->assertStringNotContainsString(': \DateTime', $script);
    }

    /**
     * @return void
     */
    public function testTemporalMutatorAcceptsLegacyScalarInputs()
    {
        $table = new Table('Foo');

        $column = new Column('register_stamp');
        $column->setDomain(new Domain('TIMESTAMP'));
        $table->addColumn($column);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addTemporalMutatorToScript($script, $column);

        $this->assertStringContainsString('@param string|integer|\DateTimeInterface|null $v string, integer (timestamp), or \DateTimeInterface value.', $script);
        $this->assertStringContainsString('public function setRegisterStamp($v)', $script);
        $this->assertStringContainsString("\$this->setTemporalValue(\$this->register_stamp, \$v, '\\DateTime', FooTableMap::COL_REGISTER_STAMP, 'Y-m-d H:i:s.u');", $script);
    }

}

class TestableObjectBuilder extends ObjectBuilder
{
    public function getDefaultValueString(Column $col, bool $acceptNull = true): string
    {
        return parent::getDefaultValueString($col, $acceptNull);
    }

    public function getTableMapClass(): string
    {
        return $this->getTable()->getPhpName() . 'TableMap';
    }

    public function getUnprefixedClassName(): string
    {
        return $this->getTable()->getPhpName();
    }

    public function getObjectClassName(bool $fqcn = false): string
    {
        return $this->getTable()->getPhpName();
    }

    public function addSetByPositionToScript(string &$script): void
    {
        $this->addSetByPosition($script);
    }

    public function addBuildCriteriaToScript(string &$script): void
    {
        $this->addBuildCriteria($script);
    }

    public function addDoInsertToScript(): string
    {
        return $this->addDoInsert();
    }

    public function addBaseObjectMethodsToScript(string &$script): void
    {
        $this->addBaseObjectMethods($script);
    }

    public function addHashCodeToScript(string &$script): void
    {
        $this->addHashCode($script);
    }

    public function addClassBodyToScript(string &$script): void
    {
        $this->addClassBody($script);
    }

    public function addClearToScript(string &$script): void
    {
        $this->addClear($script);
    }

    public function addSetPrimaryKeyToScript(string &$script): void
    {
        $this->addSetPrimaryKey($script);
    }

    public function addGetPrimaryKeyToScript(string &$script): void
    {
        $this->addGetPrimaryKey($script);
    }

    public function addIsPrimaryKeyNullToScript(string &$script): void
    {
        $this->addIsPrimaryKeyNull($script);
    }

    public function addCommonTraitUsesToScript(string &$script): void
    {
        $this->addCommonTraitUses($script);
    }

    public function getResolveFromRowExpressionForColumn(Column $column, int $position): ?string
    {
        return $this->getResolveFromRowExpression($column, $position);
    }

    public function addTemporalAccessorCommentToScript(string &$script, Column $column): void
    {
        $this->addTemporalAccessorComment($script, $column);
    }

    public function addTemporalAccessorOpenToScript(string &$script, Column $column): void
    {
        $this->addTemporalAccessorOpen($script, $column);
    }

    public function addTemporalMutatorToScript(string &$script, Column $column): void
    {
        $this->addTemporalMutator($script, $column);
    }

    public function addFKMutatorToScript(
        string &$script,
        \Propel\Generator\Model\ForeignKey $foreignKey
    ): void {
        $this->addFKMutator($script, $foreignKey);
    }

    public function addFKAttributesToScript(
        string &$script,
        \Propel\Generator\Model\ForeignKey $foreignKey
    ): void {
        $this->addFKAttributes($script, $foreignKey);
    }

    public function addFKAccessorToScript(
        string &$script,
        \Propel\Generator\Model\ForeignKey $foreignKey
    ): void {
        $this->addFKAccessor($script, $foreignKey);
    }

    public function addRefFKAddToScript(
        string &$script,
        \Propel\Generator\Model\ForeignKey $foreignKey
    ): void {
        $this->addRefFKAdd($script, $foreignKey);
    }

    public function addRefFKAttributesToScript(
        string &$script,
        \Propel\Generator\Model\ForeignKey $foreignKey
    ): void {
        $this->addRefFKAttributes($script, $foreignKey);
    }

    public function addRefFKDoAddToScript(
        string &$script,
        \Propel\Generator\Model\ForeignKey $foreignKey
    ): void {
        $this->addRefFKDoAdd($script, $foreignKey);
    }

    public function addRefFKRemoveToScript(
        string &$script,
        \Propel\Generator\Model\ForeignKey $foreignKey
    ): void {
        $this->addRefFKRemove($script, $foreignKey);
    }

    public function addPKRefFKSetToScript(
        string &$script,
        \Propel\Generator\Model\ForeignKey $foreignKey
    ): void {
        $this->addPKRefFKSet($script, $foreignKey);
    }

    public function addDefaultAccessorCommentToScript(string &$script, Column $column): void
    {
        $this->addDefaultAccessorComment($script, $column);
    }

    public function addDefaultAccessorToScript(string &$script, Column $column): void
    {
        $this->addDefaultAccessor($script, $column);
    }

    public function addDefaultAccessorBodyToScript(string &$script, Column $column): void
    {
        $this->addDefaultAccessorBody($script, $column);
    }

    public function addObjectAccessorToScript(string &$script, Column $column): void
    {
        $this->addObjectAccessor($script, $column);
    }

    public function addJsonAccessorToScript(string &$script, Column $column): void
    {
        $this->addJsonAccessor($script, $column);
    }

    public function addArrayAccessorToScript(string &$script, Column $column): void
    {
        $this->addArrayAccessor($script, $column);
    }

    public function addTemporalAccessorToScript(string &$script, Column $column): void
    {
        $this->addTemporalAccessor($script, $column);
    }

    public function addMutatorCommentToScript(string &$script, Column $column): void
    {
        $this->addMutatorComment($script, $column);
    }

    public function addDefaultMutatorToScript(string &$script, Column $column): void
    {
        $this->addDefaultMutator($script, $column);
    }
}
