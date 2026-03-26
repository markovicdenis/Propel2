<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\Collection;

use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\CollectionIterator;
use Propel\Tests\Helpers\Bookstore\BookstoreTestBase;

use function count;

/**
 * @group database
 */
class CollectionIteratorTest extends BookstoreTestBase
{
    /**
     * @return void
     */
    public function testIsEmpty()
    {
        $iterator = new CollectionIterator(new Collection());
        $this->assertTrue($iterator->isEmpty(), 'isEmpty() returns true on an empty collection');
        $data = ['bar1', 'bar2', 'bar3'];
        $iterator = new CollectionIterator(new Collection($data));
        $this->assertFalse($iterator->isEmpty(), 'isEmpty() returns false on a non empty collection');
    }

    /**
     * @return void
     */
    public function testGetPosition()
    {
        $iterator = new CollectionIterator(new Collection());
        $this->assertEquals(0, $iterator->getPosition(), 'getPosition() returns 0 on an empty collection');
        $data = ['bar1', 'bar2', 'bar3'];
        $iterator = new CollectionIterator(new Collection($data));
        $expectedPositions = [0, 1, 2];
        foreach ($iterator as $k => $element) {
            $this->assertEquals(array_shift($expectedPositions), $iterator->getPosition(), 'getPosition() returns the current position');
            $this->assertEquals($element, $iterator->getCurrent(), 'getPosition() does not change the current position');
        }
    }

    /**
     * @return void
     */
    public function testGetFirst()
    {
        $iterator = new CollectionIterator(new Collection());
        $this->assertNull($iterator->getFirst(), 'getFirst() returns null on an empty collection');
        $data = ['bar1', 'bar2', 'bar3'];
        $iterator = new CollectionIterator(new Collection($data));
        $this->assertEquals('bar1', $iterator->getFirst(), 'getFirst() returns value of the first element in the collection');
    }

    /**
     * @return void
     */
    public function testIsFirst()
    {
        $iterator = new CollectionIterator(new Collection());
        $this->assertTrue($iterator->isFirst(), 'isFirst() returns true on an empty collection');
        $data = ['bar1', 'bar2', 'bar3'];
        $iterator = new CollectionIterator(new Collection($data));
        $expectedRes = [true, false, false];

        foreach ($iterator as $element) {
            $this->assertEquals(array_shift($expectedRes), $iterator->isFirst(), 'isFirst() returns true only for the first element');
            $this->assertEquals($element, $iterator->getCurrent(), 'isFirst() does not change the current position');
        }
    }

    /**
     * @return void
     */
    public function testGetPrevious()
    {
        $iterator = new CollectionIterator(new Collection());
        $this->assertNull($iterator->getPrevious(), 'getPrevious() returns null on an empty collection');
        $data = ['bar1', 'bar2', 'bar3'];
        $iterator = new CollectionIterator(new Collection($data));
        $this->assertNull($iterator->getPrevious(), 'getPrevious() returns null when the internal pointer is at the beginning of the list');
        $iterator->getNext();
        $this->assertEquals('bar1', $iterator->getPrevious(), 'getPrevious() returns the previous element');
        $this->assertEquals('bar1', $iterator->getCurrent(), 'getPrevious() decrements the internal pointer');
    }

    /**
     * @return void
     */
    public function testGetCurrent()
    {
        $iterator = new CollectionIterator(new Collection());
        $this->assertNull($iterator->getCurrent(), 'getCurrent() returns null on an empty collection');
        $data = ['bar1', 'bar2', 'bar3'];
        $iterator = new CollectionIterator(new Collection($data));
        $this->assertEquals('bar1', $iterator->getCurrent(), 'getCurrent() returns the value of the first element when the internal pointer is at the beginning of the list');
        foreach ($iterator as $key => $value) {
            $this->assertEquals($value, $iterator->getCurrent(), 'getCurrent() returns the value of the current element in the collection');
        }
    }

    /**
     * @return void
     */
    public function testGetNext()
    {
        $iterator = new CollectionIterator(new Collection());
        $this->assertNull($iterator->getNext(), 'getNext() returns null on an empty collection');
        $data = ['bar1', 'bar2', 'bar3'];
        $iterator = new CollectionIterator(new Collection($data));
        $this->assertEquals('bar2', $iterator->getNext(), 'getNext() returns the second element when the internal pointer is at the beginning of the list');
        $this->assertEquals('bar2', $iterator->getCurrent(), 'getNext() increments the internal pointer');
        $iterator->getNext();
        $this->assertNull($iterator->getNext(), 'getNext() returns null when the internal pointer is at the end of the list');
    }

    /**
     * @return void
     */
    public function testGetLast()
    {
        $iterator = new CollectionIterator(new Collection());
        $this->assertNull($iterator->getLast(), 'getLast() returns null on an empty collection');
        $data = ['bar1', 'bar2', 'bar3'];
        $iterator = new CollectionIterator(new Collection($data));
        $this->assertEquals('bar3', $iterator->getLast(), 'getLast() returns the last element');
        $this->assertEquals('bar3', $iterator->getCurrent(), 'getLast() moves the internal pointer to the last element');
    }

    /**
     * @return void
     */
    public function testIsLast()
    {
        $iterator = new CollectionIterator(new Collection());
        $this->assertTrue($iterator->isLast(), 'isLast() returns true on an empty collection');
        $data = ['bar1', 'bar2', 'bar3'];
        $iterator = new CollectionIterator(new Collection($data));
        $expectedRes = [false, false, true];
        foreach ($iterator as $element) {
            $this->assertEquals(array_shift($expectedRes), $iterator->isLast(), 'isLast() returns true only for the last element');
            $this->assertEquals($element, $iterator->getCurrent(), 'isLast() does not change the current position');
        }
    }

    /**
     * @return void
     */
    public function testIsOdd()
    {
        $iterator = new CollectionIterator(new Collection());
        $this->assertFalse($iterator->isOdd(), 'isOdd() returns false on an empty collection');
        $data = ['bar1', 'bar2', 'bar3'];
        $iterator = new CollectionIterator(new Collection($data));
        foreach ($iterator as $key => $value) {
            $this->assertEquals((bool)($key % 2), $iterator->isOdd(), 'isOdd() returns true only when the key is odd');
        }
    }

    /**
     * @return void
     */
    public function testIsEven()
    {
        $iterator = new CollectionIterator(new Collection());
        $this->assertTrue($iterator->isEven(), 'isEven() returns true on an empty collection');
        $data = ['bar1', 'bar2', 'bar3'];
        $iterator = new CollectionIterator(new Collection($data));
        foreach ($iterator as $key => $value) {
            $this->assertEquals(!(bool)($key % 2), $iterator->isEven(), 'isEven() returns true only when the key is even');
        }
    }

    /**
     * @return void
     */
    public function testBuiltInSortMethodsReturnTrueAndRefreshPositions()
    {
        $cases = [
            'asort' => [
                'data' => [2, 1, 3],
                'args' => [SORT_NUMERIC],
                'expectedKeys' => [1, 0, 2],
                'expectedValues' => [1, 2, 3],
            ],
            'natsort' => [
                'data' => ['img10', 'img2', 'img1'],
                'args' => [],
                'expectedKeys' => [2, 1, 0],
                'expectedValues' => ['img1', 'img2', 'img10'],
            ],
            'natcasesort' => [
                'data' => ['img10', 'Img2', 'img1'],
                'args' => [],
                'expectedKeys' => [2, 1, 0],
                'expectedValues' => ['img1', 'Img2', 'img10'],
            ],
        ];

        foreach ($cases as $method => $case) {
            $iterator = new CollectionIterator(new Collection($case['data']));

            $this->assertTrue($iterator->{$method}(...$case['args']), $method . '() should return true');
            $this->assertIteratorOrder($iterator, $case['expectedKeys'], $case['expectedValues']);
        }
    }

    /**
     * @return void
     */
    public function testUserSortMethodsReturnTrueAndRefreshPositions()
    {
        $valueIterator = new CollectionIterator(new Collection([2, 1, 3]));
        $this->assertTrue($valueIterator->uasort(static fn ($left, $right): int => $left <=> $right));
        $this->assertIteratorOrder($valueIterator, [1, 0, 2], [1, 2, 3]);

        $keyIterator = new CollectionIterator(new Collection([2, 1, 3]));
        $this->assertTrue($keyIterator->asort(SORT_NUMERIC));
        $this->assertTrue($keyIterator->uksort(static fn ($left, $right): int => $left <=> $right));
        $this->assertIteratorOrder($keyIterator, [0, 1, 2], [2, 1, 3]);
    }

    /**
     * @return void
     */
    public function testKeySortMethodsReturnTrueAndRefreshPositions()
    {
        $iterator = new CollectionIterator(new Collection([2, 1, 3]));
        $this->assertTrue($iterator->asort(SORT_NUMERIC));

        $this->assertTrue($iterator->ksort(SORT_NUMERIC));
        $this->assertIteratorOrder($iterator, [0, 1, 2], [2, 1, 3]);
    }

    /**
     * @param array<string|int> $expectedKeys
     * @param array<mixed> $expectedValues
     *
     * @return void
     */
    private function assertIteratorOrder(CollectionIterator $iterator, array $expectedKeys, array $expectedValues)
    {
        $this->assertSame($expectedValues[0], $iterator->getFirst(), 'getFirst() should return the first value after sorting');
        $this->assertSame(0, $iterator->getPosition(), 'getPosition() should be reset after sorting');
        $this->assertTrue($iterator->isFirst(), 'isFirst() should reflect the refreshed positions');

        $actualKeys = [];
        $actualValues = [];
        $iterator->rewind();

        while ($iterator->valid()) {
            $actualKeys[] = $iterator->key();
            $actualValues[] = $iterator->current();
            $this->assertSame(count($actualKeys) - 1, $iterator->getPosition(), 'getPosition() should follow the sorted order');
            $iterator->next();
        }

        $this->assertSame($expectedKeys, $actualKeys, 'sorted keys should match the expected order');
        $this->assertSame($expectedValues, $actualValues, 'sorted values should match the expected order');
        $this->assertSame($expectedValues[count($expectedValues) - 1], $iterator->getLast(), 'getLast() should use the refreshed positions');
        $this->assertTrue($iterator->isLast(), 'isLast() should reflect the refreshed positions');
    }
}
