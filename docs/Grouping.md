# Automatic Grouping

When a `ModelCriteria` has a `GROUP BY`, Propel automatically makes selected
columns that are not group keys valid for strict SQL grouping modes. Explicit
aggregation configured with `withAggregation()` always takes precedence over
the automatic fallback.

## Automatic aggregate selection

The fallback depends on the mapped Propel type:

| Column type | Automatic expression |
| --- | --- |
| Numeric and date/time types | `MAX(column)` |
| `BOOLEAN` | `MAX(column::int)` on PostgreSQL; `MAX(column)` elsewhere |
| Text, binary, `ENUM`, `SET` | `ANY_VALUE(column)` |
| `UUID`, `UUID_BINARY`, `UID`, `UID_BINARY` | `ANY_VALUE(column)` |
| `JSON` (including PostgreSQL `JSONB`) | `ANY_VALUE(column)` |
| `ARRAY`, `NATIVE_ARRAY`, `OBJECT`, `GEOMETRY` | `ANY_VALUE(column)` |

`ANY_VALUE()` means that the result is an arbitrary representative value from
each group. It is appropriate when the column is functionally identical within
the group or when any member is acceptable. It does not choose a deterministic
"latest" or "largest" row.

UUIDs, JSON/JSONB documents, PostgreSQL native arrays, serialized arrays,
objects, and geometry values are treated as opaque values. Propel therefore
does not attempt `MAX()` or `MIN()` on them: some database platforms do not
define these ordering aggregates for those types.

## Choosing a deterministic value

For a deterministic result, define the aggregate explicitly instead of relying
on automatic grouping. For example, aggregate a timestamp with `MAX()` and use
that result to select the corresponding row, or provide an appropriate custom
aggregation with `withAggregation()`.
