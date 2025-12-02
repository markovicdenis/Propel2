<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\ActiveQuery\SqlBuilder;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Exception\LogicException;

use function count;
use function explode;
use function in_array;

class CountQuerySqlBuilder extends AbstractSqlQueryBuilder
{
    /**
     * @param \Propel\Runtime\ActiveQuery\Criteria $criteria
     *
     * @return \Propel\Runtime\ActiveQuery\SqlBuilder\PreparedStatementDto
     */
    public static function createCountSql(Criteria $criteria): PreparedStatementDto
    {
        $builder = new self($criteria);

        return $builder->build();
    }

    /**
     * Create a Sql COUNT statement.
     *
     * @throws \Propel\Runtime\Exception\LogicException
     *
     * @return \Propel\Runtime\ActiveQuery\SqlBuilder\PreparedStatementDto
     */
    public function build(): PreparedStatementDto
    {
        $needsComplexCount = $this->criteria->getGroupByColumns()
            || $this->criteria->getOffset()
            || $this->criteria->getLimit() >= 0
            || $this->criteria->getHaving()
            || in_array(Criteria::DISTINCT, $this->criteria->getSelectModifiers(), true)
            || $this->criteria->hasSelectQueries();

        if (!$needsComplexCount) {
            $this->criteria
                ->clearSelectColumns()
                ->addSelectColumn('COUNT(*)');

            return SelectQuerySqlBuilder::createSelectSql($this->criteria);
        }

        $this->pruneSelect();

        if ($this->criteria->needsSelectAliases()) {
            if ($this->criteria->getHaving()) {
                $errorMessage = 'Propel cannot create a COUNT query when using HAVING and duplicate column names in the SELECT part';

                throw new LogicException($errorMessage);
            }

            $this->adapter->turnSelectColumnsToAliases($this->criteria);
        }

        $this->addOneSelectColumnIfNoneExists();

        $preparedStatementDto = SelectQuerySqlBuilder::createSelectSql($this->criteria);
        $baseSelectSql = $preparedStatementDto->getSqlStatement();
        $params = $preparedStatementDto->getParameters();

        $countStatement = "SELECT COUNT(*) FROM ($baseSelectSql) propelmatch4cnt";

        return new PreparedStatementDto($countStatement, $params);
    }

    private function pruneSelect(): void
    {
        if (!$this->criteria instanceof ModelCriteria) {
            return;
        }
        if (!$this->criteria->isKeepQuery()) {
            return;
        }
        $this->criteria->setOffset(0);
        $this->criteria->setLimit(-1);
        $tables = [];
        $tables[] = $this->criteria->getPrimaryTableName();
        foreach ($this->criteria->getJoins() as $join) {
            $rightTable = $join->getRightTableName();
            if (!in_array($rightTable, $tables, true)) {
                $tables[] = $rightTable;
            }
            $leftTable = $join->getLeftTableName();
            if (!in_array($leftTable, $tables, true)) {
                $tables[] = $leftTable;
            }
        }

        // $where = $this->criteria->getMap();
        $select = $this->criteria->getSelectColumns();
        $groupBy = $this->criteria->getGroupByColumns();
        foreach ($select as $column) {
            // select tablename from column
            $parts = explode('.', $column);
            if (count($parts) === 2) {
                $table = $parts[0];
                if (in_array($table, $tables, true) && !in_array($column, $groupBy, true)) {
                    $this->criteria->removeSelectColumn($column);
                }
            }
        }
    }

    private function addOneSelectColumnIfNoneExists(): void
    {
        if (count($this->criteria->getSelectColumns()) === 0 && count($this->criteria->getAsColumns()) === 0) {
            $this->criteria->addAsColumn('constant_alias_for_count', '1');
        }
    }
}
