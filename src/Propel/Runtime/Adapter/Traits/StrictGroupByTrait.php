<?php

namespace Propel\Runtime\Adapter\Traits;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;

use function in_array;

trait StrictGroupByTrait
{
    private function createAggregateSelect(string $columnName, ModelCriteria $criteria): bool
    {
        $column = $criteria->getTableMap()->findColumnByName($columnName);
        if ($column) {
            $type = $column->getType();
            $aggregateSelect = match ($type) {
                'BOOLEAN' => "MAX($columnName::int)",
                default => "MAX($columnName)",
            };
            $criteria->removeSelectColumn($columnName);
            $criteria->withColumn($aggregateSelect, $this->quoteIdentifier($columnName));
            return true;
        }
        return false;
    }

    protected function fixGroupByColumns(Criteria $criteria): void
    {
        if (!$criteria instanceof ModelCriteria) {
            return;
        }
        $groupBy = $criteria->getGroupByColumns();
        if ($groupBy) {
            $selected = $this->getPlainSelectedColumns($criteria);
            $asSelects = $criteria->getAsColumns();
            foreach ($selected as $colName) {
                if (in_array($colName, $groupBy, true)) {
                    continue;
                }
                if (in_array($colName, $asSelects, true)) {
                    continue;
                }
                $this->createAggregateSelect($colName, $criteria);
            }
        }
    }
}
