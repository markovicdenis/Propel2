<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\ActiveQuery\Criterion;

use PHPUnit\Framework\TestCase;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Criterion\NativeArrayCriterion;

class NativeArrayCriterionTest extends TestCase
{
    /**
     * @return void
     */
    public function testContainsCriterion(): void
    {
        $criterion = new NativeArrayCriterion(new Criteria(), 'items.tags', ['one'], Criteria::ARRAY_CONTAINS);
        $params = [];
        $sql = '';

        $criterion->appendPsTo($sql, $params);

        $this->assertSame('items.tags @> :p1', $sql);
        $this->assertSame(['one'], $params[0]['value']);
        $this->assertSame('items', $params[0]['table']);
        $this->assertSame('tags', $params[0]['column']);
    }

    /**
     * @return void
     */
    public function testNotOverlapsIncludesNullArrays(): void
    {
        $criterion = new NativeArrayCriterion(new Criteria(), 'items.tags', ['one'], Criteria::ARRAY_NOT_OVERLAPS);
        $params = [];
        $sql = '';

        $criterion->appendPsTo($sql, $params);

        $this->assertSame('(items.tags IS NULL OR NOT (items.tags && :p1))', $sql);
    }
}
