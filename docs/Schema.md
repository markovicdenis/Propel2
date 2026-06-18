# Database Schema

This document is a Markdown rewrite of Propel's schema reference for `schema.xml`.
The Propel generator ships with both a DTD and a detailed XSD to validate schema documents when you build SQL and object model classes.

## At A Glance

The schema tree looks like this:

```xml
<database>
  <table>
    <column>
      <inheritance />
    </column>
    <foreign-key>
      <reference />
    </foreign-key>
    <index>
      <index-column />
    </index>
    <unique>
      <unique-column />
    </unique>
    <id-method-parameter />
  </table>
  <external-schema />
</database>
```

You can find example schemas throughout the test fixtures used by Propel's own test suite.

> Tip: if your IDE supports XML autocompletion, add the Propel XSD to the root `<database>` tag:

```xml
<database name="my_connection_name" defaultIdMethod="native"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:noNamespaceSchemaLocation="http://xsd.propelorm.org/1.6/database.xsd">
```

## Detailed Reference

This section describes where each attribute and child element belongs.

Conventions used below:

- Text wrapped in `/.../` is user-supplied text.
- Optional items are wrapped in `[` and `]`.
- Alternatives are separated by `|`.
- A value wrapped in `{...}` is the default choice.
- `...` means the previous item may be repeated.

### `database` element

The root element defines defaults inherited by nested tables.

```xml
<database
  name="/DatabaseName/"
  defaultIdMethod="native|none"
  [package="/ProjectName/"]
  [schema="/SQLSchema/"]
  [namespace="/ClassNamespace/"]
  [baseClass="/BaseClassName/"]
  [defaultPhpNamingMethod="nochange|{underscore}|phpname|clean"]
  [heavyIndexing="true|false"]
  [identifierQuoting="true|{false}"]
  [tablePrefix="/TablePrefix/"]
>
  <table>
  <external-schema>
  ...
</database>
```

Only `name` and `defaultIdMethod` are required.

A `database` element may contain one or more `<table>` elements and optional `<external-schema>` elements.

#### Database Attributes

- `defaultIdMethod`: default id strategy for auto-increment columns.
- `package`: package or subdirectory used for generated classes.
- `schema`: default SQL schema for contained tables.
- `namespace`: default PHP namespace for generated classes.
- `baseClass`: default base class for generated Propel objects instead of `propel.om.BaseObject`.
- `defaultPhpNamingMethod`: naming strategy for generated phpNames. The default is `underscore`.
- `heavyIndexing`: adds indexes for each component of composite primary keys.
- `identifierQuoting`: quotes identifiers in generated SQL. Useful when names collide with reserved words.
- `tablePrefix`: adds a prefix to all generated SQL table names.

### `table` element

The `table` element is the main schema building block.

```xml
<table
  name="/TableName/"
  [shortName="/ShortTableNameForGeneratedNames/"]
  [idMethod="native|{none}"]
  [phpName="/PhpObjectName/"]
  [package="/PhpObjectPackage/"]
  [schema="/SQLSchema/"]
  [namespace="/PhpObjectNamespace/"]
  [skipSql="true|false"]
  [abstract="true|false"]
  [phpNamingMethod="nochange|{underscore}|phpname|clean"]
  [baseClass="/BaseClassName/"]
  [description="/Table description/"]
  [heavyIndexing="true|false"]
  [identifierQuoting="true|{false}"]
  [readOnly="true|false"]
  [treeMode="NestedSet|MaterializedPath"]
  [reloadOnInsert="true|false"]
  [reloadOnUpdate="true|false"]
  [allowPkInsert="true|false"]
>
  <column>
  ...
  <foreign-key>
  ...
  <index>
  ...
  <unique>
  ...
  <id-method-parameter>
  ...
</table>
```

Only `name` is required. The `idMethod`, `package`, `schema`, `namespace`, `phpNamingMethod`, `baseClass`, and `heavyIndexing` values inherit from the enclosing `database` element.

#### Table Attributes

- `idMethod`: id strategy for auto-increment columns.
- `shortName`: optional abbreviated table name used in generated names that derive from the table name, such as auto-generated index names.
- `phpName`: generated model class name. Defaults to a CamelCase version of the table name.
- `package`: package or subdirectory for generated classes.
- `schema`: SQL schema containing the table.
- `namespace`: PHP namespace for generated classes. If it starts with `\`, it overrides the database namespace; otherwise it is appended to it.
- `skipSql`: do not generate DDL for this table. Often used with `readOnly` for views.
- `abstract`: generate the stub class as abstract.
- `phpNamingMethod`: naming strategy for generated phpNames. The default is `underscore`.
- `baseClass`: base class for generated Propel objects.
- `description`: free-form table description.
- `heavyIndexing`: adds indexes for each component of composite primary keys.
- `identifierQuoting`: quotes identifiers in generated SQL.
- `readOnly`: suppresses setters, `save()`, and `delete()`.
- `treeMode`: marks the table as a tree. Supported values are `NestedSet` and deprecated `MaterializedPath`.
- `reloadOnInsert`: reload the object after `INSERT`, useful when triggers or database defaults mutate the row.
- `reloadOnUpdate`: reload the object after `UPDATE`, useful when triggers or database defaults mutate the row.
- `allowPkInsert`: allows explicit primary key insertion even when `idMethod="native"`.

Example:

```xml
<table name="sportsbook_transactions" shortName="sbtx">
```

When `shortName` is present, Propel prefers it over the full table name when building auto-generated index names. The SQL table name itself is unchanged, and XML schema round-trips preserve the attribute.

### `column` element

```xml
<column
  name="/ColumnName/"
  [phpName="/PHPColumnName/"]
  [tableMapName="/TABLEMAPNAME/"]
  [primaryKey="true|{false}"]
  [required="true|{false}"]
  [type="BOOLEAN|TINYINT|SMALLINT|INTEGER|BIGINT|DOUBLE|FLOAT|REAL|DECIMAL|NUMERIC|CHAR|VARCHAR|LONGVARCHAR|DATE|TIME|TIMESTAMP|BLOB|CLOB|OBJECT|ARRAY|ENUM|SET|GEOMETRY|BU_DATE|BU_TIMESTAMP|BOOLEAN_EMU|BINARY|VARBINARY|LONGVARBINARY|UUID|UUID_BINARY|UID|UID_BINARY"]
  [phpType="boolean|int|integer|double|float|string|/BuiltInClassName/|/UserDefinedClassName/"]
  [sqlType="/NativeDatabaseColumnType/"]
  [size="/NumericLengthOfColumn/"]
  [scale="/DigitsAfterDecimalPlace/"]
  [defaultValue="/AnyDefaultValueMatchingType/"]
  [defaultExpr="/AnyDefaultExpressionMatchingType/"]
  [valueSet="/CommaSeparatedValues/"]
  [autoIncrement="true|{false}"]
  [lazyLoad="true|{false}"]
  [description="/ColumnDescription/"]
  [primaryString="true|{false}"]
  [phpNamingMethod="nochange|underscore|phpname"]
  [inheritance="single|{false}"]
>
  [<inheritance key="/KeyName/" class="/ClassName/" [extends="/BaseClassName/"] />]
</column>
```

#### Column Attributes

- `type`: Propel's database-agnostic column type.
- `sqlType`: native SQL type override for generated DDL.
- `defaultValue`: PHP-side default value for new objects. Interpreted as a string.
- `defaultExpr`: SQL expression used as the database default in generated DDL.
- `valueSet`: comma-separated values for `ENUM` and `SET` columns.
- `lazyLoad`: omit the column from normal query hydration and fetch it only when the getter is called.
- `primaryString`: use this column as the default value for `__toString()`.

> Tip: marking large `BLOB` and `CLOB` columns as lazy-loaded avoids transferring heavy payloads until you actually read them.

### `foreign-key` element

Use a `foreign-key` element to define relationships to another table.

```xml
<foreign-key
  foreignTable="/TheOtherTableName/"
  [foreignSchema="/TheOtherTableSQLSchema/"]
  [name="/ForeignKeyName/"]
  [phpName="/ForeignObjectMethodName/"]
  [refPhpName="/ReverseRelationMethodName/"]
  [onDelete="cascade|setnull|restrict|none"]
  [onUpdate="cascade|setnull|restrict|none"]
  [skipSql="true|false"]
  [skipRefCode="true|false"]
  [defaultJoin="Criteria::INNER_JOIN|Criteria::LEFT_JOIN"]
>
  <reference local="/LocalColumnName/" foreign="/ForeignColumnName/" />
</foreign-key>
```

#### Foreign Key Attributes

- `skipSql`: model the relationship without generating the physical foreign key. Defaults to `false`.
- `skipRefCode`: generate the SQL foreign key and the local-table relation methods, but skip reverse-side relation code on the referenced table. Defaults to `false`.
- `defaultJoin`: overrides the generated default join type for `joinXXX()` methods.

### `index` element

```xml
<index [name="/IndexName/"] [where="/SqlPredicate/"]>
  <index-column name="/ColumnName/" [size="/LengthOfIndexColumn/"] />
  ...
</index>
```

Use `where` to define a partial index predicate.

- `where`: SQL predicate appended to the generated index DDL.
- `size`: supported for MySQL index columns.

Platform notes:

- PostgreSQL generates native partial indexes, for example `CREATE INDEX ... WHERE revoked_at IS NULL`.
- MySQL does not support non-unique partial indexes. Propel will throw when `where` is used on a non-unique `<index>`.

### `unique` element

```xml
<unique [name="/IndexName/"] [where="/SqlPredicate/"]>
  <unique-column name="/ColumnName/" [size="/LengthOfIndexColumn/"] />
  ...
</unique>
```

Use `where` to define a partial unique index predicate.

- `where`: SQL predicate appended to the generated unique index DDL.
- `size`: supported for MySQL unique index columns.

Platform notes:

- PostgreSQL generates native partial unique indexes with `CREATE UNIQUE INDEX ... WHERE ...`.
- MySQL emulates partial unique indexes by adding a functional key part that evaluates to `1` for matching rows and `NULL` otherwise. This allows uniqueness to apply only to rows matching the predicate.

Example:

```xml
<unique name="postback_user_unlinks_active_unique" where="revoked_at IS NULL">
  <unique-column name="user_id" />
  <unique-column name="postback_config_id" />
</unique>
```

### `id-method-parameter` element

Sequence-backed databases such as PostgreSQL and Oracle can customize the backing sequence name:

```xml
<id-method-parameter value="my_custom_sequence_name" />
```

### `external-schema` element

The `external-schema` element includes another schema file.

```xml
<external-schema
  filename="/PathToSchemaFile/"
  referenceOnly="{true}|false"
/>
```

The `filename` may be relative or absolute. The external file must contain a `<database>` element with the same database name as the current file.

By default, external tables are treated as reference-only and ignored by the `sql` task. Set `referenceOnly="false"` if they should participate in generated SQL.

## Column Types

These are Propel's portable column types and typical MySQL and PHP mappings.

### Text Types

| Propel Type   | Description                         | Example Default DB Type (MySQL) | Default PHP Native Type |
| ------------- | ----------------------------------- | ------------------------------- | ----------------------- |
| `CHAR`        | Fixed-length character data         | `CHAR`                          | `string`                |
| `VARCHAR`     | Variable-length character data      | `VARCHAR`                       | `string`                |
| `LONGVARCHAR` | Long variable-length character data | `TEXT`                          | `string`                |
| `CLOB`        | Character large object              | `LONGTEXT`                      | `string`                |

`LONGVARCHAR` and `CLOB` do not need an explicit size and can store very large strings.

### Numeric Types

| Propel Type | Description           | Example Default DB Type (MySQL) | Default PHP Native Type |
| ----------- | --------------------- | ------------------------------- | ----------------------- |
| `NUMERIC`   | Numeric data          | `DECIMAL`                       | `string`                |
| `DECIMAL`   | Decimal data          | `DECIMAL`                       | `string`                |
| `TINYINT`   | Tiny integer          | `TINYINT`                       | `int`                   |
| `SMALLINT`  | Small integer         | `SMALLINT`                      | `int`                   |
| `INTEGER`   | Integer               | `INTEGER`                       | `int`                   |
| `BIGINT`    | Large integer         | `BIGINT`                        | `string`                |
| `REAL`      | Real number           | `REAL`                          | `double`                |
| `FLOAT`     | Floating point number | `FLOAT`                         | `double`                |
| `DOUBLE`    | Floating point number | `DOUBLE`                        | `double`                |

> Tip: `BIGINT` maps to `string`, which preserves 64-bit values even on 32-bit PHP builds.

### Binary Types

| Propel Type     | Description                      | Example Default DB Type (MySQL) | Default PHP Native Type |
| --------------- | -------------------------------- | ------------------------------- | ----------------------- |
| `BINARY`        | Fixed-length binary data         | `BINARY`                        | `string`                |
| `VARBINARY`     | Variable-length binary data      | `MEDIUMBLOB`                    | `stream` or `string`    |
| `LONGVARBINARY` | Long variable-length binary data | `LONGBLOB`                      | `stream` or `string`    |
| `BLOB`          | Binary large object              | `BLOB`                          | `stream` or `string`    |

> Tip: `BLOB` columns commonly map to streams and are suitable for large binary payloads.

### Temporal Types

| Propel Type | Description                                      | Example Default DB Type (MySQL) | Default PHP Native Type |
| ----------- | ------------------------------------------------ | ------------------------------- | ----------------------- |
| `DATE`      | Date, for example `YYYY-MM-DD`                   | `DATE`                          | `DateTime`              |
| `TIME`      | Time, for example `HH:MM:SS`                     | `TIME`                          | `DateTime`              |
| `TIMESTAMP` | Date and time, for example `YYYY-MM-DD HH:MM:SS` | `DATETIME`                      | `DateTime`              |

### Other Types

- `BOOLEAN`: maps to PHP `bool` and is stored as `BOOLEAN` or `TINYINT` depending on platform support.
- `ENUM`: accepts one value from a comma-separated `valueSet`.
- `SET`: accepts multiple values from a comma-separated `valueSet`.
- `OBJECT`: maps to a PHP object and is stored as binary.
- `ARRAY`: maps to a PHP array and is stored as text.
- `UUID`: uses a database-native UUID column when available, otherwise the binary UUID strategy.
- `UUID_BINARY`: stores UUID values in a compact binary format. See [UUID And UID Support](UuidSupport.md).
- `UID`: exposes Symfony `UuidV7` objects at the PHP layer and stores them in the platform's preferred UUID-capable representation.
- `UID_BINARY`: exposes `UuidV7` objects and stores them in compact binary form where supported. Non-primary-key `UID_BINARY` columns may receive an implicit single-column index if no compatible index already exists.

UUID-related defaults:

- Propel assumes UUIDv7 unless vendor metadata says otherwise.
- `UUID` and `UUID_BINARY` are string-oriented in PHP.
- `UID` and `UID_BINARY` normalize to Symfony `UuidV7` objects in PHP.
- See [UUID And UID Support](UuidSupport.md) for platform storage details and runtime conversion behavior.

### Legacy Temporal Types

These Propel 1.2-era types are still supported but usually no longer needed:

| Propel Type    | Description                                                  | Example Default DB Type (MySQL) | Default PHP Native Type |
| -------------- | ------------------------------------------------------------ | ------------------------------- | ----------------------- |
| `BU_DATE`      | Pre-/post-epoch date, for example `1201-03-02`               | `DATE`                          | `DateTime`              |
| `BU_TIMESTAMP` | Pre-/post-epoch timestamp, for example `1201-03-02 12:33:00` | `TIMESTAMP`                     | `DateTime`              |

## Customizing Mappings

### Specify Column Attributes

Override per-column mappings when you need a specific PHP or SQL representation.

PHP type override:

```xml
<column name="population_served" type="INTEGER" phpType="string"/>
```

SQL type override:

```xml
<column name="ip_address" type="VARCHAR" sqlType="inet"/>
```

### Adding Vendor Info

Vendor-specific metadata affects generated SQL. Add a `<vendor>` tag with a `type` attribute and one or more `<parameter>` children.

```xml
<table name="book">
  <vendor type="mysql">
    <parameter name="Engine" value="InnoDB"/>
    <parameter name="Charset" value="utf8"/>
  </vendor>
</table>
```

Example generated SQL:

```sql
CREATE TABLE book
  ()
  ENGINE = InnoDB
  DEFAULT CHARACTER SET utf8;
```

#### Global Vendor Info

Define `<vendor>` under `<database>` to apply defaults to every table, then override them locally where needed.

```xml
<database name="bookstore">
  <vendor type="mysql">
    <parameter name="Engine" value="InnoDB"/>
  </vendor>

  <table name="book">
    <!-- ... -->
  </table>
</database>
```

#### MySQL Vendor Info

Supported MySQL vendor parameters:

```text
Name             | Example values
-----------------|---------------------------------------------------------------
// in <table> element
Engine           | MYISAM, InnoDB, BDB, MEMORY, ISAM, MERGE, MRG_MYISAM, etc.
AutoIncrement    | 1234, N, etc.
AvgRowLength     |
Charset          | utf8, latin1, etc.
Checksum         | 0, 1
Collate          | utf8_unicode_ci, latin1_german1_ci, etc.
Connection       | mysql://fed_user@remote_host:9306/federated/test_table
DataDirectory    | /var/db/foo
DelayKeyWrite    | 0, 1
IndexDirectory   | /var/db/foo
InsertMethod     | FIRST, LAST
KeyBlockSize     | 0, 1024, etc.
MaxRows          | 1000, 4294967295, etc.
MinRows          | 1000
PackKeys         | 0, 1, DEFAULT
RowFormat        | FIXED, DYNAMIC, COMPRESSED, COMPACT, REDUNDANT
Union            | (t1,t2)
// in <column> element
Charset          | utf8, latin1, etc.
Collate          | utf8_unicode_ci, latin1_german1_ci, etc.
// in <index> element
Index_type       | FULLTEXT
```

UUID-related column vendor parameters:

```text
Name             | Example values
-----------------|----------------
UuidVersion      | 1, 4, 6, 7
UuidSwapFlag     | true, false
```

- `UuidVersion` defaults to `7`.
- `UuidSwapFlag` controls MySQL-compatible `UUID_TO_BIN()` / `BIN_TO_UUID()` swap behavior for binary UUID storage.
- If `UuidSwapFlag` is omitted, Propel defaults it to `false` for UUIDv7 and `true` for non-v7 UUIDs.

Example:

```xml
<column name="id" primaryKey="true" type="UUID_BINARY">
  <vendor type="mysql">
    <parameter name="UuidVersion" value="7"/>
    <parameter name="UuidSwapFlag" value="false"/>
  </vendor>
</column>
```

#### Oracle Vendor Info

Supported Oracle vendor parameters:

```text
Name             | Example values
-----------------|----------------
// in <table> element
PCTFree          | 20
InitTrans        | 4
MinExtents       | 1
MaxExtents       | 99
PCTIncrease      | 0
Tablespace       | L_128K
PKPCTFree        | 20
PKInitTrans      | 4
PKMinExtents     | 1
PKMaxExtents     | 99
PKPCTIncrease    | 0
PKTablespace     | IL_128K
// in <index> element
PCTFree          | 20
InitTrans        | 4
MinExtents       | 1
MaxExtents       | 99
PCTIncrease      | 0
Tablespace       | L_128K
```

#### PostgreSQL Vendor Info

Supported PostgreSQL vendor parameters, with vendor type `pgsql`:

```text
Name              | Example values
------------------|---------------
// in <foreign-key> element
deferrable        | true
initiallyDeferred | false
```

### Using Custom Platform

To override Propel's type-to-platform mapping globally, create a custom platform class and register it in your build configuration.

```php
<?php

require_once 'propel/engine/platform/MysqlPlatform.php';

class CustomMysqlPlatform extends MysqlPlatform
{
    protected function initialize()
    {
        parent::initialize();

        $this->setSchemaDomainMapping(new Domain(PropelTypes::NUMERIC, 'DECIMAL'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::LONGVARCHAR, 'TEXT'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::BINARY, 'BLOB'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::VARBINARY, 'MEDIUMBLOB'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::LONGVARBINARY, 'LONGBLOB'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::BLOB, 'LONGBLOB'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::CLOB, 'LONGTEXT'));
    }
}
```

Register the custom platform in your build configuration:

```ini
propel.platform.class = CustomMysqlPlatform
```
