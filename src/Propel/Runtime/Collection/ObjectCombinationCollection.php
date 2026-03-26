<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\Collection;

use Propel\Runtime\ActiveRecord\ActiveRecordInterface;
use Propel\Runtime\Exception\UnexpectedValueException;

use function func_get_args;
use function call_user_func;
use function is_callable;
use function sprintf;
use function count;

/**
 * Class for iterating over a list of Propel objects
 *
 * @author Francois Zaninotto
 */
class ObjectCombinationCollection extends ObjectCollection
{
    /**
     * True if the exact same object tuple is present.
     */
    public function containsInstance(object $object): bool
    {
        $objects = func_get_args();
        if (count($objects) === 1) {
            return parent::containsInstance($object);
        }

        foreach ($this as $combination) {
            if (count($combination) !== count($objects)) {
                continue;
            }

            $matches = true;
            foreach ($objects as $index => $currentObject) {
                if (!isset($combination[$index]) || $combination[$index] !== $currentObject) {
                    $matches = false;

                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get an array of the primary keys of all the objects in the collection
     *
     * @param bool $usePrefix
     *
     * @return array The list of the primary keys of the collection
     */
    public function getPrimaryKeys(bool $usePrefix = true): array
    {
        $ret = [];

        foreach ($this as $combination) {
            $pkCombo = [];
            /** @var object $obj */
            foreach ($combination as $key => $obj) {
                $pkCombo[$key] = $this->getPrimaryKeyForObject($obj);
            }
            $ret[] = $pkCombo;
        }

        return $ret;
    }

    /**
     * @inheritDoc
     */
    public function push($value): void
    {
        parent::push(func_get_args());
    }

    /**
     * Returns all values from one position/column.
     *
     * @param int $position beginning with 1
     *
     * @return array
     */
    public function getObjectsFromPosition(int $position = 1): array
    {
        $result = [];
        foreach ($this as $array) {
            $result[] = $array[$position - 1];
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function search($element)
    {
        $hashes = [];
        $isActiveRecord = [];
        foreach (func_get_args() as $pos => $obj) {
            if ($obj instanceof ActiveRecordInterface) {
                $hashes[$pos] = $this->getHashCode($obj);
                $isActiveRecord[$pos] = true;
            } else {
                $hashes[$pos] = $obj;
                $isActiveRecord[$pos] = false;
            }
        }
        foreach ($this as $pos => $combination) {
            $found = true;
            foreach ($combination as $idx => $obj) {
                if ($obj === null) {
                    if ($obj !== $hashes[$idx]) {
                        $found = false;

                        break;
                    }
                } elseif ($isActiveRecord[$idx] ? $this->getHashCode($obj) !== $hashes[$idx] : $obj !== $hashes[$idx]) {
                    $found = false;

                    break;
                }
            }
            if ($found) {
                return $pos;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function removeObject(mixed $element): void
    {
        $pos = $this->search(...func_get_args());
        if ($pos !== false) {
            $this->remove($pos);
        }
    }

    /**
     * @inheritDoc
     */
    public function contains($element): bool
    {
        return $this->search(...func_get_args()) !== false;
    }

    /**
     * @return mixed
     */
    protected function getPrimaryKeyForObject(object $object)
    {
        if (!is_callable([$object, 'getPrimaryKey'])) {
            throw new UnexpectedValueException(sprintf('Object of class %s does not provide getPrimaryKey().', $object::class));
        }

        return call_user_func([$object, 'getPrimaryKey']);
    }
}
