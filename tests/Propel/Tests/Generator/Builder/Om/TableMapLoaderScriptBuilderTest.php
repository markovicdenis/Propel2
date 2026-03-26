<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Builder\Om;

use Propel\Generator\Builder\Om\TableMapLoaderScriptBuilder;
use Propel\Generator\Builder\Util\SchemaReader;
use Propel\Generator\Config\QuickGeneratorConfig;
use Propel\Tests\TestCase;

class TableMapLoaderScriptBuilderTest extends TestCase
{
    /**
     * @return void
     */
    public function testBuildUsesShortArraySyntaxForTableMapDumps()
    {
        $databaseXml = <<<'XML'
<database name="bookstore" namespace="ExampleNamespace\Bookstore" package="Bookstore">
    <table name="author" phpName="Author">
        <column name="id" type="INTEGER" primaryKey="true" required="true"/>
    </table>
</database>
XML;

        $schema = (new SchemaReader())->parseString($databaseXml);
        $builder = new TableMapLoaderScriptBuilder(new QuickGeneratorConfig());

        $script = $builder->build([$schema]);

        $this->assertStringContainsString('$serviceContainer->initDatabaseMapFromDumps([', $script);
        $this->assertStringNotContainsString('initDatabaseMapFromDumps(array (', $script);
        $this->assertStringContainsString("\n    'bookstore' => [\n        'tablesByName' => [\n            'author' => '\\\\ExampleNamespace\\\\Bookstore\\\\Map\\\\AuthorTableMap',", $script);
    }
}