<?php

namespace Propel\Runtime\Adapter\Traits;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Adapter\SqlAdapterInterface;

use function explode;
use function in_array;

trait StrictGroupByTrait
{
    private array $handledAggregateSelects = [];

    private function getAggregateSelectSql(
        string $columnName,
        Criteria $criteria,
        SqlAdapterInterface $adapter
    ): string {
        if (!$criteria instanceof ModelCriteria) {
            return $columnName;
        }
        $config = $criteria->getAggregationConfig($columnName);
        if ($config) {
            $this->handledAggregateSelects[] = $columnName;
            return $config->resolveClause($adapter, $columnName);
        }
        $column = $criteria->getTableMap()->findColumnByName($columnName);
        if ($column) {
            $type = $column->getType();
            $this->handledAggregateSelects[] = $columnName;
            return match ($type) {
                'BOOLEAN' => $adapter == 'pgsql' ? "MAX($columnName::int)" : "MAX($columnName)",
                'VARCHAR', 'CHAR', 'LONGVARCHAR' => "ANY_VALUE($columnName)",
                'CLOB', 'BINARY', 'VARBINARY', 'LONGVARBINARY', 'BLOB' => "ANY_VALUE($columnName)",
                'ENUM', 'SET' => "ANY_VALUE($columnName)",
                default => "MAX($columnName)",
            };
        }
        return $columnName;
    }

    protected function resolveAggregateSelectSql(
        string $columnName,
        Criteria $criteria,
        SqlAdapterInterface $adapter
    ): string {
        if (!$criteria->getGroupByColumns()) {
            return $columnName;
        }
        if (in_array($columnName, $criteria->getGroupByColumns(), true)) {
            return $columnName;
        }
        if (!$criteria instanceof ModelCriteria) {
            return $columnName;
        }
        return $this->getAggregateSelectSql($columnName, $criteria, $adapter);
    }

    public function resolveAggregateOrderBy(
        string $clause,
        Criteria $criteria,
        SqlAdapterInterface $adapter
    ): ?string {
        if (!$criteria instanceof ModelCriteria) {
            return null;
        }
        $parts = explode(' ', $clause, 2);
        $columnName = $parts[0];

        // $sql = $this->resolveAggregateSelectSql($colName, $criteria, $adapter);
        $config = $criteria->getAggregationConfig($columnName);
        $statement = match (true) {
            $config !== null => $config->resolveOrderByClause($adapter, $columnName),
            default => $clause,
        };
        return str_replace($columnName, $statement, $clause);
    }

    protected function didHandleAggregateSelect(?string $columnName): bool
    {
        return in_array($columnName ?? '', $this->handledAggregateSelects, true);
    }
}
