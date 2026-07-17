# Case-insensitive queries

Propel can make text comparisons case-insensitive per query, or generate that behavior as a database or table default. On PostgreSQL, case-insensitive `LIKE` comparisons use `ILIKE` and `NOT ILIKE`.

## Per-query settings

Use `setIgnoreCase(true)` to apply case-insensitive matching to every text criterion in a query. It also makes text `ORDER BY` expressions case-insensitive:

```php
BookQuery::create()
    ->setIgnoreCase(true)
    ->filterByTitle('propel%')
    ->find();
```

Use `setLikeIgnoreCase(true)` when only `LIKE` and `NOT LIKE` criteria should ignore case. Equality comparisons and ordering remain unchanged:

```php
BookQuery::create()
    ->setLikeIgnoreCase(true)
    ->filterByTitle('propel%', Criteria::LIKE)
    ->filterByIsbn('978%', Criteria::NOT_LIKE)
    ->find();
```

On PostgreSQL, the second example produces `title ILIKE ...` and `isbn NOT ILIKE ...`. On other SQL platforms, Propel's generated `filterBy...()` criteria use the platform's case-folding expression around `LIKE` operands.

## Generated defaults

Set either option in a `propel` vendor block in `schema.xml`. Put it on the `database` for every generated query in that database, or on an individual `table` for that table's generated query.

```xml
<database name="app">
    <table name="book">
        <column name="id" type="INTEGER" primaryKey="true"/>
        <column name="title" type="VARCHAR"/>
    </table>

    <vendor type="propel">
        <parameter name="defaultLikeIgnoreCase" value="true"/>
    </vendor>
</database>
```

The generated `BookQuery` now calls `setLikeIgnoreCase(true)` in its constructor. Use `defaultIgnoreCase` instead when every text comparison and text ordering should be case-insensitive:

```xml
<vendor type="propel">
    <parameter name="defaultIgnoreCase" value="true"/>
</vendor>
```

Table-level parameters override database-level parameters individually. For example, retain a database-wide LIKE-only default while disabling the broader default for one table:

```xml
<table name="audit_log">
    <column name="id" type="INTEGER" primaryKey="true"/>
    <vendor type="propel">
        <parameter name="defaultIgnoreCase" value="false"/>
    </vendor>
</table>
```

Regenerate the model classes after changing the schema. These defaults affect generated query classes only; a query can still change either setting with `setIgnoreCase()` or `setLikeIgnoreCase()`.

## PostgreSQL indexes

`ILIKE '%term%'` often needs a PostgreSQL trigram index for good performance. For example:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX book_title_trgm_idx ON book USING GIN (title gin_trgm_ops);
```
