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

        $tableMap = \ExampleNamespace\Emails\Map\EmailTableMap::getTableMap();

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

        $this->assertStringContainsString('private const array SELECT_COLUMNS = [self::COL_ID, self::COL_EMAIL_ADDRESS];', $selectMethodsDefinition);
        $this->assertStringContainsString('foreach (self::SELECT_COLUMNS as $column)', $selectMethodsDefinition);
        $this->assertStringContainsString('self::alias($alias, $column)', $selectMethodsDefinition);
        $this->assertSame(1, substr_count($selectMethodsDefinition, '$criteria->addSelectColumn('));
        $this->assertSame(1, substr_count($selectMethodsDefinition, '$criteria->removeSelectColumn('));
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
}
