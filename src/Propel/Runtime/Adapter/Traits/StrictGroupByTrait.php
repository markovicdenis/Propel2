<?php

namespace Propel\Runtime\Adapter\Traits;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Adapter\Pdo\PdoAdapter;
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
    ): string {
        $parts = explode(' ', $clause, 2);
        $colName = $parts[0];

        $sql = $this->resolveAggregateSelectSql($colName, $criteria, $adapter);
        return str_replace($colName, $sql, $clause);
    }

    protected function didHandleAggregateSelect(?string $columnName): bool
    {
        return in_array($columnName ?? '', $this->handledAggregateSelects, true);
    }
}
