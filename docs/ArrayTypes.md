# Array Types

Propel has two array column types. They have different storage formats and query behavior:

| Propel type | Storage | Platforms | PHP value |
| --- | --- | --- | --- |
| `ARRAY` | Propel's delimiter-encoded text (`| value |`) | Portable | `array` |
| `NATIVE_ARRAY` | PostgreSQL's native array type (`TEXT[]`, `INTEGER[]`, etc.) | PostgreSQL only | `array` |

Use `ARRAY` when a schema must work across database platforms. Use `NATIVE_ARRAY` when the application targets PostgreSQL and needs native array operators, typed elements, or PostgreSQL array indexes.

## Legacy `ARRAY`

Declare a legacy array with `type="ARRAY"`:

```xml
<column name="tags" type="ARRAY"/>
```

Values are stored as text using Propel's historical delimiter format. The generated model still exposes a PHP array:

```php
$event->setTags(['php', 'postgres']);
$event->addTag('orm');
$event->removeTag('php');
$tags = $event->getTags();
```

For a plural column name such as `tags`, generated queries provide `filterByTags()` and the singular convenience method `filterByTag()`. Legacy collection comparisons are implemented with text `LIKE` predicates, so values containing the delimiter need application-level care.

## PostgreSQL `NATIVE_ARRAY`

Declare a native array with `type="NATIVE_ARRAY"`. The default SQL type is `TEXT[]`; specify `sqlType` for another PostgreSQL element type:

```xml
<table name="event">
    <column name="id" type="INTEGER" primaryKey="true"/>
    <column name="tags" type="NATIVE_ARRAY"/>
    <column name="scores" type="NATIVE_ARRAY" sqlType="INTEGER[]" required="true"/>
    <column name="reviewer_ids" type="NATIVE_ARRAY" sqlType="UUID[]"/>
</table>
```

`NATIVE_ARRAY` supports one-dimensional homogeneous arrays. PostgreSQL must be the target platform; schema generation on other platforms reports an unsupported-type error. Nested PHP arrays are rejected.

### Reading and writing values

Generated objects use normal PHP arrays. Nullable columns use `null` when the database value is `NULL`; required columns start with an empty array. PostgreSQL `NULL` elements are preserved:

```php
$event->setTags(['php', null, 'postgres']);
$event->save();

$event = EventQuery::create()->findOneById($id);
$tags = $event->getTags(); // ['php', null, 'postgres']
```

The element type controls hydration for common scalar types. For example, `INTEGER[]` is returned as an array of integers, while `TEXT[]` is returned as strings.

### Defaults

Use PostgreSQL's array literal format for a literal `defaultValue`:

```xml
<column
    name="states"
    type="NATIVE_ARRAY"
    sqlType="TEXT[]"
    defaultValue="{pending,active}"
/>
```

Use `defaultExpr` for a database expression such as `ARRAY['pending', 'active']::text[]`. Expression defaults are applied by PostgreSQL when the row is inserted.

### Filtering

For a plural column such as `tags`, Propel generates `filterByTags()` and `filterByTag()`:

```php
// Contains every requested value (the default): tags @> ARRAY['php', 'postgres']
EventQuery::create()->filterByTags(['php', 'postgres']);

// Contains at least one requested value: tags && ARRAY['php', 'postgres']
EventQuery::create()->filterByTags(['php', 'postgres'], Criteria::CONTAINS_SOME);

// Contains none of the requested values; NULL arrays are included.
EventQuery::create()->filterByTags(['legacy'], Criteria::CONTAINS_NONE);

// Singular convenience method; equivalent to filterByTags(['php']).
EventQuery::create()->filterByTag('php');
```

`CONTAINS_ALL` is the default comparison. Empty arrays retain PostgreSQL semantics: an empty containment set matches every non-`NULL` array, while an empty overlap set matches none.

### Indexes

For containment or overlap queries on a frequently searched column, add a GIN index in a migration:

```sql
CREATE INDEX event_tags_gin ON event USING GIN (tags);
```

### Migrations and reverse engineering

The PostgreSQL reverse-schema parser recognizes columns reported as `ARRAY` by `information_schema` and emits `NATIVE_ARRAY` with the concrete SQL type.

When changing a legacy `ARRAY` column to `NATIVE_ARRAY`, PostgreSQL migrations convert Propel's `| value |` text representation to the requested native array type. Review the generated migration if legacy values contain delimiter-like content or require custom conversion. The reverse conversion is also supported when changing back to `ARRAY`.

