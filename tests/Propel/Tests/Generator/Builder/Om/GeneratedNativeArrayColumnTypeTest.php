<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Builder\Om;

use PHPUnit\Framework\TestCase;
use Propel\Generator\Platform\PgsqlPlatform;
use Propel\Generator\Util\QuickBuilder;

class GeneratedNativeArrayColumnTypeTest extends TestCase
{
    /**
     * @return string
     */
    private function buildClasses(): string
    {
        $builder = new QuickBuilder();
        $builder->setPlatform(new PgsqlPlatform());
        $builder->setSchema(<<<'XML'
<database name="native_array_test">
    <table name="native_array_item">
        <column name="id" type="INTEGER" primaryKey="true"/>
        <column name="tags" type="NATIVE_ARRAY" sqlType="TEXT[]"/>
        <column name="scores" type="NATIVE_ARRAY" sqlType="INTEGER[]" required="true"/>
    </table>
</database>
XML);

        return $builder->getClasses();
    }

    /**
     * @return void
     */
    public function testGeneratedObjectUsesNativeArrayCodec(): void
    {
        $classes = $this->buildClasses();

        $this->assertStringContainsString("PgsqlArrayCodec::decode(\$col, 'TEXT')", $classes);
        $this->assertStringContainsString("PgsqlArrayCodec::decode(\$col, 'INTEGER')", $classes);
        $this->assertStringContainsString('PgsqlArrayCodec::encode($this->tags)', $classes);
        $this->assertStringContainsString('function addTag($value', $classes);
        $this->assertStringContainsString('function removeTag($value', $classes);
    }

    /**
     * @return void
     */
    public function testGeneratedQueryUsesNativeArrayOperators(): void
    {
        $classes = $this->buildClasses();

        $this->assertStringContainsString('Criteria::ARRAY_CONTAINS', $classes);
        $this->assertStringContainsString('Criteria::ARRAY_OVERLAPS', $classes);
        $this->assertStringContainsString('Criteria::ARRAY_NOT_OVERLAPS', $classes);
        $this->assertStringContainsString('function filterByTag(', $classes);
    }
}
