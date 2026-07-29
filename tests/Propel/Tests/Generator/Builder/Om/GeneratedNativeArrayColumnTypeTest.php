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
        <column name="currency_codes" type="NATIVE_ARRAY" sqlType="VARCHAR(3)[]" transformer="uppercase"/>
        <column name="legacy_currency_codes" type="ARRAY" transformer="lowercase"/>
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
        $this->assertStringContainsString(
            'static fn ($value) => $value === null ? null : strtoupper($value)',
            $classes,
        );
        $this->assertStringContainsString(
            'static fn ($value) => $value === null ? null : strtolower($value)',
            $classes,
        );
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
        $this->assertStringContainsString('static fn ($value) => $value === null ? null : strtoupper($value)', $classes);
    }

    /**
     * @return void
     */
    public function testGeneratedTableMapRetainsNativeArrayElementTypes(): void
    {
        $classes = $this->buildClasses();

        $this->assertStringContainsString("->setNativeArrayElementType('TEXT')", $classes);
        $this->assertStringContainsString("->setNativeArrayElementType('INTEGER')", $classes);
        $this->assertStringContainsString("->setNativeArrayElementType('VARCHAR(3)')", $classes);
    }

    /**
     * Unlike an ARRAY column, a native array is not stored in its encoded form.
     *
     * @return void
     */
    public function testGeneratedObjectStoresNativeArrayColumnsAsArray(): void
    {
        $classes = $this->buildClasses();

        $this->assertStringContainsString("@var array|null\n     */\n    protected \$tags;", $classes);
        $this->assertStringContainsString("@var array|null\n     */\n    protected \$scores;", $classes);
        // an ARRAY column is stored as the encoded string, with the array in $legacy_currency_codes_unserialized
        $this->assertStringContainsString("@var string|null\n     */\n    protected \$legacy_currency_codes;", $classes);
    }
}
