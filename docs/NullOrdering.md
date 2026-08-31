# Null ordering

SQL leaves it to the database system where NULL values end up in an `ORDER BY` clause, so the same query returns rows in a different order depending on where it runs:

| Database system | NULL sorts as | `ORDER BY x ASC` | `ORDER BY x DESC` |
| --- | --- | --- | --- |
| MySQL, SQLite, SQL Server | the smallest value | NULLs first | NULLs last |
| PostgreSQL, Oracle | the largest value | NULLs last | NULLs first |

The difference is easy to miss until a listing is sorted descending on a nullable column: on PostgreSQL and Oracle, the rows with no value are the ones at the top, and with pagination they can fill the entire first page.

Propel can settle the question explicitly by emitting `NULLS FIRST`/`NULLS LAST`, which makes ordering portable across database systems.

## Configuration

Null ordering is off by default: without configuration Propel emits no null ordering at all and every query keeps the behaviour of its database system.

To change it, set the ordering once for every connection of the application:

```php
use Propel\Runtime\Adapter\NullOrdering;
use Propel\Runtime\Adapter\Pdo\PdoAdapter;

PdoAdapter::setDefaultNullOrdering(NullOrdering::NullsSmallest);
```

An adapter can also carry a setting of its own, which takes precedence over that default:

```php
/** @var \Propel\Runtime\Adapter\Pdo\PdoAdapter $adapter */
$adapter = Propel::getServiceContainer()->getAdapter('bookstore');
$adapter->setNullOrdering(NullOrdering::NullsSmallest);
```

Pass `null` to drop it again and follow the default, or `NullOrdering::Native` to exempt one connection from a default the others use.

The three settings are:

| Setting | Meaning |
| --- | --- |
| `NullOrdering::Native` | Leave the placement to the database system. No clause is emitted. This is the default. |
| `NullOrdering::NullsSmallest` | NULLs sort before all other values, like MySQL: `ASC NULLS FIRST`, `DESC NULLS LAST`. |
| `NullOrdering::NullsLargest` | NULLs sort after all other values, like PostgreSQL: `ASC NULLS LAST`, `DESC NULLS FIRST`. |

Because the setting describes the wanted result rather than the clause to write, the same value can be applied to every connection of an application. Adapters that already order that way, and adapters whose database system has no `NULLS FIRST`/`NULLS LAST` clause, are left alone.

## Generated SQL

With `NullOrdering::NullsSmallest` on a PostgreSQL connection:

```php
BookQuery::create()
    ->orderByPrice(Criteria::DESC)   // price is nullable
    ->orderByTitle(Criteria::ASC)    // title is NOT NULL
    ->find();
```

```sql
ORDER BY book.price DESC NULLS LAST,book.title ASC
```

Propel spells out the null ordering only where it changes the result:

- The column must be nullable. A `NOT NULL` column sorts the same either way, so it keeps the ordering that indexes can serve (see below).
- The database system must have the clause. On MySQL and SQL Server nothing is ever emitted.
- The requested ordering must differ from the native one. Asking for `NullsLargest` on PostgreSQL emits nothing, because that is what PostgreSQL already does.

Entries of an `ORDER BY` clause that are not plain columns - aggregates, function calls and other expressions - keep the native null ordering, as their nullability is not known.

One case escapes the nullability check: a `NOT NULL` column can still produce NULLs as the result of an outer join. Such columns keep the native ordering, since the column itself cannot be null.

## Indexes

An explicit null ordering cannot be served by an ordinary index. A PostgreSQL btree index on `(price)` provides the order `price ASC NULLS LAST`, and read backwards `price DESC NULLS FIRST`. It cannot provide `price DESC NULLS LAST`, so the planner adds a sort:

```
ORDER BY price DESC LIMIT 15
  Limit
    Index Scan Backward using book_price_idx on book

ORDER BY price DESC NULLS LAST LIMIT 15
  Limit
    Sort
      Sort Key: price DESC NULLS LAST
      Index Only Scan using book_price_idx on book
```

Note that PostgreSQL does not reason about nullability here: the sort appears even on a `NOT NULL` column. This is why Propel leaves `NOT NULL` columns alone, and it is the cost to weigh for the nullable columns that remain. Where such a column carries a paginated listing, give it an index in the order the query asks for:

```sql
CREATE INDEX book_price_nulls_last_idx ON book (price DESC NULLS LAST);
```

## Adapter support

Adapters declare their own behaviour with two constants:

```php
class PgsqlAdapter extends PdoAdapter implements SqlAdapterInterface
{
    protected const SUPPORTS_NULL_ORDERING_CLAUSE = true;

    protected const NATIVE_NULL_ORDERING = NullOrdering::NullsLargest;
}
```

| Adapter | Native ordering | `NULLS FIRST`/`NULLS LAST` |
| --- | --- | --- |
| `PgsqlAdapter` | `NullsLargest` | yes |
| `OracleAdapter` | `NullsLargest` | yes |
| `SqliteAdapter` | `NullsSmallest` | yes (SQLite 3.30 and later) |
| `MysqlAdapter` | `NullsSmallest` | no |
| `MssqlAdapter`, `SqlsrvAdapter` | `NullsSmallest` | no |

`SqlAdapterInterface::getNullOrderingSuffix()` turns the configured setting into the fragment appended to a clause entry, and returns an empty string whenever the two constants above, or the nullability of the column, make the clause unnecessary. Adapters that need different rules can override it.
