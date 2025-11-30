<?php

namespace Propel\Runtime\Adapter\Traits;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Adapter\Pdo\PgsqlAdapter;
use Propel\Runtime\Adapter\SqlAdapterInterface;
use Propel\Runtime\Map\ColumnMap;

use function explode;
use function in_array;

trait StrictGroupByTrait
{
    private array $handledAggregateSelects = [];

    private function convertToAggregateClause(
        string $columnName,
        SqlAdapterInterface $adapter,
        Criteria $criteria,
    ): string {
        $alias = $criteria->getAliases()[$columnName] ?? null;
        if ($alias !== null) {
            $columnName = $alias;
        }
        $columnMap = match(true) {
            $criteria instanceof ModelCriteria => $criteria->getTableMap()->findColumnByName($columnName),
            default => null,
        };
        $type = $columnMap ? $columnMap->getType() : 'VARCHAR';
        $isPostgres = $adapter instanceof PgsqlAdapter;
        return match ($type) {
            'BOOLEAN' => $isPostgres ? "MAX($columnName::int)" : "MAX($columnName)",
            'VARCHAR', 'CHAR', 'LONGVARCHAR' => "ANY_VALUE($columnName)",
            'CLOB', 'BINARY', 'VARBINARY', 'LONGVARBINARY', 'BLOB' => "ANY_VALUE($columnName)",
            'ENUM', 'SET' => "ANY_VALUE($columnName)",
            default => "MAX($columnName)",
        };
    }

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
        // $column = $criteria->getTableMap()->findColumnByName($columnName);
        // if ($column) {
        //     $this->handledAggregateSelects[] = $columnName;
        //     return $this->convertToAggregateClause($column->getType(), $columnName, $adapter);
        // }
        $resolvedType = $this->convertToAggregateClause(
            $columnName,
            $adapter,
            $criteria,
        );
        if ($resolvedType) {
            $this->handledAggregateSelects[] = $columnName;
            return $resolvedType;
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
        if (!$criteria->getGroupByColumns()) {
            return null;
        }
        if (!$criteria instanceof ModelCriteria) {
            return null;
        }
        $parts = explode(' ', $clause, 2);
        $columnName = $parts[0];

        $config = $criteria->getAggregationConfig($columnName);
        $statement = match (true) {
            $config !== null => $config->resolveOrderByClause($adapter, $columnName),
            default => $this->convertToAggregateClause(
                $columnName,
                $adapter,
                $criteria,
            ),
        };
        return str_replace($columnName, $statement, $clause);
    }

    protected function didHandleAggregateSelect(?string $columnName): bool
    {
        return in_array($columnName ?? '', $this->handledAggregateSelects, true);
    }
}
