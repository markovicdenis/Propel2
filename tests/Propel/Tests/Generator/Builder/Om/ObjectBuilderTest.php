<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Builder\Om;

use Propel\Generator\Builder\Om\ObjectBuilder;
use Propel\Generator\Builder\Util\SchemaReader;
use Propel\Generator\Config\QuickGeneratorConfig;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\ColumnDefaultValue;
use Propel\Generator\Model\Database;
use Propel\Generator\Model\ForeignKey;
use Propel\Generator\Model\IdMethod;
use Propel\Generator\Model\Domain;
use Propel\Generator\Model\Table;
use Propel\Generator\Platform\MysqlPlatform;
use Propel\Generator\Platform\PgsqlPlatform;
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
    public function testGeneratedConvertValueToObjectTypeHookDelegatesToSharedPhpTypeHelper()
    {
        $script = '';
        $this->builder->addConvertValueToObjectTypeMethodToScript($script);

        $this->assertStringContainsString('protected function convertValueToObjectType(mixed $value, string $phpType, bool $isNullable): mixed', $script);
        $this->assertStringContainsString('return $this->convertValueToPhpType($value, $phpType, $isNullable);', $script);
        $this->assertStringNotContainsString('protected function areObjectTypeValuesEqual(', $script);
    }

    /**
     * @return void
     */
    public function testGeneratedAreObjectTypeValuesEqualHookDelegatesToSharedPhpTypeHelper()
    {
        $script = '';
        $this->builder->addAreObjectTypeValuesEqualMethodToScript($script);

        $this->assertStringContainsString('protected function areObjectTypeValuesEqual(mixed $currentValue, mixed $newValue, string $phpType): bool', $script);
        $this->assertStringContainsString('return $this->arePhpTypeValuesEqual($currentValue, $newValue, $phpType);', $script);
        $this->assertStringNotContainsString('protected function convertValueToObjectType(', $script);
    }

    /**
     * @return void
     */
    public function testGeneratedPhpTypeHooksDelegateToTraitDefaults()
    {
        $database = new Database('test');
        $table = new Table('Promotion');
        $database->addTable($table);

        $limits = new Column('limits');
        $table->addColumn($limits);
        $limits->loadMapping([
            'name' => 'limits',
            'type' => 'VARCHAR',
            'phpType' => '\\Internal\\Features\\UserLimits\\UserLimitConfig',
        ]);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addPhpTypeMethodsToScript($script);

        $this->assertStringContainsString('protected function convertValueToObjectType(mixed $value, string $phpType, bool $isNullable): mixed', $script);
        $this->assertStringContainsString('return $this->convertValueToPhpType($value, $phpType, $isNullable);', $script);
        $this->assertStringContainsString('protected function areObjectTypeValuesEqual(mixed $currentValue, mixed $newValue, string $phpType): bool', $script);
        $this->assertStringContainsString('return $this->arePhpTypeValuesEqual($currentValue, $newValue, $phpType);', $script);
    }

    /**
     * @return void
     */
    public function testClassBodyGeneratesObjectTypeHooksWhenObjectMutatorExists()
    {
        $database = new Database('test');
        $table = new Table('Foo');
        $database->addTable($table);

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $table->addColumn($id);

        $limits = new Column('limits');
        $table->addColumn($limits);
        $limits->loadMapping([
            'name' => 'limits',
            'type' => 'VARCHAR',
            'phpType' => '\\Internal\\Features\\UserLimits\\UserLimitConfig',
        ]);

        $builder = new TestableObjectBuilder($table);
        $builder->setGeneratorConfig(new QuickGeneratorConfig());
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addClassBodyToScript($script);

        $this->assertStringContainsString('protected function convertValueToObjectType(mixed $value, string $phpType, bool $isNullable): mixed', $script);
        $this->assertStringContainsString('protected function areObjectTypeValuesEqual(mixed $currentValue, mixed $newValue, string $phpType): bool', $script);
    }

    /**
     * @return void
     */
    public function testClassBodySkipsPhpTypeHooksWhenNoColumnNeedsThem()
    {
        $database = new Database('test');
        $table = new Table('Foo');
        $database->addTable($table);

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $table->addColumn($id);

        $isActive = new Column('is_active');
        $isActive->setDomain(new Domain('BOOLEAN'));
        $table->addColumn($isActive);

        $createdAt = new Column('created_at');
        $createdAt->setDomain(new Domain('TIMESTAMP'));
        $table->addColumn($createdAt);

        $builder = new TestableObjectBuilder($table);
        $builder->setGeneratorConfig(new QuickGeneratorConfig());
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addClassBodyToScript($script);

        $this->assertStringNotContainsString('protected function convertValueToObjectType(mixed $value, string $phpType, bool $isNullable): mixed', $script);
        $this->assertStringNotContainsString('protected function areObjectTypeValuesEqual(mixed $currentValue, mixed $newValue, string $phpType): bool', $script);
    }

    /**
     * @return void
     */
    public function testClassBodyTreatsDefaultExpressionsAsUnsetValues()
    {
        $database = new Database('test');
        $table = new Table('EmployeeAccount');
        $database->addTable($table);

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $table->addColumn($id);

        $authenticator = new Column('authenticator');
        $authenticator->setDomain(new Domain('VARCHAR'));
        $authenticator->setDefaultValue(new ColumnDefaultValue('Password', ColumnDefaultValue::TYPE_EXPR));
        $table->addColumn($authenticator);

        $builder = new TestableObjectBuilder($table);
        $builder->setGeneratorConfig(new QuickGeneratorConfig());
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addClassBodyToScript($script);

        $this->assertStringContainsString('public function getAuthenticator()', $script);
        $this->assertStringContainsString('@return string|null', $script);
        $this->assertStringContainsString('return $this->authenticator;', $script);
        $this->assertStringNotContainsString("return \$this->authenticator ?? 'Password';", $script);
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
        $this->assertStringContainsString(
            '$criteria->add($columnConstant, $this->normalizeValueForPersistence($this->{$propertyName}));',
            $script
        );
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
    public function testDoInsertMarksObjectPersistedAfterSuccessfulInsert()
    {
        $table = new Table('Foo');
        $table->setIdMethod(IdMethod::NATIVE);

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $id->setAutoIncrement(true);
        $table->addColumn($id);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $script = $builder->addDoInsertToScript();

        $this->assertStringContainsString('        $this->setId((int) $pk);', $script);
        $this->assertStringContainsString('        $this->setNew(false);', $script);
        $this->assertGreaterThan(
            strpos($script, '        $this->setId((int) $pk);'),
            strpos($script, '        $this->setNew(false);')
        );
    }

    /**
     * @return void
     */
    public function testDoInsertUsesLastInsertIdForPostgresAutoIncrementPrimaryKey()
    {
        $table = new Table('Foo');
        $table->setIdMethod(IdMethod::NATIVE);

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $id->setAutoIncrement(true);
        $table->addColumn($id);

        $name = new Column('name');
        $name->setDomain(new Domain('VARCHAR'));
        $table->addColumn($name);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new PgsqlPlatform());

        $script = $builder->addDoInsertToScript();

        $this->assertStringNotContainsString("SELECT nextval('Foo_id_seq')", $script);
        $this->assertStringContainsString("\$pk = \$con->lastInsertId('Foo_id_seq');", $script);
        $this->assertStringContainsString('$this->setId((int) $pk);', $script);
    }

    /**
     * @return void
     */
    public function testDoInsertUsesConfiguredPostgresSequenceAfterInsert()
    {
        $table = new Table('Foo');
        $table->setIdMethod(IdMethod::NATIVE);
        $table->addIdMethodParameter(['value' => 'my_custom_sequence_name']);

        $id = new Column('id');
        $id->setDomain(new Domain('INTEGER'));
        $id->setPrimaryKey(true);
        $id->setAutoIncrement(true);
        $table->addColumn($id);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new PgsqlPlatform());

        $script = $builder->addDoInsertToScript();

        $this->assertStringNotContainsString("SELECT nextval('my_custom_sequence_name')", $script);
        $this->assertStringContainsString("\$pk = \$con->lastInsertId('my_custom_sequence_name');", $script);
    }

    /**
     * @return void
     */
    public function testDoInsertUsesStringBindingForPostgresUidBinaryColumns()
    {
        $database = new Database('foo', new PgsqlPlatform());
        $table = new Table('Foo');
        $database->addTable($table);

        $uid = new Column('uid');
        $uid->setDomain(new Domain('UID_BINARY'));
        $table->addColumn($uid);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new PgsqlPlatform());

        $script = $builder->addDoInsertToScript();

        $this->assertStringContainsString("UuidConverter::uidToString(\$this->uid)", $script);
        $this->assertStringContainsString("new InsertColumnBindingDto(':p'.\$index++, 'uid', (\$this->uid) ? UuidConverter::uidToString(\$this->uid) : null, PDO::PARAM_STR);", $script);
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

        $this->assertStringContainsString("\$v = \$this->convertValueToPhpType(\$v, 'string', true);", $script);
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

        $this->assertStringContainsString('$primaryKey = $this->getPrimaryKey();', $script);
        $this->assertStringContainsString('if ($this->validatePrimaryKey($primaryKey, null)) {', $script);
        $this->assertStringContainsString('return $this->hashCodeFromValue($primaryKey);', $script);
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

        $this->assertStringContainsString('$primaryKey = $this->getPrimaryKey();', $script);
        $this->assertStringContainsString('if ($this->validatePrimaryKeys($primaryKey, [0, \'\'])) {', $script);
        $this->assertStringContainsString('return $this->hashCodeFromValue($primaryKey);', $script);
    }

    /**
     * @return void
     */
    public function testHashCodeIncludesForeignKeyFallbackForSharedPrimaryKeyRelation()
    {
        $database = new Database('test');

        $parentTable = new Table('Parent');
        $database->addTable($parentTable);

        $parentId = new Column('id');
        $parentId->setDomain(new Domain('INTEGER'));
        $parentId->setPrimaryKey(true);
        $parentId->setNotNull(true);
        $parentTable->addColumn($parentId);

        $childTable = new Table('Child');
        $database->addTable($childTable);

        $childId = new Column('id');
        $childId->setDomain(new Domain('INTEGER'));
        $childId->setPrimaryKey(true);
        $childId->setNotNull(true);
        $childTable->addColumn($childId);
        $childTable->addForeignKey([
            'foreignTable' => 'Parent',
        ])->addReference('id', 'id');

        $builder = new TestableObjectBuilder($childTable);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addHashCodeToScript($script);

        $this->assertStringContainsString('if ($this->validatePrimaryKey($primaryKey, null)) {', $script);
        $this->assertStringContainsString('$primaryKeyForeignHashes = [];', $script);
        $this->assertStringContainsString('// relation Child_fk_', $script);
        $this->assertStringContainsString('if (!$this->aParent) {', $script);
        $this->assertStringContainsString('$primaryKeyForeignHashes[] = spl_object_hash($this->aParent);', $script);
        $this->assertStringContainsString('return $this->hashCodeFromValue($primaryKeyForeignHashes);', $script);
    }

    /**
     * @return void
     */
    public function testHashCodeIncludesForeignKeyFallbackForCompositeForeignPrimaryKey()
    {
        $database = new Database('test');

        $bookTable = new Table('Book');
        $database->addTable($bookTable);

        $bookId = new Column('id');
        $bookId->setDomain(new Domain('INTEGER'));
        $bookId->setPrimaryKey(true);
        $bookId->setNotNull(true);
        $bookTable->addColumn($bookId);

        $listTable = new Table('BookClubList');
        $database->addTable($listTable);

        $listId = new Column('id');
        $listId->setDomain(new Domain('INTEGER'));
        $listId->setPrimaryKey(true);
        $listId->setNotNull(true);
        $listTable->addColumn($listId);

        $joinTable = new Table('BookListRel');
        $database->addTable($joinTable);

        $joinBookId = new Column('book_id');
        $joinBookId->setDomain(new Domain('INTEGER'));
        $joinBookId->setPrimaryKey(true);
        $joinBookId->setNotNull(true);
        $joinTable->addColumn($joinBookId);

        $joinListId = new Column('book_club_list_id');
        $joinListId->setDomain(new Domain('INTEGER'));
        $joinListId->setPrimaryKey(true);
        $joinListId->setNotNull(true);
        $joinTable->addColumn($joinListId);

        $joinTable->addForeignKey([
            'foreignTable' => 'Book',
        ])->addReference('book_id', 'id');
        $joinTable->addForeignKey([
            'foreignTable' => 'BookClubList',
        ])->addReference('book_club_list_id', 'id');

        $builder = new TestableObjectBuilder($joinTable);
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addHashCodeToScript($script);

        $this->assertStringContainsString('if ($this->validatePrimaryKeys($primaryKey, [null, null])) {', $script);
        $this->assertStringContainsString('$primaryKeyForeignHashes = [];', $script);
        $this->assertStringContainsString('if (!$this->aBook) {', $script);
        $this->assertStringContainsString('$primaryKeyForeignHashes[] = spl_object_hash($this->aBook);', $script);
        $this->assertStringContainsString('if (!$this->aBookclublist) {', $script);
        $this->assertStringContainsString('$primaryKeyForeignHashes[] = spl_object_hash($this->aBookclublist);', $script);
        $this->assertStringContainsString('return $this->hashCodeFromValue($primaryKeyForeignHashes);', $script);
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
    public function testRequiredBigintAccessorUsesCustomIntPhpTypeForUnsetValue()
    {
        $database = new Database('test');
        $table = new Table('BalanceTransaction');
        $database->addTable($table);

        $id = new Column('id');
        $table->addColumn($id);
        $id->loadMapping([
            'name' => 'id',
            'type' => 'BIGINT',
            'phpType' => 'int',
            'required' => true,
            'autoIncrement' => true,
        ]);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $comment = '';
        $builder->addDefaultAccessorCommentToScript($comment, $id);

        $body = '';
        $builder->addDefaultAccessorBodyToScript($body, $id);

        $this->assertStringContainsString('* @return int', $comment);
        $this->assertStringNotContainsString('* @return int|null', $comment);
        $this->assertStringContainsString('return $this->id ?? 0;', $body);
        $this->assertStringNotContainsString('return $this->id ?? \'\';', $body);
    }

    /**
     * @return void
     */
    public function testAccessorDefaultsUseEffectivePhpType()
    {
        $cases = [
            ['amount', 'BIGINT', 'int', '0', 'return $this->amount ?? 0;'],
            ['enabled', 'BOOLEAN', 'bool', 'true', 'return $this->enabled ?? true;'],
            ['ratio', 'DOUBLE', 'float', '1.25', 'return $this->ratio ?? 1.25;'],
            ['code', 'VARCHAR', 'int', '42', 'return $this->code ?? 42;'],
        ];

        foreach ($cases as [$name, $type, $phpType, $defaultValue, $expectedReturn]) {
            $database = new Database('test');
            $table = new Table('DefaultValue');
            $database->addTable($table);

            $column = new Column($name);
            $table->addColumn($column);
            $column->loadMapping([
                'name' => $name,
                'type' => $type,
                'phpType' => $phpType,
                'required' => true,
                'defaultValue' => $defaultValue,
            ]);

            $builder = new TestableObjectBuilder($table);
            $builder->setPlatform(new MysqlPlatform());

            $body = '';
            $builder->addDefaultAccessorBodyToScript($body, $column);

            $this->assertStringContainsString($expectedReturn, $body);
        }
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

        $this->assertStringNotContainsString('convertValueToPhpType(', $mutator);
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
        $this->assertStringContainsString("\$v = \$this->convertValueToPhpType(\$v, 'int', true);", $mutator);
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
        $this->assertStringContainsString("\$v = \$this->convertValueToPhpType(\$v, 'int', false);", $mutator);
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
    public function testObjectTypedMutatorUsesSharedConversionAndEqualityHooks()
    {
        $database = new Database('test');

        $table = new Table('Promotion');
        $database->addTable($table);

        $limits = new Column('limits');
        $table->addColumn($limits);
        $limits->loadMapping([
            'name' => 'limits',
            'type' => 'VARCHAR',
            'phpType' => '\\Internal\\Features\\UserLimits\\UserLimitConfig',
        ]);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $mutatorComment = '';
        $builder->addMutatorCommentToScript($mutatorComment, $limits);

        $mutator = '';
        $builder->addDefaultMutatorToScript($mutator, $limits);

        $this->assertStringContainsString('* @param \Internal\Features\UserLimits\UserLimitConfig|null $v New value', $mutatorComment);
        $this->assertStringContainsString("\$v = \$this->convertValueToObjectType(\$v, UserLimitConfig::class, true);", $mutator);
        $this->assertStringContainsString("if (!\$this->areObjectTypeValuesEqual(\$this->limits, \$v, UserLimitConfig::class)) {", $mutator);
        $this->assertStringNotContainsString('if ($this->limits !== $v) {', $mutator);
    }

    /**
     * @return void
     */
    public function testCustomPhpTypeOnJsonUsesDefaultAccessorsAndMutators()
    {
        $database = new Database('test');
        $table = new Table('Thing');
        $database->addTable($table);
        $payload = new Column('payload');
        $table->addColumn($payload);
        $payload->loadMapping([
            'name' => 'payload',
            'type' => 'JSON',
            'phpType' => '\\App\\ValueObject\\JsonPayload',
        ]);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new MysqlPlatform());

        $accessors = '';
        $builder->addColumnAccessorMethodsToScript($accessors);

        $mutators = '';
        $builder->addColumnMutatorMethodsToScript($mutators);

        $this->assertStringContainsString('* @return \App\ValueObject\JsonPayload|null', $accessors);
        $this->assertStringNotContainsString('json_decode(', $accessors);
        $this->assertStringContainsString('convertValueToObjectType($v, JsonPayload::class, true)', $mutators);
        $this->assertStringNotContainsString('json_encode(', $mutators);
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

        $this->assertStringNotContainsString('convertValueToPhpType(', $mutator);
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

        $this->assertStringNotContainsString('convertValueToPhpType(', $idMutator);
        $this->assertStringNotContainsString('convertValueToPhpType(', $codeMutator);
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
    public function testHashRelevantForeignKeySetterGeneratesInternalAssociationHelper()
    {
        $database = new Database('test');

        $parentTable = new Table('parent');
        $parentTable->setNamespace('Model');
        $database->addTable($parentTable);

        $parentId = new Column('id');
        $parentId->setDomain(new Domain('INTEGER'));
        $parentId->setPrimaryKey(true);
        $parentId->setNotNull(true);
        $parentTable->addColumn($parentId);

        $childTable = new Table('child');
        $childTable->setNamespace('Model');
        $database->addTable($childTable);

        $childId = new Column('parent_id');
        $childId->setDomain(new Domain('INTEGER'));
        $childId->setPrimaryKey(true);
        $childId->setNotNull(true);
        $childTable->addColumn($childId);

        $foreignKey = $childTable->addForeignKey(['foreignTable' => 'parent']);
        $foreignKey->addReference('parent_id', 'id');

        $builder = new TestableObjectBuilder($childTable);
        $builder->setGeneratorConfig(new QuickGeneratorConfig());
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addFKMutatorToScript($script, $foreignKey);

        $this->assertStringContainsString('public function associateParentWithoutInverseSync(ChildParent $v): void', $script);
        $this->assertStringContainsString('$this->aParent = $v;', $script);
        $this->assertStringContainsString('$this->associateParentWithoutInverseSync($v);', $script);
        $this->assertStringContainsString('$v->setChild($this);', $script);
    }

    /**
     * @return void
     */
    public function testHashRelevantRefFkDoAddAssociatesBeforeAppend()
    {
        $database = new Database('test');

        $parentTable = new Table('parent');
        $parentTable->setNamespace('Model');
        $database->addTable($parentTable);

        $parentId = new Column('id');
        $parentId->setDomain(new Domain('INTEGER'));
        $parentId->setPrimaryKey(true);
        $parentId->setNotNull(true);
        $parentTable->addColumn($parentId);

        $childTable = new Table('child');
        $childTable->setNamespace('Model');
        $database->addTable($childTable);

        $childId = new Column('parent_id');
        $childId->setDomain(new Domain('INTEGER'));
        $childId->setPrimaryKey(true);
        $childId->setNotNull(true);
        $childTable->addColumn($childId);

        $foreignKey = $childTable->addForeignKey(['foreignTable' => 'parent']);
        $foreignKey->addReference('parent_id', 'id');

        $builder = new TestableObjectBuilder($parentTable);
        $builder->setGeneratorConfig(new QuickGeneratorConfig());
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addRefFKDoAddToScript($script, $foreignKey);

        $this->assertStringContainsString('$child->associateParentWithoutInverseSync($this);', $script);
        $this->assertStringContainsString('$this->collChildren?->append($child);', $script);
        $this->assertStringNotContainsString('$child->setParent($this);', $script);
        $this->assertLessThan(
            strpos($script, '$this->collChildren?->append($child);'),
            strpos($script, '$child->associateParentWithoutInverseSync($this);')
        );
    }

    /**
     * @return void
     */
    public function testHashRelevantCrossRefDoAddUsesInternalAssociationHelpers()
    {
        $database = new Database('test');

        $bookTable = new Table('book');
        $bookTable->setNamespace('Model');
        $database->addTable($bookTable);

        $bookId = new Column('id');
        $bookId->setDomain(new Domain('INTEGER'));
        $bookId->setPrimaryKey(true);
        $bookId->setNotNull(true);
        $bookTable->addColumn($bookId);

        $listTable = new Table('book_club_list');
        $listTable->setNamespace('Model');
        $database->addTable($listTable);

        $listId = new Column('id');
        $listId->setDomain(new Domain('INTEGER'));
        $listId->setPrimaryKey(true);
        $listId->setNotNull(true);
        $listTable->addColumn($listId);

        $joinTable = new Table('book_list_rel');
        $joinTable->setNamespace('Model');
        $joinTable->setIsCrossRef(true);
        $database->addTable($joinTable);

        $joinBookId = new Column('book_id');
        $joinBookId->setDomain(new Domain('INTEGER'));
        $joinBookId->setPrimaryKey(true);
        $joinBookId->setNotNull(true);
        $joinTable->addColumn($joinBookId);

        $joinListId = new Column('book_club_list_id');
        $joinListId->setDomain(new Domain('INTEGER'));
        $joinListId->setPrimaryKey(true);
        $joinListId->setNotNull(true);
        $joinTable->addColumn($joinListId);

        $joinTable->addForeignKey(['foreignTable' => 'book'])->addReference('book_id', 'id');
        $joinTable->addForeignKey(['foreignTable' => 'book_club_list'])->addReference('book_club_list_id', 'id');
        $joinTable->setupReferrers();

        $builder = new TestableObjectBuilder($listTable);
        $builder->setGeneratorConfig(new QuickGeneratorConfig());
        $builder->setPlatform(new MysqlPlatform());

        $crossFks = $listTable->getCrossFks();
        $this->assertCount(1, $crossFks);

        $script = '';
        $builder->addCrossFKDoAddToScript($script, $crossFks[0]);

        $this->assertStringContainsString('$bookListRel->associateBookWithoutInverseSync($book);', $script);
        $this->assertStringContainsString('$bookListRel->associateBookClubListWithoutInverseSync($this);', $script);
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
    public function testSkipRefCodeSuppressesReverseObjectMethodsButKeepsForwardSetter()
    {
        $database = new Database('test');

        $authorTable = new Table('author');
        $authorTable->setNamespace('Model');
        $database->addTable($authorTable);

        $authorId = new Column('id');
        $authorId->setDomain(new Domain('INTEGER'));
        $authorId->setPrimaryKey(true);
        $authorTable->addColumn($authorId);

        $bookTable = new Table('book');
        $bookTable->setNamespace('Model');
        $database->addTable($bookTable);

        $bookId = new Column('id');
        $bookId->setDomain(new Domain('INTEGER'));
        $bookId->setPrimaryKey(true);
        $bookTable->addColumn($bookId);

        $bookAuthorId = new Column('author_id');
        $bookAuthorId->setDomain(new Domain('INTEGER'));
        $bookTable->addColumn($bookAuthorId);

        $bookTable->addForeignKey(['foreignTable' => 'author', 'skipRefCode' => 'true'])
            ->addReference('author_id', 'id');
        $bookTable->setupReferrers(true);

        $authorBuilder = new TestableObjectBuilder($authorTable);
        $authorBuilder->setGeneratorConfig(new QuickGeneratorConfig());
        $authorBuilder->setPlatform(new MysqlPlatform());

        $authorScript = '';
        $authorBuilder->addClassBodyToScript($authorScript);

        $this->assertStringNotContainsString('protected $collBooks', $authorScript);
        $this->assertStringNotContainsString('public function getBooks(', $authorScript);
        $this->assertStringNotContainsString('public function addBook(', $authorScript);
        $this->assertStringContainsString(
            'public function toArray(string $keyType = TableMap::TYPE_PHPNAME, bool $includeLazyLoadColumns = true, array $alreadyDumpedObjects = [], bool $includeForeignObjects = false): array',
            $authorScript,
        );

        $bookBuilder = new TestableObjectBuilder($bookTable);
        $bookBuilder->setGeneratorConfig(new QuickGeneratorConfig());
        $bookBuilder->setPlatform(new MysqlPlatform());

        $bookScript = '';
        $bookBuilder->addFKMutatorToScript($bookScript, $bookTable->getForeignKeys()[0]);

        $this->assertStringContainsString('public function setAuthor(', $bookScript);
        $this->assertStringNotContainsString('->addBook($this)', $bookScript);
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
    public function testFkRemoverResetsForeignKeyColumnsByDirectPropertyAssignment()
    {
        $database = new Database('test');

        $parentTable = new Table('parent');
        $parentTable->setNamespace('Model');
        $database->addTable($parentTable);

        $parentId = new Column('id');
        $parentId->setDomain(new Domain('INTEGER'));
        $parentId->setPrimaryKey(true);
        $parentId->setNotNull(true);
        $parentTable->addColumn($parentId);

        $childTable = new Table('child');
        $childTable->setNamespace('Model');
        $database->addTable($childTable);

        $childId = new Column('id');
        $childId->setDomain(new Domain('INTEGER'));
        $childId->setPrimaryKey(true);
        $childId->setNotNull(true);
        $childTable->addColumn($childId);

        $parentForeignKey = new Column('parent_id');
        $parentForeignKey->setDomain(new Domain('INTEGER'));
        $childTable->addColumn($parentForeignKey);
        $childTable->addForeignKey(['foreignTable' => 'parent'])
            ->addReference('parent_id', 'id');

        $builder = new TestableObjectBuilder($childTable);
        $builder->setGeneratorConfig(new QuickGeneratorConfig());
        $builder->setPlatform(new MysqlPlatform());

        $script = '';
        $builder->addFKRemoverToScript($script, $childTable->getForeignKeys()[0]);

        $this->assertStringContainsString('public function unsetParent()', $script);
        $this->assertStringContainsString('$this->parent_id = null;', $script);
        $this->assertStringContainsString('$this->aParent = null;', $script);
        $this->assertStringNotContainsString('$this->setParentId(', $script);
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

    /**
     * A partitioned table keeps a single-column model key, but its physical key is the pair
     * (model key, partition key). Without the partition key in the criteria the database cannot
     * prune, so every UPDATE, DELETE and reload has to visit every partition.
     *
     * @return void
     */
    public function testBuildPkeyCriteriaAddsPartitionKeyForPartitionedTable()
    {
        $schema = <<<EOF
<database name="test">
    <table name="event" partitionBy="RANGE" partitionKey="created_at" partitionPkMode="composite">
        <column name="id" primaryKey="true" type="VARCHAR" size="36"/>
        <column name="created_at" type="TIMESTAMP" required="true"/>
        <column name="payload" type="LONGVARCHAR"/>
    </table>
</database>
EOF;

        $script = $this->buildPkeyCriteriaBody($schema);

        $this->assertStringContainsString('$criteria->add(EventTableMap::COL_ID, $this->id);', $script);
        $this->assertStringContainsString(
            'if ($this->created_at !== null && !$this->isColumnModified(EventTableMap::COL_CREATED_AT)) {',
            $script,
        );
        $this->assertStringContainsString('$criteria->add(EventTableMap::COL_CREATED_AT, $this->created_at);', $script);
    }

    /**
     * The guard matters as much as the predicate: a modified partition key no longer identifies
     * the stored row, and on PostgreSQL updating it moves the row to another partition. Falling
     * back to the unpruned key is the correct behaviour there, so the value must never be added
     * unconditionally.
     *
     * @return void
     */
    public function testBuildPkeyCriteriaGuardsPartitionKeyAgainstNullAndModifiedValues()
    {
        $schema = <<<EOF
<database name="test">
    <table name="event" partitionBy="RANGE" partitionKey="created_at" partitionPkMode="composite">
        <column name="id" primaryKey="true" type="VARCHAR" size="36"/>
        <column name="created_at" type="TIMESTAMP" required="true"/>
    </table>
</database>
EOF;

        $script = $this->buildPkeyCriteriaBody($schema);

        $this->assertMatchesRegularExpression(
            '/if \(\$this->created_at !== null && !\$this->isColumnModified\(EventTableMap::COL_CREATED_AT\)\) \{\s*\$criteria->add\(/',
            $script,
        );
    }

    /**
     * @return void
     */
    public function testBuildPkeyCriteriaLeavesNonPartitionedTableUnchanged()
    {
        $schema = <<<EOF
<database name="test">
    <table name="event">
        <column name="id" primaryKey="true" type="VARCHAR" size="36"/>
        <column name="created_at" type="TIMESTAMP" required="true"/>
    </table>
</database>
EOF;

        $script = $this->buildPkeyCriteriaBody($schema);

        $this->assertStringContainsString('$criteria->add(EventTableMap::COL_ID, $this->id);', $script);
        $this->assertStringNotContainsString('COL_CREATED_AT', $script);
        $this->assertStringNotContainsString('isColumnModified', $script);
    }

    /**
     * When the partition key is already part of the primary key the parent loop has emitted it,
     * so it must not be added a second time.
     *
     * @return void
     */
    public function testBuildPkeyCriteriaDoesNotRepeatPartitionKeyAlreadyInPrimaryKey()
    {
        $schema = <<<EOF
<database name="test">
    <table name="event" partitionBy="RANGE" partitionKey="created_at" partitionPkMode="composite">
        <column name="id" primaryKey="true" type="VARCHAR" size="36"/>
        <column name="created_at" primaryKey="true" type="TIMESTAMP" required="true"/>
    </table>
</database>
EOF;

        $script = $this->buildPkeyCriteriaBody($schema);

        $this->assertSame(1, substr_count($script, 'EventTableMap::COL_CREATED_AT'));
        $this->assertStringNotContainsString('isColumnModified', $script);
    }

    /**
     * A primary key is not non-null per se: a uid key without a fallback value stays null on an
     * unsaved object, so the accessor has to be documented the way its body behaves.
     *
     * @return void
     */
    public function testPrimaryKeyAccessorWithoutUnsetValueIsDocumentedNullable()
    {
        $table = new Table('Transaction');

        $uuid = new Column('uuid');
        $uuid->setDomain(new Domain('UID'));
        $uuid->setPrimaryKey(true);
        $uuid->setNotNull(true);
        $uuid->setDefaultValue(new ColumnDefaultValue('uuidv7()', ColumnDefaultValue::TYPE_EXPR));
        $table->addColumn($uuid);

        $builder = new TestableObjectBuilder($table);
        $builder->setPlatform(new PgsqlPlatform());

        $comment = '';
        $builder->addDefaultAccessorCommentToScript($comment, $uuid);

        $body = '';
        $builder->addDefaultAccessorBodyToScript($body, $uuid);

        $this->assertStringContainsString('|null', $comment);
        $this->assertStringContainsString('return $this->uuid;', $body);
        $this->assertStringNotContainsString('??', $body);
    }

    /**
     * The attribute of a column does not always hold the value in the PHP type of the column,
     * some types are stored encoded and only converted in the accessor.
     *
     * @return array<string, array{string, string}>
     */
    public static function columnStorageTypeProvider(): array
    {
        return [
            'int columns are stored as int' => ['<column name="col" type="INTEGER"/>', 'int|null'],
            'string columns are stored as string' => ['<column name="col" type="VARCHAR" size="10"/>', 'string|null'],
            // a native array is the one array type which is not encoded
            'native array columns are stored as array' => ['<column name="col" type="NATIVE_ARRAY" sqlType="TEXT[]"/>', 'array|null'],
            'array columns are stored as string' => ['<column name="col" type="ARRAY"/>', 'string|null'],
            'object columns are stored as stream' => ['<column name="col" type="OBJECT"/>', 'resource|null'],
            'enum columns are stored as int' => ['<column name="col" type="ENUM" valueSet="a, b"/>', 'int|null'],
            // SetColumnConverter::convertToInt() returns the bitmask as a string
            'set columns are stored as string' => ['<column name="col" type="SET" valueSet="a, b"/>', 'string|null'],
        ];
    }

    /**
     * @dataProvider columnStorageTypeProvider
     *
     * @param string $columnXml
     * @param string $expectedType
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('columnStorageTypeProvider')]
    public function testColumnAttributeIsDocumentedWithItsStorageType(string $columnXml, string $expectedType)
    {
        $script = $this->buildObjectScript($this->wrapColumnsInSchema($columnXml), 'addColumnAttributes');

        $this->assertStringContainsString("@var $expectedType\n     */\n    protected \$col;", $script);
    }

    /**
     * @return void
     */
    public function testSetColumnConvertedAttributeIsDocumented()
    {
        $columnXml = '<column name="col" type="SET" valueSet="a, b"/>';
        $script = $this->buildObjectScript($this->wrapColumnsInSchema($columnXml), 'addColumnAttributes');

        $this->assertStringContainsString("@var array|null\n     */\n    protected \$col_converted;", $script);
    }

    /**
     * Array default values are exported over several lines, which used to break out of the comment.
     *
     * @return void
     */
    public function testArrayDefaultValueCommentStaysOnASingleLine()
    {
        $columnXml = '<column name="col" type="NATIVE_ARRAY" sqlType="TEXT[]" defaultValue="{}"/>';
        $script = $this->buildObjectScript($this->wrapColumnsInSchema($columnXml), 'addColumnAttributes');

        $this->assertStringContainsString('     * Note: this column has a database default value of: array ( )', $script);
        foreach (explode("\n", $script) as $line) {
            if ($line !== '' && !str_starts_with(ltrim($line), '*') && !str_starts_with(ltrim($line), '/*')) {
                $this->assertStringNotContainsString('database default value', $line);
            }
        }
    }

    /**
     * The attribute holds the value bitmask, which is not a primitive int, so the set branch has to
     * be applied instead of the primitive one.
     *
     * @return void
     */
    public function testSetColumnHydrationResetsTheConvertedAttribute()
    {
        $columnXml = '<column name="col" type="SET" valueSet="a, b"/>';
        $script = $this->buildObjectScript($this->wrapColumnsInSchema($columnXml), 'addHydrate');

        $this->assertStringContainsString('$this->col = $col;', $script);
        $this->assertStringContainsString('$this->col_converted = null;', $script);
        $this->assertStringNotContainsString('(int) $col', $script);
    }

    /**
     * @return void
     */
    public function testCopyIsDocumentedWithStaticReturnTypeInsteadOfVarAnnotation()
    {
        $script = $this->buildObjectScript($this->wrapColumnsInSchema(''), 'addCopy');

        $this->assertStringContainsString('@return static Clone of current object', $script);
        $this->assertStringContainsString('$copyObj = new $clazz();', $script);
        // the annotation contradicted the type of `new $clazz()`, which is `$this`
        $this->assertStringNotContainsString('$copyObj */', $script);
        // copyInto() receives `$this`, so it cannot ask for the child class
        $this->assertStringContainsString('@param static $copyObj', $script);
    }

    /**
     * @return void
     */
    public function testToStringFallbackDoesNotCastTheStringItGetsFromExportTo()
    {
        $script = $this->buildObjectScript($this->wrapColumnsInSchema(''), 'addPrimaryString');

        $this->assertStringContainsString('return $this->exportTo(ItemTableMap::DEFAULT_STRING_FORMAT);', $script);
        $this->assertStringNotContainsString('(string) $this->exportTo(', $script);
    }

    /**
     * @return void
     */
    public function testResetPartialDocumentsItsParameter()
    {
        $schema = <<<'EOF'
<database name="test">
    <table name="item">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
    </table>
    <table name="item_part">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
        <column name="item_id" type="INTEGER"/>
        <foreign-key foreignTable="item">
            <reference local="item_id" foreign="id"/>
        </foreign-key>
    </table>
</database>
EOF;

        $platform = new PgsqlPlatform();
        $table = (new SchemaReader($platform))->parseString($schema)->getDatabase()->getTable('item');

        $builder = new TestableObjectBuilder($table);
        $builder->setGeneratorConfig(new QuickGeneratorConfig());
        $builder->setPlatform($platform);

        $script = '';
        $builder->addRefFKPartialToScript($script, $table->getReferrers()[0]);

        $this->assertStringContainsString('@param bool $v', $script);
    }

    /**
     * @param string $columnsXml
     *
     * @return string
     */
    private function wrapColumnsInSchema(string $columnsXml): string
    {
        return <<<EOF
<database name="test">
    <table name="item">
        <column name="id" primaryKey="true" type="INTEGER" autoIncrement="true"/>
        $columnsXml
    </table>
</database>
EOF;
    }

    /**
     * @param string $schema
     * @param string $scriptBuilderFunctionName
     *
     * @return string
     */
    private function buildObjectScript(string $schema, string $scriptBuilderFunctionName): string
    {
        $platform = new PgsqlPlatform();
        $table = (new SchemaReader($platform))->parseString($schema)->getDatabase()->getTable('item');

        $builder = new TestableObjectBuilder($table);
        $builder->setGeneratorConfig(new QuickGeneratorConfig());
        $builder->setPlatform($platform);

        return $builder->buildScript($scriptBuilderFunctionName);
    }

    /**
     * @return void
     */
    private function buildPkeyCriteriaBody(string $schema): string
    {
        $platform = new PgsqlPlatform();
        $schemaReader = new SchemaReader($platform);
        $table = $schemaReader->parseString($schema)->getDatabase()->getTable('event');

        $builder = new TestableObjectBuilder($table);
        $builder->setGeneratorConfig(new QuickGeneratorConfig());
        $builder->setPlatform($platform);

        $script = '';
        $builder->addBuildPkeyCriteriaBodyToScript($script);

        return $script;
    }

}

class TestableObjectBuilder extends ObjectBuilder
{
    /**
     * Call a (usually protected) script builder function by name and return the result.
     *
     * @param string $scriptBuilderFunctionName
     *
     * @return string
     */
    public function buildScript(string $scriptBuilderFunctionName): string
    {
        $script = '';
        $this->$scriptBuilderFunctionName($script);

        return $script;
    }

    public function addRefFKPartialToScript(string &$script, ForeignKey $foreignKey): void
    {
        $this->addRefFKPartial($script, $foreignKey);
    }

    public function getDefaultValueString(Column $col, bool $acceptNull = true): string
    {
        return parent::getDefaultValueString($col, $acceptNull);
    }

    public function addBuildPkeyCriteriaBodyToScript(string &$script): void
    {
        $this->addBuildPkeyCriteriaBody($script);
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

    public function addPhpTypeMethodsToScript(string &$script): void
    {
        $this->addPhpTypeMethods($script);
    }

    public function addConvertValueToObjectTypeMethodToScript(string &$script): void
    {
        $this->addConvertValueToObjectTypeMethod($script);
    }

    public function addAreObjectTypeValuesEqualMethodToScript(string &$script): void
    {
        $this->addAreObjectTypeValuesEqualMethod($script);
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

    public function addFKRemoverToScript(
        string &$script,
        \Propel\Generator\Model\ForeignKey $foreignKey
    ): void {
        $this->addFKRemover($script, $foreignKey);
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

    public function addCrossFKDoAddToScript(
        string &$script,
        \Propel\Generator\Model\CrossForeignKeys $crossForeignKeys
    ): void {
        $this->addCrossFKDoAdd($script, $crossForeignKeys);
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

    public function addColumnAccessorMethodsToScript(string &$script): void
    {
        $this->addColumnAccessorMethods($script);
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

    public function addColumnMutatorMethodsToScript(string &$script): void
    {
        $this->addColumnMutatorMethods($script);
    }
}
