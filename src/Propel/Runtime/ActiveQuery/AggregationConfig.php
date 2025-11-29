<?php

namespace Propel\Runtime\ActiveQuery;

use Propel\Runtime\Adapter\Pdo\PdoAdapter;
use Propel\Runtime\Adapter\SqlAdapterInterface;

use function str_contains;

class AggregationConfig
{
    public function __construct(
        public string $columnName,
        public ?string $alias = null,
        public ?string $function = null,
        public ?string $clause = null,
    ) {
    }

    public static function create(?string $function = null, ?string $clause = null, ?string $alias = null): self
    {
        return new self('', $alias, $function, $clause);
    }

    public function resolveClause(SqlAdapterInterface $adapter, ?string $columnName = null): string
    {
        $columnName ??= $this->columnName;
        // if ($this->clause) {
        //     return $this->clause;
        // }
        // if ($this->function) {
        //     return "{$this->function}($columnName)";
        // }
        // return $columnName;
        $statement = match (true) {
            $this->clause !== null => $this->clause,
            $this->function !== null => "{$this->function}($columnName)",
            default => $columnName,
        };

        if ($this->alias !== null && !str_contains($statement, ' AS ')) {
            $statement .= ' AS ' . $adapter->quoteIdentifier($this->alias);
        }

        return $statement;
    }

    public function resolveOrderByClause(SqlAdapterInterface $adapter, ?string $columnName = null): ?string
    {
        $columnName ??= $this->columnName;
        if ($this->alias) {
            return $adapter->quoteIdentifierSafe($this->alias);
        }
        $statement = match (true) {
            $this->clause !== null => $this->clause,
            $this->function !== null => "{$this->function}($columnName)",
            default => null,
        };

        // check if incompatible with ORDER BY, eg COUNT()

        return $statement;
    }
}
