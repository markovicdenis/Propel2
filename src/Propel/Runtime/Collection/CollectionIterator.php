<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\Collection;

use ArrayIterator;

use function count;

/**
 * Read-only cursor with sequential navigation helpers over a Collection snapshot.
 *
 * This cursor snapshots the collection's data at construction time.
 * Use Collection::getIterator() for standard foreach iteration.
 * Use CollectionIterator when you need position-aware navigation
 * (getPosition, isFirst, isLast, getPrevious, getNext, etc.).
 *
 * @extends ArrayIterator<int|string, mixed>
 */
class CollectionIterator extends ArrayIterator
{
    protected Collection $collection;

    /** @var array<int|string, int> maps array key → sequential position */
    protected array $positions = [];

    public function __construct(Collection $collection)
    {
        parent::__construct($collection->getData());
        $this->collection = $collection;
        $this->refreshPositions();
    }

    public function getCollection(): Collection
    {
        return $this->collection;
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Returns the 0-based sequential position of the internal pointer.
     */
    public function getPosition(): int
    {
        $key = $this->key();
        if (!$this->valid()) {
            return 0;
        }

        return $this->positions[$key] ?? 0;
    }

    /**
     * Rewind and return the first element, or null on an empty collection.
     */
    public function getFirst(): mixed
    {
        if ($this->isEmpty()) {
            return null;
        }
        $this->rewind();

        return $this->current();
    }

    public function isFirst(): bool
    {
        return $this->getPosition() === 0;
    }

    /**
     * Move backward and return the previous element, or null if already at the start.
     */
    public function getPrevious(): mixed
    {
        if ($this->isFirst()) {
            return null;
        }
        $this->seek($this->getPosition() - 1);

        return $this->current();
    }

    public function getCurrent(): mixed
    {
        return $this->current();
    }

    /**
     * Move forward and return the next element, or null if already at the end.
     */
    public function getNext(): mixed
    {
        $this->next();

        return $this->valid() ? $this->current() : null;
    }

    /**
     * Seek to the last element and return it, or null on an empty collection.
     */
    public function getLast(): mixed
    {
        if ($this->isEmpty()) {
            return null;
        }
        $this->seek(count($this->positions) - 1);

        return $this->current();
    }

    public function isLast(): bool
    {
        if ($this->isEmpty()) {
            return true;
        }

        return $this->getPosition() === count($this->positions) - 1;
    }

    public function isOdd(): bool
    {
        return (bool)($this->getPosition() % 2);
    }

    public function isEven(): bool
    {
        return !$this->isOdd();
    }

    private function refreshPositions(): void
    {
        $this->positions = array_flip(array_keys($this->getArrayCopy()));
    }
}
