Current state: Propel already has usable JSON support. The existing `JSON` type stores a JSON string internally, encodes values in generated setters, and decodes them in getters ([ObjectBuilder.php](/run/media/denis/data/projects/Propel2/src/Propel/Generator/Builder/Om/ObjectBuilder.php:1230), [PropelTypes.php](/run/media/denis/data/projects/Propel2/src/Propel/Generator/Model/PropelTypes.php:191)). This means:

```xml
<column name="payload" type="JSON" sqlType="JSONB"/>
```

should already work for PostgreSQL storage and persistence. The main missing pieces are reverse-engineering fidelity and JSONB-specific querying.

Recommendation:

1. Keep `JSON` as the portable logical type.
2. Add a PostgreSQL-specific `JSONB` schema type if explicit intent and platform validation are important.
3. Reuse the existing JSON PHP behavior rather than introducing a separate codec. JSONB should still be represented internally as an encoded string and exposed through the current array/object getter API.
4. Do not call it `NATIVE_JSON`; `JSONB` is clearer because it names the actual PostgreSQL storage type.

The implementation would likely need:

- Add `PropelTypes::JSONB`, with PHP type `string` and `PDO::PARAM_STR`.
- Share the existing JSON accessor/mutator generation for `JSON` and `JSONB`.
- Map `JSONB` to `JSONB` in `PgsqlPlatform`.
- Reject `JSONB` on non-PostgreSQL platforms.
- Add `jsonb` reverse-parser support and preserve `sqlType="JSONB"` when generating schema.
- Add migration casts:
    - JSON → JSONB: `column::jsonb`
    - JSONB → JSON: `column::json`
- Add schema documentation explaining that JSONB normalizes object ordering/whitespace and is generally the better default for querying and indexing.

The biggest missing feature is query support. Existing `filterByPayload()` is primarily equality/text-oriented; passing a PHP array may be interpreted as an `IN` filter rather than a JSON value. PostgreSQL-specific criteria should be separate from array criteria, for example:

- `JSON_CONTAINS` → `payload @> :json`
- `JSON_CONTAINED_BY` → `payload <@ :json`
- `JSON_KEY_EXISTS` → `payload ? :key`
- `JSON_KEYS_ANY` → `payload ?| :keys`
- `JSON_KEYS_ALL` → `payload ?& :keys`

Right-hand values should be JSON-encoded with `JSON_THROW_ON_ERROR` before binding. Avoid reusing `CONTAINS_ALL` because JSON containment and array containment have different value semantics.

Index support can initially remain migration-based:

```sql
CREATE INDEX event_payload_gin
    ON event USING GIN (payload);
```

A future schema/index DSL could expose `USING GIN` and `jsonb_path_ops`, but that is separate scope.

Suggested implementation phases:

- Phase 1: document and test existing `JSON` + `sqlType="JSONB"` usage; add reverse-parser preservation.
- Phase 2: introduce explicit `JSONB` type with shared JSON model behavior.
- Phase 3: add JSONB-specific criteria/operators and integration tests.
- Phase 4: optionally add JSONB index configuration.

For PostgreSQL applications, I would make `JSONB` the recommended type for new structured data and retain `JSON` only when exact textual representation matters.
