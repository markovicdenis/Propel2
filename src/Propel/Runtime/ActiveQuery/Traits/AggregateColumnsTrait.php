<?php

namespace Propel\Runtime\ActiveQuery\Traits;

trait AggregateColumnsTrait
{
    private array $aggregateSelects = [];


    public function addAggregateSelect(string $columnName, string $clause): void
    {
        $this->aggregateSelects[$columnName] = $clause;
    }

    public function getAggregateSelects(): array
    {
        return $this->aggregateSelects;
    }

    public function clearAggregateSelects(): void
    {
        $this->aggregateSelects = [];
    }

    public function removeAggregateSelect(string $columnName): void
    {
        unset($this->aggregateSelects[$columnName]);
    }

    public function getAggregateSelect(string $columnName): ?string
    {
        return $this->aggregateSelects[$columnName] ?? null;
    }
}
