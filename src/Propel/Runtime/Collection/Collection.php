<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\Collection;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Propel\Common\Pluralizer\PluralizerInterface;
use Propel\Common\Pluralizer\StandardEnglishPluralizer;
use Propel\Runtime\Collection\Exception\ModelNotFoundException;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\BadMethodCallException;
use Propel\Runtime\Exception\LogicException;
use Propel\Runtime\Exception\UnexpectedValueException;
use Propel\Runtime\Formatter\AbstractFormatter;
use Propel\Runtime\Map\TableMap;
use Propel\Runtime\Parser\AbstractParser;
use Propel\Runtime\Propel;
use ArrayIterator;
use Traversable;

use function array_is_list;
use function array_pop;
use function array_shift;
use function array_unshift;
use function count;
use function in_array;
use function is_object;
use function sprintf;

/**
 * Class for iterating over a list of Propel elements
 * The collection keys must be integers - no associative array accepted
 *
 * @author Francois Zaninotto
 *
 * @implements ArrayAccess<int, mixed>
 * @implements IteratorAggregate<int, mixed>
 */
class Collection implements ArrayAccess, IteratorAggregate, Countable
{
    /**
     * @var string
     */
    protected string $model = '';

    /**
     * The fully qualified classname of the model
     *
     * @var string
     */
    protected string $fullyQualifiedModel = '';

    /**
     * @var \Propel\Runtime\Formatter\AbstractFormatter
     */
    protected ?AbstractFormatter $formatter = null;

    /**
     * @var array
     */
    protected array $data = [];

    /**
     * @var \Propel\Common\Pluralizer\PluralizerInterface|null
     */
    private ?PluralizerInterface $pluralizer = null;

    /**
     * @param array $data
     */
    public function __construct(
        array $data = [],
        string $model = '',
        ?AbstractFormatter $formatter = null,
    ) {
        $this->exchangeArray($data);
        if ($model !== '') {
            $this->setModel($model);
        }
        $this->formatter = $formatter;
    }

    /**
     * @param string $data
     *
     * @return static
     */
    public static function fromXmlString(string $data, string $model = '', ?AbstractFormatter $formatter = null): self
    {
        $collection = new static([], $model, $formatter);
        $collection->importFrom('XML', $data);

        return $collection;
    }

    /**
     * @param string $data
     *
     * @return static
     */
    public static function fromYamlString(string $data, string $model = '', ?AbstractFormatter $formatter = null): self
    {
        $collection = new static([], $model, $formatter);
        $collection->importFrom('YAML', $data);

        return $collection;
    }

    /**
     * @param string $data
     *
     * @return static
     */
    public static function fromJsonString(string $data, string $model = '', ?AbstractFormatter $formatter = null): self
    {
        $collection = new static([], $model, $formatter);
        $collection->importFrom('JSON', $data);

        return $collection;
    }

    /**
     * @param string $data
     *
     * @return static
     */
    public static function fromCsvString(string $data, string $model = '', ?AbstractFormatter $formatter = null): self
    {
        $collection = new static([], $model, $formatter);
        $collection->importFrom('CSV', $data);

        return $collection;
    }

    /**
     * @return array
     */
    public function __serialize(): array
    {
        return [
            'data' => $this->getArrayCopy(),
            'model' => $this->model,
            'fullyQualifiedModel' => $this->fullyQualifiedModel,
        ];
    }

    /**
     * @param array $data
     *
     * @return void
     */
    public function __unserialize(array $data): void
    {
        $this->exchangeArray($data['data'] ?? []);
        $this->model = $data['model'] ?? '';
        $this->fullyQualifiedModel = $data['fullyQualifiedModel'] ?? '';
    }

    /**
     * @param mixed $value
     *
     * @return void
     */
    public function append($value): void
    {
        $this->data[] = $value;
    }

    /**
     * @param mixed $offset
     *
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    /**
     * @psalm-suppress ReservedWord
     *
     * @param mixed $offset
     *
     * @return mixed
     */
    public function offsetGet($offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     *
     * @return void
     */
    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->data[] = $value;

            return;
        }

        $this->assertIntegerOffset($offset);
        $this->data[$offset] = $value;
    }

    /**
     * @param mixed $offset
     *
     * @return void
     */
    public function offsetUnset($offset): void
    {
        $this->assertIntegerOffset($offset);
        unset($this->data[$offset]);
    }

    /**
     * @param array $input
     *
     * @return void
     */
    public function exchangeArray(array $input): void
    {
        $this->data = array_values($input);
    }

    /**
     * Get the data in the collection
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @return array
     */
    public function getArrayCopy(): array
    {
        return $this->data;
    }

    /**
     * Set the data in the collection
     *
     * @param array $data
     *
     * @return void
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    /**
     * @return \Propel\Runtime\Collection\CollectionIterator|\Propel\Runtime\Collection\IteratorInterface
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->data);
    }

    /**
     * Count elements in the collection
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->data);
    }

    /**
     * Get the first element in the collection
     *
     * @return mixed
     */
    public function getFirst()
    {
        return $this->data[0] ?? null;
    }

    /**
     * Get the last element in the collection
     *
     * @return mixed
     */
    public function getLast()
    {
        if ($this->data === []) {
            return null;
        }

        return $this->data[array_key_last($this->data)];
    }

    /**
     * Check if the collection is empty
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * Get an element from its key
     * Alias for ArrayObject::offsetGet()
     *
     * @param mixed $key
     *
     * @throws \Propel\Runtime\Exception\UnexpectedValueException
     *
     * @return mixed The element
     */
    public function get($key)
    {
        if (!$this->offsetExists($key)) {
            throw new UnexpectedValueException(sprintf('Unknown key %s.', $key));
        }

        return $this->offsetGet($key);
    }

    /**
     * Pops an element off the end of the collection
     *
     * @return mixed The popped element
     */
    public function pop()
    {
        return array_pop($this->data);
    }

    /**
     * Pops an element off the beginning of the collection
     *
     * @return mixed The popped element
     */
    public function shift()
    {
        return array_shift($this->data);
    }

    /**
     * Prepend one elements to the end of the collection
     *
     * @param mixed $value the element to prepend
     *
     * @return void
     */
    public function push($value): void
    {
        $this[] = $value;
    }

    /**
     * Prepend one or more elements to the beginning of the collection
     *
     * @param mixed $value the element to prepend
     *
     * @return int The number of new elements in the array
     */
    public function prepend($value): int
    {
        return array_unshift($this->data, $value);
    }

    /**
     * Add an element to the collection with the given key
     * Alias for ArrayObject::offsetSet()
     *
     * @param mixed $key
     * @param mixed $value
     *
     * @return void
     */
    public function set($key, $value): void
    {
        $this->offsetSet($key, $value);
    }

    /**
     * Removes a specified collection element
     * Alias for ArrayObject::offsetUnset()
     *
     * @param mixed $key
     *
     * @throws \Propel\Runtime\Exception\UnexpectedValueException
     *
     * @return void
     */
    public function remove($key): void
    {
        if (!$this->offsetExists($key)) {
            throw new UnexpectedValueException(sprintf('Unknown key %s.', $key));
        }

        $this->offsetUnset($key);
    }

    /**
     * Clears the collection
     *
     * @return void
     */
    public function clear(): void
    {
        $this->exchangeArray([]);
    }

    /**
     * Whether this collection contains a specified element
     *
     * @param mixed $element
     *
     * @return bool
     */
    public function contains($element): bool
    {
        return in_array($element, $this->getArrayCopy(), true);
    }

    /**
     * Search an element in the collection
     *
     * @param mixed $element
     *
     * @return mixed Returns the key for the element if it is found in the collection, FALSE otherwise
     */
    public function search($element)
    {
        return array_search($element, $this->getArrayCopy(), true);
    }

    /**
     * Returns an array of objects present in the collection that
     * are not presents in the given collection.
     *
     * @param \Propel\Runtime\Collection\Collection $collection A Propel collection.
     *
     * @return self An array of Propel objects from the collection that are not presents in the given collection.
     */
    public function diff(Collection $collection): self
    {
        $diff = clone $this;
        $diff->clear();

        foreach ($this as $object) {
            if (!$collection->contains($object)) {
                $diff[] = $object;
            }
        }

        return $diff;
    }

    // Propel collection methods

    /**
     * Set the model of the elements in the collection
     *
     * @param string $model Name of the Propel object classes stored in the collection
     *
     * @return void
     */
    public function setModel(string $model): void
    {
        $pos = strrpos($model, '\\');
        if ($pos !== false) {
            $this->model = substr($model, $pos + 1);
        } else {
            $this->model = $model;
        }
        $this->fullyQualifiedModel = ((strpos($model, '\\') === 0) ? '' : '\\') . $model;
    }

    /**
     * Get the model of the elements in the collection
     *
     * @return string Name of the Propel object class stored in the collection
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the model of the elements in the collection
     *
     * @return string Fully qualified Name of the Propel object class stored in the collection
     */
    public function getFullyQualifiedModel(): string
    {
        return $this->fullyQualifiedModel;
    }

    /**
     * @psalm-return class-string<\Propel\Runtime\Map\TableMap>
     *
     * @throws \Propel\Runtime\Collection\Exception\ModelNotFoundException
     *
     * @return string
     */
    public function getTableMapClass(): string
    {
        $model = $this->getModel();

        if (!$model) {
            throw new ModelNotFoundException('You must set the collection model before interacting with it');
        }

        return $this->getFullyQualifiedModel()::TABLE_MAP;
    }

    /**
     * @param \Propel\Runtime\Formatter\AbstractFormatter $formatter
     *
     * @return void
     */
    public function setFormatter(AbstractFormatter $formatter): void
    {
        $this->formatter = $formatter;
    }

    /**
     * @return \Propel\Runtime\Formatter\AbstractFormatter
     */
    public function getFormatter(): AbstractFormatter
    {
        if ($this->formatter === null) {
            throw new LogicException('The collection formatter has not been initialized.');
        }

        return $this->formatter;
    }

    /**
     * Get a write connection object for the database containing the elements of the collection
     *
     * @return \Propel\Runtime\Connection\ConnectionInterface A ConnectionInterface connection object
     */
    public function getWriteConnection(): ConnectionInterface
    {
        $databaseName = $this->getTableMapClass()::DATABASE_NAME;

        return Propel::getServiceContainer()->getWriteConnection($databaseName);
    }

    /**
     * Populate the current collection from a string, using a given parser format
     * <code>
     * $coll = new ObjectCollection();
     * $coll->setModel('Book');
     * $coll->importFrom('JSON', '{{"Id":9012,"Title":"Don Juan","ISBN":"0140422161","Price":12.99,"PublisherId":1234,"AuthorId":5678}}');
     * </code>
     *
     * @param mixed $parser A AbstractParser instance, or a format name ('XML', 'YAML', 'JSON', 'CSV')
     * @param string $data The source data to import from
     *
     * @return void
     */
    public function importFrom($parser, string $data): void
    {
        if (!$parser instanceof AbstractParser) {
            $parser = AbstractParser::getParser($parser);
        }

        $this->fromArray($parser->listToArray($data, $this->getPluralModelName()));
    }

    /**
     * Export the current collection to a string, using a given parser format
     * <code>
     * $books = BookQuery::create()->find();
     * echo $book->exportTo('JSON');
     *  => {{"Id":9012,"Title":"Don Juan","ISBN":"0140422161","Price":12.99,"PublisherId":1234,"AuthorId":5678}}');
     * </code>
     *
     * A OnDemandCollection cannot be exported. Any attempt will result in a PropelException being thrown.
     *
     * @param \Propel\Runtime\Parser\AbstractParser|string $parser A AbstractParser instance, or a format name ('XML', 'YAML', 'JSON', 'CSV')
     * @param bool $usePrefix (optional) If true, the returned element keys will be prefixed with the
     * model class name ('Article_0', 'Article_1', etc). Defaults to TRUE.
     * Not supported by ArrayCollection, as ArrayFormatter has
     * already created the array used here with integers as keys.
     * @param bool $includeLazyLoadColumns (optional) Whether to include lazy load(ed) columns. Defaults to TRUE.
     * Not supported by ArrayCollection, as ArrayFormatter has
     * already included lazy-load columns in the array used here.
     * @param string $keyType (optional) One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME, TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM. Defaults to TableMap::TYPE_PHPNAME.
     *
     * @return string The exported data
     */
    public function exportTo($parser, bool $usePrefix = true, bool $includeLazyLoadColumns = true, string $keyType = TableMap::TYPE_PHPNAME): string
    {
        if (!$parser instanceof AbstractParser) {
            $parser = AbstractParser::getParser($parser);
        }

        $array = $this->toArray(null, $usePrefix, $keyType, $includeLazyLoadColumns);

        return $parser->listFromArray($array, $this->getPluralModelName());
    }

    public function toXmlString(bool $usePrefix = false, bool $includeLazyLoadColumns = true, string $keyType = TableMap::TYPE_PHPNAME): string
    {
        return $this->exportTo('XML', $usePrefix, $includeLazyLoadColumns, $keyType);
    }

    public function toYamlString(bool $usePrefix = false, bool $includeLazyLoadColumns = true, string $keyType = TableMap::TYPE_PHPNAME): string
    {
        return $this->exportTo('YAML', $usePrefix, $includeLazyLoadColumns, $keyType);
    }

    public function toJsonString(bool $usePrefix = false, bool $includeLazyLoadColumns = true, string $keyType = TableMap::TYPE_PHPNAME): string
    {
        return $this->exportTo('JSON', $usePrefix, $includeLazyLoadColumns, $keyType);
    }

    public function toCsvString(bool $usePrefix = false, bool $includeLazyLoadColumns = true, string $keyType = TableMap::TYPE_PHPNAME): string
    {
        return $this->exportTo('CSV', $usePrefix, $includeLazyLoadColumns, $keyType);
    }

    /**
     * Returns a string representation of the current collection.
     * Based on the string representation of the underlying objects, defined in
     * the TableMap::DEFAULT_STRING_FORMAT constant
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->exportTo($this->getTableMapClass()::DEFAULT_STRING_FORMAT, false);
    }

    /**
     * Creates clones of the containing data.
     *
     * @return void
     */
    public function __clone()
    {
        foreach ($this as $key => $obj) {
            if (is_object($obj)) {
                $this[$key] = clone $obj;
            }
        }
    }

    /**
     * @return \Propel\Common\Pluralizer\PluralizerInterface
     */
    protected function getPluralizer(): PluralizerInterface
    {
        if ($this->pluralizer === null) {
            $this->pluralizer = $this->createPluralizer();
        }

        return $this->pluralizer;
    }

    /**
     * Overwrite this method if you want to use a custom pluralizer
     *
     * @return \Propel\Common\Pluralizer\PluralizerInterface
     */
    protected function createPluralizer(): PluralizerInterface
    {
        return new StandardEnglishPluralizer();
    }

    /**
     * @return string
     */
    protected function getPluralModelName(): string
    {
        return $this->getPluralizer()->getPluralForm($this->getModel());
    }

    /**
     * @return string
     */
    public function hashCode(): string
    {
        return spl_object_hash($this);
    }

    /**
     * @param mixed $offset
     *
     * @return void
     */
    protected function assertIntegerOffset($offset): void
    {
        if (!is_int($offset)) {
            throw new BadMethodCallException(sprintf('Collection offsets must be integers, %s given.', get_debug_type($offset)));
        }
    }
}
