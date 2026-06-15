# UUID And UID Support

This branch added first-class support for four related schema types:

- `UUID`
- `UUID_BINARY`
- `UID`
- `UID_BINARY`

They cover two different PHP-facing models:

- `UUID` types are exposed as RFC 4122 strings
- `UID` types are exposed as Symfony `Uid` objects

## Type Semantics

### `UUID`

`UUID` is string-oriented at the PHP layer.

- Getter/setter values are UUID strings
- Storage type depends on the platform
- On MySQL, the default storage is binary unless native UUID storage is enabled

### `UUID_BINARY`

`UUID_BINARY` is also string-oriented at the PHP layer, but stored in a binary-friendly database type.

- Getter/setter values are UUID strings
- Query filters automatically convert UUID strings to the stored binary representation where needed

### `UID`

`UID` is object-oriented at the PHP layer.

- Getter/setter values normalize to Symfony `UuidV7`
- Strings and `Symfony\Component\Uid\Uuid` inputs are accepted and normalized
- Query filters convert values to the platform's stored form automatically

### `UID_BINARY`

`UID_BINARY` stores Symfony UID values in a compact binary form where the platform uses a binary type.

- Getter/setter values normalize to `UuidV7`
- Queries convert UID objects and strings automatically
- Non-primary-key `UID_BINARY` columns receive an implicit single-column index if no compatible index already exists

## Platform Storage

Current platform mappings are:

| Platform | `UUID` | `UUID_BINARY` | `UID` | `UID_BINARY` |
| --- | --- | --- | --- | --- |
| MySQL | `BINARY(16)` by default, optional native `UUID` | `BINARY(16)` | `CHAR(36)` | `BINARY(16)` |
| PostgreSQL | `uuid` | `BYTEA` | `uuid` | `uuid` |
| SQLite | `BLOB` | `BLOB` | `TEXT` | `BLOB` |
| SQL Server | `UNIQUEIDENTIFIER` | `BINARY(16)` | `UNIQUEIDENTIFIER` | `BINARY(16)` |
| Oracle | `UUID` | `RAW(16)` | `UUID` | `RAW(16)` |

For SQLite, `UUID` currently maps to the same binary-backed storage used by `UUID_BINARY`.

## MySQL Configuration

MySQL keeps `UUID` binary-backed by default:

```yaml
propel:
  database:
    adapters:
      mysql:
        uuidColumnType: binary
```

If the target database supports a native UUID column type, it can be enabled:

```yaml
propel:
  database:
    adapters:
      mysql:
        uuidColumnType: native
```

That switch affects `UUID`. It does not change `UUID_BINARY`, `UID`, or `UID_BINARY`.

## Runtime Conversion

Generated model and query classes handle conversion automatically.

### Model Hydration

- `UUID_BINARY` values are converted from stored bytes to UUID strings
- `UID_BINARY` values are converted from stored bytes to `UuidV7`
- `UID` values are converted from stored text/native UUID values to `UuidV7`

### Query Filtering

Generated `filterBy...()` methods convert incoming values before binding:

- `UUID_BINARY` and binary-backed `UUID` values use UUID-to-binary conversion where needed
- `UID_BINARY` values use UID-to-binary conversion where needed
- `UID` values use RFC 4122 string form where needed

Arrays are supported through the recursive converter helpers.

## UUID Version Metadata

UUID-related vendor metadata now supports `UuidVersion`.

Supported values are:

- `1`
- `4`
- `6`
- `7`

If not specified, Propel assumes UUIDv7.

Example:

```xml
<column name="id" primaryKey="true" type="UUID">
    <vendor type="mysql">
        <parameter name="UuidVersion" value="7"/>
    </vendor>
</column>
```

## Swap Flag Metadata

Binary UUID conversion also supports `UuidSwapFlag`.

- Allowed values are `true` and `false`
- If omitted, UUIDv7 defaults to `false`
- Non-v7 UUIDs default to `true`

This matters for MySQL-compatible `UUID_TO_BIN()` / `BIN_TO_UUID()` ordering semantics.

## UID Primary Key Generation

Generated object code now emits UUIDv7-based primary key generation for MySQL `UID` and `UID_BINARY` primary keys when a new object is saved without an assigned value.

When the table uses the `timestampable` behavior, the generated code can use the `created_at` timestamp as the UUIDv7 time source.

## Example Schema

```xml
<table name="event_log">
    <column name="id" primaryKey="true" type="UID_BINARY"/>
    <column name="trace_id" type="UUID_BINARY"/>
    <column name="request_id" type="UID"/>
</table>
```

Practical effect:

- `id` is exposed as `UuidV7`
- `trace_id` is exposed as a UUID string
- `request_id` is exposed as `UuidV7`
- queries can use natural PHP values without manual byte conversion
