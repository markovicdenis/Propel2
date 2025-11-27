<?php

namespace Propel\Runtime\ActiveQuery\Traits;

use Propel\Runtime\ActiveQuery\ModelCriteria;

trait AggregateColumnsTrait
{
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

    public function addAggregateSelect(string $columnName, string $clause): self
    {
        $this->aggregateSelects[$this->normalizeColumnName($columnName)] = $clause;
        return $this;
    }

    public function getAggregateSelects(): array
    {
        return $this->aggregateSelects;
    }

    public function clearAggregateSelects(): self
    {
        $this->aggregateSelects = [];
        return $this;
    }

    public function removeAggregateSelect(string $columnName): self
    {
        unset($this->aggregateSelects[$columnName]);
        return $this;
    }

    public function getAggregateSelect(string $columnName): ?string
    {
        return $this->aggregateSelects[$columnName] ?? null;
    }
}
