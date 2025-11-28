<?php

namespace Propel\Runtime\ActiveQuery\Traits;

use Propel\Runtime\ActiveQuery\AggregationConfig;
use Propel\Runtime\ActiveQuery\ModelCriteria;

trait AggregateColumnsTrait
{
    /**
     * @var AggregationConfig[]
     */
    private array $aggregateSelects = [];

    private function normalizeColumnName(string $columnName): string
    {
        if ($this instanceof ModelCriteria) {
            $column = $this->getTableMap()->findColumnByName($columnName);
            if ($column) {
                return $column->getFullyQualifiedName();
            }
            return $columnName;
        }
        return $columnName;
    }

    public function addAggregationConfig(
        string $columnName,
        string $clause,
        ?string $alias = null,
    ): static {
        $name = $this->normalizeColumnName($columnName);
        $config = new AggregationConfig(
            columnName: $name,
            alias: $alias,
        );
        if (str_contains($clause, '(')) {
            $config->clause = $clause;
        } else {
            $config->function = $clause;
        }
        $this->aggregateSelects[$name] = $config;
        return $this;
    }

    /**
     * @return AggregationConfig[]
     */
    public function getAggregationConfigs(): array
    {
        return $this->aggregateSelects;
    }

    /**
     * @return AggregationConfig[]
     */
    public function getDefaultAggregationConfigs(): array
    {
        return [];
    }

    public function clearAggregateSelects(): static
    {
        $this->aggregateSelects = [];
        return $this;
    }

    public function removeAggregateSelect(string $columnName): static
    {
        unset($this->aggregateSelects[$columnName]);
        return $this;
    }

    public function getAggregationConfig(string $columnName): ?AggregationConfig
    {
        $config = $this->getAggregationConfigs()[$columnName] ?? $this->getDefaultAggregationConfigs()[$columnName] ?? null;
        if ($config) {
            $config->columnName = $columnName;
        }
        return $config;
    }
}
