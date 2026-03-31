<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\Collection;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\PropelQuery;
use Propel\Runtime\Collection\Exception\ReadOnlyModelException;
use Propel\Runtime\Collection\Exception\UnsupportedRelationException;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\RuntimeException;
use Propel\Runtime\Formatter\AbstractFormatter;
use Propel\Runtime\Map\RelationMap;
use Propel\Runtime\Map\TableMap;
use Propel\Runtime\Propel;
use NoDiscard;

use function array_key_exists;
use function array_pop;
use function array_splice;
use function array_unshift;
use function is_callable;
use function is_object;
use function count;

/**
 * Class for iterating over a list of Propel objects.
 *
 * Two O(1) membership indexes are maintained:
 *  - $instanceIndex  maps spl_object_id() → position  (instance identity)
 *  - $index          maps hashCode()       → position  (record identity)
 *
 * @author Francois Zaninotto
 */
class ObjectCollection extends Collection
{
    /**
     * Record-identity index: hashCode() → array position.
     *
     * @var array<string, int>
     */
    protected array $index = [];

    /**
     * Instance-identity index: spl_object_id() → array position.
     *
     * @var array<int, int>
     */
    protected array $instanceIndex = [];

    /**
     * True when record identities may have changed outside the collection.
     */
    protected bool $recordIndexDirty = false;

    public function __construct(
        array $data = [],
        string $model = '',
        ?AbstractFormatter $formatter = null,
    ) {
        parent::__construct($data, $model, $formatter);
    }

    /**
     * @param array $input
     *
     * @return void
     */
    public function exchangeArray(array $input): void
    {
        $this->data = array_values($input);
        $this->rebuildIndex();
    }

    /**
     * @param array $data
     *
     * @return void
     */
    public function setData(array $data): void
    {
        parent::setData($data);
        $this->rebuildIndex();
    }

    /**
     * Save all the elements in the collection
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con
     *
     * @throws \Propel\Runtime\Collection\Exception\ReadOnlyModelException
     *
     * @return void
     */
    public function save(?ConnectionInterface $con = null): void
    {
        if (!method_exists($this->getFullyQualifiedModel(), 'save')) {
            throw new ReadOnlyModelException('Cannot save objects on a read-only model');
        }
        if ($con === null) {
            $con = $this->getWriteConnection();
        }
        $con->transaction(function () use ($con): void {
            /** @var \Propel\Runtime\ActiveRecord\ActiveRecordInterface $element */
            foreach ($this as $element) {
                $element->save($con);
            }
        });

        $this->rebuildIndex();
    }

    /**
     * Delete all the elements in the collection
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con
     *
     * @throws \Propel\Runtime\Collection\Exception\ReadOnlyModelException
     *
     * @return void
     */
    public function delete(?ConnectionInterface $con = null): void
    {
        if (!method_exists($this->getFullyQualifiedModel(), 'delete')) {
            throw new ReadOnlyModelException('Cannot delete objects on a read-only model');
        }
        if ($con === null) {
            $con = $this->getWriteConnection();
        }
        $con->transaction(function () use ($con): void {
            /** @var \Propel\Runtime\ActiveRecord\ActiveRecordInterface $element */
            foreach ($this as $element) {
                $element->delete($con);
            }
        });
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

        /** @var \Propel\Runtime\ActiveRecord\ActiveRecordInterface $obj */
        foreach ($this as $key => $obj) {
            $key = $usePrefix ? ($this->getModel() . '_' . $key) : $key;
            $ret[$key] = $obj->getPrimaryKey();
        }

        return $ret;
    }

    /**
     * Populates the collection from an array
     * Each object is populated from an array and the result is stored
     * Does not empty the collection before adding the data from the array
     *
     * @param array $arr
     *
     * @return void
     */
    public function fromArray(array $arr): void
    {
        $class = $this->getFullyQualifiedModel();
        foreach ($arr as $element) {
            /** @var \Propel\Runtime\ActiveRecord\ActiveRecordInterface $obj */
            $obj = new $class();
            $obj->fromArray($element);
            $this->append($obj);
        }
    }

    /**
     * Get an array representation of the collection
     * Each object is turned into an array and the result is returned
     *
     * @param string|null $keyColumn If null, the returned array uses an incremental index.
     *                                        Otherwise, the array is indexed using the specified column
     * @param bool $usePrefix If true, the returned array prefixes keys
     * with the model class name ('Article_0', 'Article_1', etc).
     * @param string $keyType (optional) One of the class type constants TableMap::TYPE_PHPNAME,
     *                                        TableMap::TYPE_CAMELNAME, TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME,
     *                                        TableMap::TYPE_NUM. Defaults to TableMap::TYPE_PHPNAME.
     * @param bool $includeLazyLoadColumns (optional) Whether to include lazy loaded columns. Defaults to TRUE.
     * @param array $alreadyDumpedObjects List of objects to skip to avoid recursion
     *
     * <code>
     * $bookCollection->toArray();
     * array(
     *  0 => array('Id' => 123, 'Title' => 'War And Peace'),
     *  1 => array('Id' => 456, 'Title' => 'Don Juan'),
     * )
     * $bookCollection->toArray('Id');
     * array(
     *  123 => array('Id' => 123, 'Title' => 'War And Peace'),
     *  456 => array('Id' => 456, 'Title' => 'Don Juan'),
     * )
     * $bookCollection->toArray(null, true);
     * array(
     *  'Book_0' => array('Id' => 123, 'Title' => 'War And Peace'),
     *  'Book_1' => array('Id' => 456, 'Title' => 'Don Juan'),
     * )
     * </code>
     *
     * @return array<int|string, array>
     */
    public function toArray(
        ?string $keyColumn = null,
        bool $usePrefix = false,
        string $keyType = TableMap::TYPE_PHPNAME,
        bool $includeLazyLoadColumns = true,
        array $alreadyDumpedObjects = []
    ): array {
        $ret = [];
        $keyGetterMethod = 'get' . $keyColumn;

        /** @var \Propel\Runtime\ActiveRecord\ActiveRecordInterface $obj */
        foreach ($this->data as $key => $obj) {
            $key = $keyColumn === null ? $key : $obj->$keyGetterMethod();
            $key = $usePrefix ? ($this->getModel() . '_' . $key) : $key;
            $ret[$key] = $obj->toArray($keyType, $includeLazyLoadColumns, $alreadyDumpedObjects, true);
        }

        return $ret;
    }

    /**
     * Get an array representation of the collection
     *
     * @param string|null $keyColumn If null, the returned array uses an incremental index.
     *                           Otherwise, the array is indexed using the specified column
     * @param bool $usePrefix If true, the returned array prefixes keys
     * with the model class name ('Article_0', 'Article_1', etc).
     * <code>
     * $bookCollection->getArrayCopy();
     * array(
     * 0 => $book0,
     * 1 => $book1,
     * )
     * $bookCollection->getArrayCopy('Id');
     * array(
     * 123 => $book0,
     * 456 => $book1,
     * )
     * $bookCollection->getArrayCopy(null, true);
     * array(
     * 'Book_0' => $book0,
     * 'Book_1' => $book1,
     * )
     * </code>
     *
     * @return array<int|string, mixed>
     */
    public function getArrayCopy(?string $keyColumn = null, bool $usePrefix = false): array
    {
        if ($keyColumn === null && $usePrefix === false) {
            return parent::getArrayCopy();
        }
        $ret = [];
        $keyGetterMethod = 'get' . $keyColumn;
        foreach ($this as $key => $obj) {
            $key = $keyColumn === null ? $key : $obj->$keyGetterMethod();
            $key = $usePrefix ? ($this->getModel() . '_' . $key) : $key;
            $ret[$key] = $obj;
        }

        return $ret;
    }

    /**
     * Get an associative array representation of the collection
     * The first parameter specifies the column to be used for the key,
     * And the second for the value.
     *
     * <code>
     *   $res = $coll->toKeyValue('Id', 'Name');
     * </code>
     *
     * @param string $keyColumn
     * @param string|null $valueColumn
     *
     * @return array<int|string, mixed>
     */
    public function toKeyValue(string $keyColumn = 'PrimaryKey', ?string $valueColumn = null): array
    {
        $ret = [];
        $keyGetterMethod = 'get' . $keyColumn;
        $valueGetterMethod = ($valueColumn === null) ? '__toString' : ('get' . $valueColumn);
        foreach ($this as $obj) {
            $ret[$obj->$keyGetterMethod()] = $obj->$valueGetterMethod();
        }

        return $ret;
    }

    /**
     * Get an associative array representation of the collection.
     * The first parameter specifies the column to be used for the key.
     *
     * <code>
     *   $res = $userCollection->toKeyIndex('Name');
     *
     *   $res = [
     *       'peter' => class User #1 {$name => 'peter', ...},
     *       'hans' => class User #2 {$name => 'hans', ...},
     *       ...
     *   ]
     * </code>
     *
     * @param string $keyColumn
     *
     * @return array<int|string, mixed>
     */
    public function toKeyIndex(string $keyColumn = 'PrimaryKey'): array
    {
        $ret = [];
        $keyGetterMethod = 'get' . ucfirst($keyColumn);
        foreach ($this as $obj) {
            $ret[$obj->$keyGetterMethod()] = $obj;
        }

        return $ret;
    }

    /**
     * Get an array representation of the column.
     *
     * <code>
     *   $res = $userCollection->toKeyIndex('Name');
     *
     *   $res = [
     *       'peter',
     *       'hans',
     *       ...
     *   ]
     * </code>
     *
     * @param string $columnName
     *
     * @return list<mixed>
     */
    public function getColumnValues(string $columnName = 'PrimaryKey'): array
    {
        $ret = [];
        $keyGetterMethod = 'get' . ucfirst($columnName);
        foreach ($this as $obj) {
            $ret[] = $obj->$keyGetterMethod();
        }

        return $ret;
    }

    /**
     * Makes an additional query to populate the objects related to the collection objects
     * by a certain relation
     *
     * @param string $relation Relation name (e.g. 'Book')
     * @param \Propel\Runtime\ActiveQuery\Criteria|null $criteria Optional Criteria object to filter the related object collection
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con Optional connection object
     *
     * @throws \Propel\Runtime\Exception\RuntimeException
     * @throws \Propel\Runtime\Collection\Exception\UnsupportedRelationException
     *
     * @return static The list of related objects.
     */
    public function populateRelation(
        string $relation,
        ?Criteria $criteria = null,
        ?ConnectionInterface $con = null
    ) {
        if (!Propel::isInstancePoolingEnabled()) {
            throw new RuntimeException(__METHOD__ . ' needs instance pooling to be enabled prior to populating the collection');
        }
        $relationMap = $this->getFormatter()->getTableMap()->getRelation($relation);
        if ($this->isEmpty()) {
            // save a useless query and return an empty collection
            $relationClassName = $relationMap->getRightTable()->getClassName();
            $collectionClassName = $relationMap->getRightTable()->getCollectionClassName();

            /** @var static $coll */
            $coll = new $collectionClassName([], $relationClassName, $this->getFormatter());

            return $coll;
        }

        $symRelationMap = $relationMap->getSymmetricalRelation();

        $query = PropelQuery::from($relationMap->getRightTable()->getClassName());
        if ($criteria !== null) {
            $query->mergeWith($criteria);
        }
        // query the db for the related objects
        $filterMethod = 'filterBy' . $symRelationMap->getName();
        /** @var static $relatedObjects */
        $relatedObjects = $query
            ->$filterMethod($this)
            ->find($con);

        if ($relationMap->getType() === RelationMap::ONE_TO_MANY) {
            // initialize the embedded collections of the main objects
            $relationName = $relationMap->getName();
            $resetPartialStatusMethod = 'resetPartial' . $relationMap->getPluralName();
            foreach ($this as $mainObj) {
                $mainObj->initRelation($relationName);
                $mainObj->$resetPartialStatusMethod(false);
            }
            // associate the related objects to the main objects
            $getMethod = 'get' . $symRelationMap->getName();
            $addMethod = 'add' . $relationName;
            foreach ($relatedObjects as $object) {
                $mainObj = $object->$getMethod(); // instance pool is used here to avoid a query
                $mainObj->$addMethod($object);
            }
        } elseif ($relationMap->getType() === RelationMap::MANY_TO_ONE) {
            // nothing to do; the instance pool will catch all calls to getRelatedObject()
            // and return the object in memory
        } else {
            throw new UnsupportedRelationException(__METHOD__ . ' does not support this relation type');
        }

        return $relatedObjects;
    }

    // -------------------------------------------------------------------------
    // Explicit identity API
    // -------------------------------------------------------------------------

    /**
     * True if the exact same object instance is present.
     * O(1) via spl_object_id().
     */
    public function containsInstance(object $object): bool
    {
        return isset($this->instanceIndex[spl_object_id($object)]);
    }

    /**
     * True if any item has the same record identity (hashCode()) as $object.
     * O(1) via record-identity index.
     */
    public function containsSameRecord(object $object): bool
    {
        return $this->indexOfSameRecord($object) !== null;
    }

    /**
     * True if any item is found through the maintained record-identity index only.
     *
     * This method never scans the collection or repairs stale indexes.
     */
    public function containsSameRecordIndexed(object $object): bool
    {
        return $this->indexOfSameRecordIndexed($object) !== null;
    }

    /**
     * Returns the position of the exact instance, or null if not found.
     */
    public function indexOfInstance(object $object): ?int
    {
        return $this->instanceIndex[spl_object_id($object)] ?? null;
    }

    /**
     * Returns the position of the first record with the same identity, or null if not found.
     */
    public function indexOfSameRecord(object $object): ?int
    {
        $hash = $this->getHashCode($object);
        $hasIndexedHash = array_key_exists($hash, $this->index);

        if ($this->recordIndexDirty) {
            return $this->repairSameRecordPosition($hash);
        }

        $position = $this->findIndexedSameRecordPosition($hash);
        if ($position !== null) {
            return $position;
        }

        if ($hasIndexedHash) {
            return $this->repairSameRecordPosition($hash);
        }

        return $this->repairSameRecordPosition($hash, false);
    }

    /**
     * Returns the position via the maintained record-identity index only.
     *
     * This method never scans the collection or repairs stale indexes.
     */
    public function indexOfSameRecordIndexed(object $object): ?int
    {
        return $this->findIndexedSameRecordPosition($this->getHashCode($object));
    }

    /**
     * Marks the record-identity index as dirty.
     *
     * Call this after mutating object state that contributes to hashCode().
     */
    public function markRecordIndexDirty(): void
    {
        $this->recordIndexDirty = true;
    }

    /**
     * Rebuilds the record-identity index after external record-identity mutations.
     */
    public function repairRecordIndex(): void
    {
        $this->rebuildIndex();
    }

    /**
     * @inheritDoc
     *
     * For objects: instance identity first, then record identity.
     * For non-objects: delegates to base linear search.
     */
    public function contains($element): bool
    {
        if (!is_object($element)) {
            return parent::contains($element);
        }

        return $this->containsInstance($element) || $this->containsSameRecord($element);
    }

    /**
     * @inheritDoc
     *
     * For objects: returns position via instance identity, falling back to record identity.
     *
     * @return int|false
     */
    public function search($element)
    {
        if (!is_object($element)) {
            return parent::search($element);
        }

        return $this->indexOfInstance($element) ?? $this->indexOfSameRecord($element) ?? false;
    }

    /**
     * Remove an object by instance or record identity.
     */
    public function removeObject(object $element): void
    {
        $pos = $this->findObjectPosition($element);
        if ($pos !== null) {
            $this->removeAt($pos);
        }
    }

    /**
     * Remove an object without preserving the remaining order.
     *
     * @return bool True when an object was removed.
     */
    #[NoDiscard]
    public function removeObjectFast(object $element): bool
    {
        $pos = $this->findObjectPosition($element);
        if ($pos === null) {
            return false;
        }

        $this->swapRemoveAt($pos);

        return true;
    }

    /**
     * Remove many objects while preserving relative order and rebuilding indexes once.
     *
     * @param iterable<mixed> $objects
     *
     * @return int Number of removed elements.
     */
    #[NoDiscard]
    public function removeObjects(iterable $objects): int
    {
        $positions = [];
        foreach ($objects as $object) {
            if (!is_object($object)) {
                continue;
            }

            $position = $this->findObjectPosition($object);
            if ($position !== null) {
                $positions[$position] = true;
            }
        }

        if ($positions === []) {
            return 0;
        }

        $data = [];
        foreach ($this->data as $position => $value) {
            if (!isset($positions[$position])) {
                $data[] = $value;
            }
        }

        $this->data = $data;
        $this->rebuildIndex();

        return count($positions);
    }

    // -------------------------------------------------------------------------
    // Mutators — maintain both indexes
    // -------------------------------------------------------------------------

    /**
     * @param mixed $value
     */
    public function append($value): void
    {
        if (!is_object($value)) {
            parent::append($value);

            return;
        }

        $this->data[] = $value;
        $pos = count($this->data) - 1;
        $this->indexObjectAt($pos, $value);
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        if (!is_object($value)) {
            parent::offsetSet($offset, $value);

            return;
        }

        if ($offset === null) {
            $this->append($value);

            return;
        }

        $this->assertIntegerOffset($offset);

        if (isset($this->data[$offset]) && is_object($this->data[$offset])) {
            $this->removeObjectIndexes($this->data[$offset]);
        }

        $this->data[$offset] = $value;
        $this->indexObjectAt($offset, $value);
    }

    /**
     * @param mixed $offset
     */
    public function offsetUnset($offset): void
    {
        $this->assertIntegerOffset($offset);
        $this->removeAt($offset);
    }

    public function pop(): mixed
    {
        if ($this->data === []) {
            return null;
        }

        $value = array_pop($this->data);
        if (is_object($value)) {
            $this->removeObjectIndexes($value);
        }

        return $value;
    }

    public function shift(): mixed
    {
        if ($this->data === []) {
            return null;
        }

        return $this->removeAt(0);
    }

    public function prepend($value): int
    {
        $count = array_unshift($this->data, $value);
        $this->reindexTailFrom(0);

        return $count;
    }

    // -------------------------------------------------------------------------
    // Index maintenance
    // -------------------------------------------------------------------------

    protected function findObjectPosition(object $object): ?int
    {
        return $this->indexOfInstance($object) ?? $this->indexOfSameRecord($object);
    }

    /**
     * Remove one element while preserving the relative order of the remaining items.
     */
    protected function removeAt(int $offset): mixed
    {
        if (!array_key_exists($offset, $this->data)) {
            return null;
        }

        $value = $this->data[$offset];
        if (is_object($value)) {
            $this->removeObjectIndexes($value);
        }

        $lastPosition = count($this->data) - 1;
        if ($offset === $lastPosition) {
            array_pop($this->data);

            return $value;
        }

        array_splice($this->data, $offset, 1);
        $this->reindexTailFrom($offset);

        return $value;
    }

    /**
     * Remove one element in O(1) by moving the last element into its slot.
     */
    protected function swapRemoveAt(int $offset): mixed
    {
        if (!array_key_exists($offset, $this->data)) {
            return null;
        }

        $lastPosition = count($this->data) - 1;
        $value = $this->data[$offset];
        if (is_object($value)) {
            $this->removeObjectIndexes($value);
        }

        if ($offset === $lastPosition) {
            array_pop($this->data);

            return $value;
        }

        $lastValue = array_pop($this->data);
        if (is_object($lastValue)) {
            $this->removeObjectIndexes($lastValue);
        }

        $this->data[$offset] = $lastValue;
        if (is_object($lastValue)) {
            $this->indexObjectAt($offset, $lastValue);
        }

        return $value;
    }

    protected function reindexTailFrom(int $offset): void
    {
        $count = count($this->data);
        for ($position = $offset; $position < $count; $position++) {
            $value = $this->data[$position];
            if (!is_object($value)) {
                continue;
            }

            $this->indexObjectAt($position, $value);
        }
    }

    protected function indexObjectAt(int $position, object $value): void
    {
        $this->index[$this->getHashCode($value)] = $position;
        $this->instanceIndex[spl_object_id($value)] = $position;
    }

    protected function removeObjectIndexes(object $value): void
    {
        unset($this->instanceIndex[spl_object_id($value)]);
        unset($this->index[$this->getHashCode($value)]);
    }

    protected function findIndexedSameRecordPosition(string $hash): ?int
    {
        $position = $this->index[$hash] ?? null;
        if ($position === null) {
            return null;
        }

        if (!isset($this->data[$position]) || !is_object($this->data[$position])) {
            return null;
        }

        return $this->getHashCode($this->data[$position]) === $hash ? $position : null;
    }

    protected function repairSameRecordPosition(string $hash, bool $rebuildOnMiss = true): ?int
    {
        foreach ($this->data as $position => $value) {
            if (!is_object($value)) {
                continue;
            }

            if ($this->getHashCode($value) === $hash) {
                $this->rebuildIndex();

                return $position;
            }
        }

        if ($rebuildOnMiss) {
            $this->rebuildIndex();
        }

        return null;
    }

    protected function rebuildIndex(): void
    {
        $this->index = [];
        $this->instanceIndex = [];
        foreach ($this->data as $pos => $value) {
            if (!is_object($value)) {
                continue;
            }

            $this->indexObjectAt($pos, $value);
        }

        $this->recordIndexDirty = false;
    }

    /**
     * Returns $object->hashCode() when available, otherwise spl_object_hash().
     *
     * @param mixed $object
     */
    protected function getHashCode($object): string
    {
        if (is_object($object) && is_callable([$object, 'hashCode'])) {
            return $object->hashCode();
        }

        return spl_object_hash($object);
    }
}
