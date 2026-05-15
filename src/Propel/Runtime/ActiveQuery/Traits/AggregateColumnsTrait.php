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
        $config = $this->buildAggregationConfig($name, $clause, $alias);
        $this->aggregateSelects[$name] = $config;
        return $this;
    }

    public function withAggregation(
        string $columnName,
        ?string $aggregation = null,
        ?string $alias = null,
    ): static {
        if ($aggregation !== null) {
            $name = $this->normalizeColumnName($columnName);
            if ($alias !== null && isset($this->aggregateSelects[$name])) {
                return $this->addAliasedAggregationColumn($name, $aggregation, $alias);
            }

            return $this->addAggregationConfig($columnName, $aggregation, $alias);
        }

        $name = $this->normalizeColumnName($columnName);
        $config = $this->getAggregationConfig($name);
        if ($config === null) {
            return $this;
        }

        if ($alias !== null) {
            $config->alias = $alias;
        }

        $this->aggregateSelects[$config->columnName] = $config;

        return $this;
    }

    private function buildAggregationConfig(
        string $columnName,
        string $clause,
        ?string $alias = null,
    ): AggregationConfig {
        $config = new AggregationConfig(
            columnName: $columnName,
            alias: $alias,
        );
        if (str_contains($clause, '(')) {
            $config->clause = $clause;
        } else {
            $config->function = $clause;
        }

        return $config;
    }

    private function addAliasedAggregationColumn(
        string $columnName,
        string $aggregation,
        string $alias,
    ): static {
        $config = $this->buildAggregationConfig($columnName, $aggregation, $alias);
        $statement = $this->resolveAggregationSelectExpression($config);

        if ($this instanceof ModelCriteria && !$this->hasSelectClause() && !$this->getPrimaryCriteria()) {
            $this->addSelfSelectColumns();
        }

        $this->addAsColumn($alias, $statement);

        return $this;
    }

    private function resolveAggregationSelectExpression(AggregationConfig $config): string
    {
        $statement = match (true) {
            $config->clause !== null => $config->clause,
            $config->function !== null => "{$config->function}({$config->columnName})",
            default => $config->columnName,
        };

        $statement = trim($statement);

        if ($config->alias !== null) {
            $statement = preg_replace('/\s+AS\s+(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|[\w.]+)\s*$/i', '', $statement) ?? $statement;
        }

        if ($this instanceof ModelCriteria) {
            $this->replaceNames($statement);
        }

        return $statement;
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
