<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Util;

use Propel\Generator\Util\SqlPredicateNormalizer;
use Propel\Tests\TestCase;

/**
 * Tests for the SqlPredicateNormalizer service class.
 */
class SqlPredicateNormalizerTest extends TestCase
{
    /**
     * Predicates as they are declared in a schema, together with the form PostgreSQL reports for
     * them in pg_get_expr() once the index exists.
     *
     * @return array<array<string>>
     */
    public static function equalPredicateProvider(): array
    {
        return [
            ["status = 'open'", "status::text = 'open'::text"],
            ["status = 'settled'", "status::text = 'settled'::text"],
            [
                "status IN ('pending', 'accepted')",
                "status::text = ANY (ARRAY['pending'::character varying, 'accepted'::character varying]::text[])",
            ],
            [
                "status IN ('pending','accepted')",
                "status::text = ANY (ARRAY['pending'::character varying, 'accepted'::character varying]::text[])",
            ],
            ['wagering_id IS NOT NULL', 'wagering_id IS NOT NULL'],
            ['status = 4 AND balance_operation = 1', 'status = 4 AND balance_operation = 1'],
            ['status=4 and balance_operation=1', 'status = 4 AND balance_operation = 1'],
            ["(status = 'open')", "status::text = 'open'::text"],
            ['amount > 0', 'amount > 0::numeric'],
            ['amount >= 10.5 AND qty < 100', 'amount >= 10.5 AND qty < 100'],
            // A single value list, LIKE and NOT IN are stored under a different form.
            ["status IN ('only')", "status::text = 'only'::text"],
            ["note LIKE 'x%'", "note ~~ 'x%'::text"],
            ["note NOT LIKE 'x%'", "note !~~ 'x%'::text"],
            [
                "status NOT IN ('a', 'b')",
                "status::text <> ALL (ARRAY['a'::character varying, 'b'::character varying]::text[])",
            ],
            ['created_at IS NULL', 'created_at IS NULL'],
            [null, null],
        ];
    }

    /**
     * @dataProvider equalPredicateProvider
     *
     * @param string|null $schemaPredicate
     * @param string|null $databasePredicate
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('equalPredicateProvider')]
    public function testDescribesTheSameConditionAsTheDatabase(?string $schemaPredicate, ?string $databasePredicate): void
    {
        $this->assertTrue(SqlPredicateNormalizer::areEqual($schemaPredicate, $databasePredicate));
    }

    /**
     * @return array<array<string|null>>
     */
    public static function differentPredicateProvider(): array
    {
        return [
            ["status = 'open'", "status::text = 'closed'::text"],
            ['status = 4 AND balance_operation = 1', 'status = 4'],
            ['wagering_id IS NOT NULL', 'wagering_id IS NULL'],
            ["status IN ('pending')", "status::text = ANY (ARRAY['pending'::character varying, 'accepted'::character varying]::text[])"],
            ["status = 'open'", null],
            [null, 'status = 4'],
            // A cast inside a string literal is part of the value, not a cast.
            ["note = 'a::text'", "note::text = 'a'::text"],
        ];
    }

    /**
     * @dataProvider differentPredicateProvider
     *
     * @param string|null $predicate
     * @param string|null $otherPredicate
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('differentPredicateProvider')]
    public function testDescribesADifferentCondition(?string $predicate, ?string $otherPredicate): void
    {
        $this->assertFalse(SqlPredicateNormalizer::areEqual($predicate, $otherPredicate));
    }

    /**
     * @return void
     */
    public function testEmptyPredicateIsNoPredicate(): void
    {
        $this->assertNull(SqlPredicateNormalizer::normalize(''));
        $this->assertNull(SqlPredicateNormalizer::normalize('   '));
        $this->assertNull(SqlPredicateNormalizer::normalize(null));
    }

    /**
     * @return void
     */
    public function testKeepsQuotedIdentifiersAsTheyAreWritten(): void
    {
        $this->assertTrue(SqlPredicateNormalizer::areEqual('"myColumn" = 1', '"myColumn" = 1'));
        $this->assertFalse(SqlPredicateNormalizer::areEqual('"myColumn" = 1', '"mycolumn" = 1'));
    }
}
