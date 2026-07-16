# Column Transformers

Column transformers normalize text values in generated model setters and query
filters. They are useful for identifiers whose spelling should not be
case-sensitive in application code but whose database comparison is
case-sensitive, such as PostgreSQL `VARCHAR` currency codes.

Declare a transformer with the `transformer` column attribute:

```xml
<table name="payment">
    <column name="id" type="INTEGER" primaryKey="true"/>
    <column name="currency_code" type="VARCHAR" size="3" transformer="uppercase"/>
</table>
```

The supported values are:

| Schema value | Generated transformation |
| --- | --- |
| `strtoupper`, `uppercase`, `upper` | `strtoupper()` |
| `strtolower`, `lowercase`, `lower` | `strtolower()` |

`uppercase` and `lowercase` are generally the clearest schema spelling. The
schema dumper writes the canonical PHP function name (`strtoupper` or
`strtolower`). Unsupported transformer values cause schema generation to fail.

## Scalar columns

The generated setter transforms a non-`NULL` value before Propel records it as
modified. Consequently, the normalized value is used for both inserts and
updates:

```php
$payment->setCurrencyCode('usd');
$payment->save();

echo $payment->getCurrencyCode(); // USD
```

Generated `filterBy…()` methods transform scalar values and `IN` arrays before
binding them. Primary-key helpers are covered too:

```php
PaymentQuery::create()->filterByCurrencyCode('usd');       // compares with USD
PaymentQuery::create()->filterByCurrencyCode(['usd', 'eur']); // USD, EUR
PaymentQuery::create()->filterByPrimaryKey('usd');
PaymentQuery::create()->filterByPrimaryKeys(['usd', 'eur']);
```

## Array columns

Transformers work element-by-element for both Propel array representations.
`NULL` array elements are preserved.

```xml
<!-- Portable, legacy delimiter-encoded array -->
<column name="legacy_currency_codes" type="ARRAY" transformer="uppercase"/>

<!-- PostgreSQL native array -->
<column
    name="currency_codes"
    type="NATIVE_ARRAY"
    sqlType="VARCHAR(3)[]"
    transformer="uppercase"
/>
```

```php
$payment->setCurrencyCodes(['usd', null, 'eur']);
// ['USD', null, 'EUR']

PaymentQuery::create()->filterByCurrencyCodes(['usd', 'eur']);
// native-array filters bind ['USD', 'EUR']
```

For legacy `ARRAY` columns, generated collection filters use Propel's existing
delimiter-based filtering. For `NATIVE_ARRAY` columns, existing PostgreSQL
array operators such as `CONTAINS_ALL` and `CONTAINS_SOME` continue to apply;
only the supplied element values are normalized first.

## Scope and limitations

- Use transformers for text columns and text array elements. They call PHP's
  `strtoupper()` or `strtolower()` and are not intended for numeric, binary,
  JSON, or object values.
- Transformation happens in generated setters and generated query filters. Raw
  SQL, manually built criteria, and values written outside Propel bypass it.
- Hydration does not rewrite values already stored in the database. Normalize
  existing rows with a data migration before relying on case-insensitive
  application behavior.
- PHP's built-in case functions are byte-oriented. They are a good fit for
  ASCII identifiers such as ISO currency codes; use application-specific
  handling when locale-aware Unicode casing is required.
