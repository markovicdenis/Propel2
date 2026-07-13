<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Platform;

use PHPUnit\Framework\TestCase;
use Propel\Generator\Builder\Util\SchemaReader;
use Propel\Generator\Exception\EngineException;
use Propel\Generator\Platform\MysqlPlatform;
use Propel\Generator\Platform\PgsqlPlatform;
use Propel\Generator\Platform\PlatformInterface;
use Propel\Generator\Platform\SqlitePlatform;

class NativeArrayPlatformTest extends TestCase
{
    /**
     * @return void
     */
    public function testPgsqlBuildsNativeArrayDdl(): void
    {
        $platform = new PgsqlPlatform();
        $table = $this->readTable($platform, 'sqlType="UUID[]"');

        $this->assertStringContainsString('UUID[]', $platform->getAddTableDDL($table));
    }

    /**
     * @return void
     */
    public function testPgsqlDefaultsNativeArrayToTextArray(): void
    {
        $platform = new PgsqlPlatform();
        $table = $this->readTable($platform);

        $this->assertStringContainsString('TEXT[]', $platform->getAddTableDDL($table));
    }

    /**
     * @return void
     */
    public function testNativeArrayIsRejectedByOtherPlatforms(): void
    {
        $platform = new SqlitePlatform();
        $table = $this->readTable($platform);

        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('only supported by PostgreSQL');

        $platform->getAddTableDDL($table);
    }

    /**
     * @return void
     */
    public function testNativeArrayIsRejectedByMysql(): void
    {
        $platform = new MysqlPlatform();
        $table = $this->readTable($platform);

        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('only supported by PostgreSQL');

        $platform->getAddTableDDL($table);
    }

    /**
     * @return void
     */
    public function testMigrationConvertsLegacyArrayEncoding(): void
    {
        $platform = new PgsqlPlatform();
        $legacyColumn = $this->readColumn($platform, 'ARRAY');
        $nativeColumn = $this->readColumn($platform, 'NATIVE_ARRAY', 'sqlType="INTEGER[]"');

        $toNative = $platform->getUsingCast($legacyColumn, $nativeColumn);
        $toLegacy = $platform->getUsingCast($nativeColumn, $legacyColumn);

        $this->assertStringContainsString('string_to_array(substring(values FROM 3', $toNative);
        $this->assertStringContainsString('::INTEGER[]', $toNative);
        $this->assertStringContainsString("array_to_string(values, ' | ', '')", $toLegacy);
    }

    /**
     * @param \Propel\Generator\Platform\PlatformInterface $platform
     * @param string $extraAttributes
     *
     * @return \Propel\Generator\Model\Table
     */
    private function readTable(PlatformInterface $platform, string $extraAttributes = '')
    {
        $reader = new SchemaReader($platform);
        $schema = sprintf(<<<'XML'
<database name="native_array_test">
    <table name="native_array_item">
        <column name="id" type="INTEGER" primaryKey="true"/>
        <column name="values" type="NATIVE_ARRAY" %s/>
    </table>
</database>
XML, $extraAttributes);

        return $reader->parseString($schema)->getDatabase()->getTable('native_array_item');
    }

    /**
     * @param \Propel\Generator\Platform\PlatformInterface $platform
     * @param string $type
     * @param string $extraAttributes
     *
     * @return \Propel\Generator\Model\Column
     */
    private function readColumn(PlatformInterface $platform, string $type, string $extraAttributes = '')
    {
        $reader = new SchemaReader($platform);
        $schema = sprintf(<<<'XML'
<database name="native_array_test">
    <table name="native_array_item">
        <column name="id" type="INTEGER" primaryKey="true"/>
        <column name="values" type="%s" %s/>
    </table>
</database>
XML, $type, $extraAttributes);

        return $reader->parseString($schema)->getDatabase()->getTable('native_array_item')->getColumn('values');
    }
}
