<?php

namespace Propel\Runtime\ActiveQuery\Traits;

trait AggregateColumnsTrait
{
    private array $aggregateSelects = [];


    public function addAggregateSelect(string $columnName, string $clause): self
    {
        $this->aggregateSelects[$columnName] = $clause;
        return $this;
    }

    public function getAggregateSelects(): array
    {
        return $this->aggregateSelects;
    }

    public function clearAggregateSelects(): void
    {
        $this->aggregateSelects = [];
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
