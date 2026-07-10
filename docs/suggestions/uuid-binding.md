I’ve already isolated the PostgreSQL behavior enough to answer this directly: the key tradeoff is native `uuid` semantics versus forcing 16-byte transport through a non-native path.

Not in the absolute sense, but it is the most appropriate performant fix for the current PostgreSQL design.

In this codebase, PostgreSQL maps `UID_BINARY` to native `uuid`, not to a 16-byte blob type, in PgsqlPlatform.php. Once that decision is made, the efficient and correct PDO path is to send an RFC 4122 string and bind it as `PDO::PARAM_STR`. PostgreSQL stores `uuid` internally in 16 bytes anyway; the extra cost is just parsing the incoming text once per bind. In practice, that parse cost is small, and it is far cheaper than fighting PDO into a fake binary/LOB path that PostgreSQL does not accept for `uuid`.

What would be faster in theory is binary protocol transfer of a typed `uuid` parameter, but PDO pgsql does not give us a clean native way to send a 16-byte value as PostgreSQL `uuid`. `PDO::PARAM_LOB` is not that. It is exactly the mismatch that caused the failure. So within Propel’s current stack, `PARAM_STR` is the fastest correct option for native PostgreSQL `uuid`.

If the goal is absolute transport compactness rather than native `uuid` semantics, the real alternative is architectural: map `UID_BINARY` to `BYTEA` on PostgreSQL and use UuidConverter.php binary conversion end-to-end. That would avoid text parsing, but you would give up native `uuid` behavior, casts, operators, and some ergonomics. With the current mapping, binding as string is the right tradeoff.

If you want, I can do one of these next:

1. Keep the native `uuid` approach and tighten the fix so it is limited exactly to PostgreSQL `UID_BINARY`.
2. Prototype a `BYTEA` mapping variant for comparison.
3. Add a micro-benchmark to measure string-bound `uuid` versus binary `bytea` in this repo.
