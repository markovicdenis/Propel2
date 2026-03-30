# ObjectCollection

`ObjectCollection` is Propel's runtime collection for model objects. In addition to the usual collection behavior, it maintains two identity indexes:

- Instance identity via `spl_object_id()`
- Record identity via `hashCode()`

That gives object-aware membership checks and lookups without falling back to a linear scan in the common case.

## Identity Semantics

`ObjectCollection` distinguishes between two different notions of equality:

- Instance identity: the exact same PHP object instance
- Record identity: another object representing the same persisted record

The main APIs are:

- `containsInstance(object $object): bool`
- `containsSameRecord(object $object): bool`
- `containsSameRecordIndexed(object $object): bool`
- `indexOfInstance(object $object): ?int`
- `indexOfSameRecord(object $object): ?int`
- `indexOfSameRecordIndexed(object $object): ?int`
- `contains($element): bool`
- `search($element): int|false`

For object arguments, `contains()` and `search()` check instance identity first and then record identity.

## Removal APIs

`ObjectCollection` now exposes removal APIs with explicit ordering semantics.

### Stable Removal

Use these methods when the relative order of the remaining elements matters:

- `removeObject(object $element): void`
- `removeObjects(iterable $objects): int`

`removeObject()` removes a single element by instance identity or record identity.

`removeObjects()` removes multiple elements and rebuilds indexes once, which is much cheaper than repeated single removals when deleting many objects.

Example:

```php
$books = new ObjectCollection([$book1, $book2, $book3, $book4]);

$removed = $books->removeObjects([$book2, $book4]);

// $removed === 2
// Remaining order: [$book1, $book3]
```

### Fast Unordered Removal

Use `removeObjectFast()` when you do not need stable ordering:

- `removeObjectFast(object $element): bool`

This method removes in $O(1)$ by swapping the last element into the removed slot.

Example:

```php
$books = new ObjectCollection([$book1, $book2, $book3]);

$removed = $books->removeObjectFast($book1);

// $removed === true
// Remaining order is not preserved: [$book3, $book2]
```

Because the return value matters, `removeObjectFast()` and `removeObjects()` are marked with `#[\NoDiscard]` on PHP 8.5.

## Record Index Consistency

Record-identity lookups rely on each object's `hashCode()` result. If an object's record identity changes after it has already been inserted into the collection, the record index can become stale.

`ObjectCollection` exposes both compatibility behavior and strict indexed behavior.

### Normal Case

If record identity does not change while an object is in the collection, no extra work is required:

```php
$books = new ObjectCollection([$book]);

$books->containsSameRecord($book);
$books->indexOfSameRecord($book);
```

### Mark The Record Index Dirty

If application code mutates fields that affect `hashCode()`, mark the collection dirty:

```php
$books = new ObjectCollection([$book]);

$book->save();

$books->markRecordIndexDirty();

$sameBook = clone $book;
$found = $books->containsSameRecord($sameBook);
```

When the record index is marked dirty, same-record lookup is allowed to repair itself by scanning once and rebuilding the index.

### Force A Rebuild

If you know the collection's record identities have changed and you want to restore strict indexed lookups immediately, rebuild explicitly:

```php
$books = new ObjectCollection([$book]);

$book->save();
$books->repairRecordIndex();

$sameBook = clone $book;
$position = $books->indexOfSameRecord($sameBook);
```

### Compatibility vs Strict Indexed Lookup

The compatibility APIs preserve the existing `contains()` and `search()` style behavior:

- `containsSameRecord()`
- `indexOfSameRecord()`
- `contains()`
- `search()`
- `removeObject()`

If needed, they may scan on clean misses and rebuild the record index when the collection has been marked dirty or when a stale indexed candidate needs repair.

On a clean negative miss, they avoid rebuild work and return `null` or `false` if the record is not found.

The strict indexed APIs never scan or repair:

- `containsSameRecordIndexed()`
- `indexOfSameRecordIndexed()`

Use those methods when you want predictable index-only behavior.

## Common Usage Examples

### Check Exact Instance Membership

```php
$books = new ObjectCollection([$book1]);

$books->containsInstance($book1); // true
$books->containsInstance(clone $book1); // false
```

### Check Record Membership Across Clones

```php
$book1->save();
$book2 = clone $book1;

$books = new ObjectCollection([$book1]);

$books->containsSameRecord($book2); // true
$books->search($book2); // 0
```

### Use Strict Indexed Same-Record Lookup

```php
$book1->save();
$book2 = clone $book1;

$books = new ObjectCollection([$book1]);

$books->containsSameRecordIndexed($book2); // true
$books->indexOfSameRecordIndexed($book2); // 0
```

### Remove A Single Object While Preserving Order

```php
$books = new ObjectCollection([$book1, $book2, $book3]);

$books->removeObject($book2);

// Remaining order: [$book1, $book3]
```

### Remove Many Objects In One Pass

```php
$books = new ObjectCollection([$book1, $book2, $book3, $book4]);

$removedCount = $books->removeObjects([$book2, $book4]);

// $removedCount === 2
// Remaining order: [$book1, $book3]
```

### Fast Removal For Queue-Like Or Set-Like Workloads

```php
$jobs = new ObjectCollection([$job1, $job2, $job3]);

if ($jobs->removeObjectFast($job2)) {
    // Item was removed, but collection order may now be [$job1, $job3]
    // or [$job3, $job1] depending on which slot was removed.
}
```

## Choosing The Right API

- Use `containsInstance()` when you care about the exact same PHP object.
- Use `containsSameRecord()` when different instances may represent the same row.
- Use `containsSameRecordIndexed()` or `indexOfSameRecordIndexed()` when you want index-only lookup with no repair scan.
- Use `removeObject()` when order matters and you are removing one item.
- Use `removeObjects()` when order matters and you are removing many items.
- Use `removeObjectFast()` when order does not matter and removal throughput is more important.
- Use `markRecordIndexDirty()` or `repairRecordIndex()` when objects already in the collection change the fields that contribute to `hashCode()`.
