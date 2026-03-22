<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Builder\Om;

use Propel\Generator\Builder\Om\TableMapBuilder;
use Propel\Generator\Builder\Util\SchemaReader;
use Propel\Generator\Config\QuickGeneratorConfig;
use Propel\Generator\Util\QuickBuilder;
use Propel\Runtime\Map\TableMap;
use Propel\Tests\TestCase;

class TableMapBuilderOutputTest extends TestCase
{
    /**
     * @return void
     */
    public function testNormalizedColumnMapFallsBackToRawColumnNames()
    {
        $databaseXml = <<<XML
<database namespace="ExampleNamespace\Emails" package="Emails">
    <table name="email">
        <column name="id" type="integer"/>
        <column name="email_address" type="varchar"/>
    </table>
</database>
XML;
        $builder = new QuickBuilder();
        $builder->setSchema($databaseXml);
        $builder->build();

        $tableMapClass = '\\ExampleNamespace\\Emails\\Map\\EmailTableMap';

        $this->assertTrue(\class_exists($tableMapClass));

        $tableMap = $tableMapClass::getTableMap();

        $this->assertSame('email_address', $tableMap->getColumn('email_address')->getName());
        $this->assertSame('email_address', $tableMap->getColumn('email.email_address')->getName());
        $this->assertSame('email_address', $tableMap->getColumn('emailAddress')->getName());
        $this->assertSame('email_address', $tableMap->getColumn('Email.EmailAddress')->getName());
        $this->assertSame('email_address', $tableMap->getColumn('COL_EMAIL_ADDRESS')->getName());
        $this->assertSame('email_address', $tableMap->getColumn('EmailTableMap::COL_EMAIL_ADDRESS')->getName());
    }

    /**
     * @return void
     */
    public function testSelectMethodsReuseSharedColumnList()
    {
        $databaseXml = '
<database>
    <table name="email">
        <column name="id" type="integer"/>
        <column name="email_address" type="varchar"/>
    </table>
</database>
';
        $reader = new SchemaReader();
        $schema = $reader->parseString($databaseXml);
        $table = $schema->getDatabase()->getTable('email');

        $tableMapBuilder = new class ($table) extends TableMapBuilder {
            public function getSelectMethodsDefinition(): string
            {
                $script = '';
                $this->addSelectMethods($script);

                return $script;
            }
        };
        $tableMapBuilder->setGeneratorConfig(new QuickGeneratorConfig());
        $selectMethodsDefinition = $tableMapBuilder->getSelectMethodsDefinition();

        $this->assertStringContainsString('foreach (self::ALL_COLUMNS as $column)', $selectMethodsDefinition);
        $this->assertStringContainsString('$criteria->addSelectColumn($alias === null ? $column : self::alias($alias, $column));', $selectMethodsDefinition);
        $this->assertStringContainsString('$criteria->removeSelectColumn($alias === null ? $column : self::alias($alias, $column));', $selectMethodsDefinition);
        $this->assertStringContainsString('self::alias($alias, $column)', $selectMethodsDefinition);
        $this->assertStringNotContainsString('if (!in_array($column, self::LAZY_COLUMNS, true)) {', $selectMethodsDefinition);
        $this->assertSame(1, substr_count($selectMethodsDefinition, '$criteria->addSelectColumn('));
        $this->assertSame(1, substr_count($selectMethodsDefinition, '$criteria->removeSelectColumn('));
    }

    /**
     * @return void
     */
    public function testSelectMethodsSkipLazyColumnsWhenPresent()
    {
        $databaseXml = '
<database>
    <table name="email">
        <column name="id" type="integer"/>
        <column name="email_address" type="varchar"/>
        <column name="body" type="longvarchar" lazyLoad="true"/>
    </table>
</database>
';
        $reader = new SchemaReader();
        $schema = $reader->parseString($databaseXml);
        $table = $schema->getDatabase()->getTable('email');

        $tableMapBuilder = new class ($table) extends TableMapBuilder {
            public function getSelectMethodsDefinition(): string
            {
                $script = '';
                $this->addSelectMethods($script);

                return $script;
            }
        };
        $tableMapBuilder->setGeneratorConfig(new QuickGeneratorConfig());
        $selectMethodsDefinition = $tableMapBuilder->getSelectMethodsDefinition();

        $this->assertStringContainsString('if (!in_array($column, self::LAZY_COLUMNS, true)) {', $selectMethodsDefinition);
        $this->assertSame(1, substr_count($selectMethodsDefinition, '$criteria->addSelectColumn('));
        $this->assertSame(1, substr_count($selectMethodsDefinition, '$criteria->removeSelectColumn('));
    }

    /**
     * @return void
     */
    public function testSelectColumnsConstantIsPlacedWithOtherConstants()
    {
        $databaseXml = '
<database>
    <table name="email">
        <column name="id" type="integer"/>
        <column name="email_address" type="varchar"/>
    </table>
</database>
';
        $reader = new SchemaReader();
        $schema = $reader->parseString($databaseXml);
        $table = $schema->getDatabase()->getTable('email');

        $tableMapBuilder = new class ($table) extends TableMapBuilder {
            public function getConstantsAndAttributesDefinition(): string
            {
                $script = '';
                $table = $this->getTable();

                $script .= $this->addConstants();
                $this->addInheritanceColumnConstants($script);
                if ($table->hasValueSetColumns()) {
                    $this->addValueSetColumnConstants($script);
                }

                $this->applyBehaviorModifier('staticConstants', $script, '    ');
                if (!$table->isAlias()) {
                    $this->addSelectColumnsConstant($script);
                }
                $this->applyBehaviorModifier('staticAttributes', $script, '    ');
                $script .= $this->addFieldsAttributes();

                return $script;
            }
        };
        $tableMapBuilder->setGeneratorConfig(new QuickGeneratorConfig());
        $classBodyDefinition = $tableMapBuilder->getConstantsAndAttributesDefinition();

        $allColumnsPosition = strpos($classBodyDefinition, 'public const array ALL_COLUMNS = [self::COL_ID, self::COL_EMAIL_ADDRESS];');
        $lazyColumnsPosition = strpos($classBodyDefinition, 'public const array LAZY_COLUMNS = [];');
        $fieldNamesPosition = strpos($classBodyDefinition, 'protected static array $fieldNames = [');

        $this->assertNotFalse($allColumnsPosition);
        $this->assertNotFalse($lazyColumnsPosition);
        $this->assertNotFalse($fieldNamesPosition);
        $this->assertLessThan($fieldNamesPosition, $allColumnsPosition);
        $this->assertLessThan($fieldNamesPosition, $lazyColumnsPosition);
    }

    /**
     * @return void
     */
    public function testGeneratedTableMapCanResolvePropertyNames()
    {
        $databaseXml = <<<XML
<database namespace="ExampleNamespace\PropertyNames" package="PropertyNames">
    <table name="property_email">
        <column name="id" type="integer"/>
        <column name="email_address" type="varchar"/>
    </table>
</database>
XML;
        $builder = new QuickBuilder();
        $builder->setSchema($databaseXml);
        $builder->build();

        $tableMapClass = '\\ExampleNamespace\\PropertyNames\\Map\\PropertyEmailTableMap';

        $this->assertSame('email_address', $tableMapClass::getPropertyName($tableMapClass::COL_EMAIL_ADDRESS));
        $this->assertSame('email_address', $tableMapClass::getPropertyName('EmailAddress', TableMap::TYPE_PHPNAME));
    }

    /**
     * @return void
     */
    public function testAllColumnsConstantIncludesLazyLoadColumns()
    {
        $databaseXml = '
<database>
    <table name="email">
        <column name="id" type="integer"/>
        <column name="email_address" type="varchar"/>
        <column name="body" type="longvarchar" lazyLoad="true"/>
    </table>
</database>
';
        $reader = new SchemaReader();
        $schema = $reader->parseString($databaseXml);
        $table = $schema->getDatabase()->getTable('email');

        $tableMapBuilder = new class ($table) extends TableMapBuilder {
            public function getColumnConstantsDefinition(): string
            {
                $script = '';
                $this->addSelectColumnsConstant($script);

                return $script;
            }
        };
        $tableMapBuilder->setGeneratorConfig(new QuickGeneratorConfig());
        $columnConstantsDefinition = $tableMapBuilder->getColumnConstantsDefinition();

        $this->assertStringContainsString(
            'public const array ALL_COLUMNS = [self::COL_ID, self::COL_EMAIL_ADDRESS, self::COL_BODY];',
            $columnConstantsDefinition
        );
        $this->assertStringContainsString(
            'public const array LAZY_COLUMNS = [self::COL_BODY];',
            $columnConstantsDefinition
        );
    }

    /**
     * @return void
     */
    public function testFieldMapsUseTypedStaticArrays()
    {
        $databaseXml = '
<database>
    <table name="email">
        <column name="id" type="integer"/>
        <column name="email_address" type="varchar"/>
    </table>
</database>
';
        $reader = new SchemaReader();
        $schema = $reader->parseString($databaseXml);
        $table = $schema->getDatabase()->getTable('email');

        $tableMapBuilder = new class ($table) extends TableMapBuilder {
            public function getFieldAttributesDefinition(): string
            {
                return $this->addFieldsAttributes();
            }
        };
        $tableMapBuilder->setGeneratorConfig(new QuickGeneratorConfig());
        $fieldAttributesDefinition = $tableMapBuilder->getFieldAttributesDefinition();

        $this->assertStringContainsString('protected static array $fieldNames = [', $fieldAttributesDefinition);
        $this->assertStringContainsString('protected static array $fieldKeys = [', $fieldAttributesDefinition);
        $this->assertStringContainsString('self::COL_EMAIL_ADDRESS', $fieldAttributesDefinition);
    }

    /**
     * @return void
     */
    public function testGeneratedPhpDocUsesValidWrappedTags()
    {
        $databaseXml = '
<database>
    <table name="email">
        <column name="id" type="integer"/>
        <column name="email_address" type="varchar"/>
    </table>
</database>
';
        $reader = new SchemaReader();
        $schema = $reader->parseString($databaseXml);
        $table = $schema->getDatabase()->getTable('email');

        $tableMapBuilder = new class ($table) extends TableMapBuilder {
            public function getPopulateObjectDefinition(): string
            {
                $script = '';
                $this->addPopulateObject($script);

                return $script;
            }

            public function getGetTableMapDefinition(): string
            {
                $script = '';
                $this->addGetTableMap($script);

                return $script;
            }
        };
        $tableMapBuilder->setGeneratorConfig(new QuickGeneratorConfig());

        $populateObjectDefinition = $tableMapBuilder->getPopulateObjectDefinition();
        $getTableMapDefinition = $tableMapBuilder->getGetTableMapDefinition();

        $this->assertStringContainsString(
            "     * @param string \$indexType The index type of \$row. Mostly DataFetcher->getIndexType().\n"
            . "     *     One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME,\n"
            . "     *     TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.",
            $populateObjectDefinition
        );
        $this->assertStringContainsString('     * @throws \Propel\Runtime\Exception\PropelException', $populateObjectDefinition);
        $this->assertStringContainsString('     * @throws \Propel\Runtime\Exception\PropelException', $getTableMapDefinition);
        $this->assertStringNotContainsString('Any exceptions caught during processing will be', $populateObjectDefinition);
        $this->assertStringNotContainsString('rethrown wrapped into a PropelException.', $populateObjectDefinition);
        $this->assertStringNotContainsString("\n                                 One of the class type constants", $populateObjectDefinition);
    }

    /**
     * @return void
     */
    public function testPopulateObjectUsesSharedInstanceCreationHook()
    {
        $databaseXml = '
<database>
    <table name="email">
        <column name="id" type="integer"/>
        <column name="email_address" type="varchar"/>
    </table>
</database>
';
        $reader = new SchemaReader();
        $schema = $reader->parseString($databaseXml);
        $table = $schema->getDatabase()->getTable('email');

        $tableMapBuilder = new class ($table) extends TableMapBuilder {
            public function getPopulateObjectDefinition(): string
            {
                $script = '';
                $this->addPopulateObject($script);

                return $script;
            }

            public function buildObjectInstanceCreationCode(string $objName, string $clsName): string
            {
                return "$objName = self::customInstantiate($clsName);";
            }
        };
        $tableMapBuilder->setGeneratorConfig(new QuickGeneratorConfig());

        $populateObjectDefinition = $tableMapBuilder->getPopulateObjectDefinition();

        $this->assertStringContainsString('$obj = self::customInstantiate($cls);', $populateObjectDefinition);
    }
}
