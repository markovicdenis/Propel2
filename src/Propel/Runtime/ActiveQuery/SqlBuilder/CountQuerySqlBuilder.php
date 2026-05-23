<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\ActiveQuery\SqlBuilder;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Adapter\Pdo\PgsqlAdapter;
use Propel\Runtime\Exception\LogicException;
use Propel\Runtime\Map\ColumnMap;

use function count;
use function in_array;
use function sprintf;

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
        if ($this->canUsePgsqlDistinctPrimaryKeyCount()) {
            return $this->buildPgsqlDistinctPrimaryKeyCount();
        }

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

    private function canUsePgsqlDistinctPrimaryKeyCount(): bool
    {
        if (!$this->adapter instanceof PgsqlAdapter) {
            return false;
        }
        if (!$this->criteria instanceof ModelCriteria) {
            return false;
        }
        if (!$this->criteria->hasSelectModifier(Criteria::DISTINCT)) {
            return false;
        }
        if ($this->criteria->getGroupByColumns() || $this->criteria->getHaving() || $this->criteria->hasSelectQueries()) {
            return false;
        }
        if ($this->criteria->getOffset() || $this->criteria->getLimit() >= 0) {
            return false;
        }
        if (!$this->criteria->isSelfColumnsSelected() || count($this->criteria->getAsColumns()) > 0) {
            return false;
        }

        $primaryKeyColumn = $this->getSinglePrimaryKeyColumn();
        if ($primaryKeyColumn === null) {
            return false;
        }

        return $this->hasOnlyPrimaryModelSelectColumns($primaryKeyColumn);
    }

    private function buildPgsqlDistinctPrimaryKeyCount(): PreparedStatementDto
    {
        $primaryKeyColumn = $this->getSinglePrimaryKeyColumn();
        if ($primaryKeyColumn === null) {
            throw new LogicException('PostgreSQL distinct count fast path requires a single primary key column.');
        }

        $this->criteria
            ->removeSelectModifier(Criteria::DISTINCT)
            ->clearSelectColumns()
            ->addSelectColumn(sprintf('COUNT(DISTINCT %s)', $this->getQualifiedPrimaryKeyColumn($primaryKeyColumn)));

        return SelectQuerySqlBuilder::createSelectSql($this->criteria);
    }

    private function getSinglePrimaryKeyColumn(): ?ColumnMap
    {
        if (!$this->criteria instanceof ModelCriteria) {
            return null;
        }

        $tableMap = $this->criteria->getTableMap();
        if ($tableMap === null) {
            return null;
        }

        $primaryKeys = $tableMap->getPrimaryKeys();
        if (count($primaryKeys) !== 1) {
            return null;
        }

        return array_values($primaryKeys)[0];
    }

    private function hasOnlyPrimaryModelSelectColumns(ColumnMap $primaryKeyColumn): bool
    {
        if (!$this->criteria instanceof ModelCriteria) {
            return false;
        }

        $allowedPrefixes = [$primaryKeyColumn->getTable()->getName()];
        $modelAlias = $this->criteria->getModelAlias();
        if ($modelAlias !== null) {
            $allowedPrefixes[] = $modelAlias;
        }

        foreach ($this->criteria->getSelectColumns() as $column) {
            if (strpos($column, '(') !== false) {
                return false;
            }

            $parts = explode('.', $column);
            if (count($parts) !== 2 || !in_array($parts[0], $allowedPrefixes, true)) {
                return false;
            }
        }

        return true;
    }

    private function getQualifiedPrimaryKeyColumn(ColumnMap $primaryKeyColumn): string
    {
        if (!$this->criteria instanceof ModelCriteria) {
            return $primaryKeyColumn->getFullyQualifiedName();
        }

        $modelAlias = $this->criteria->getModelAlias();
        if ($modelAlias !== null) {
            return $modelAlias . '.' . $primaryKeyColumn->getName();
        }

        return $primaryKeyColumn->getFullyQualifiedName();
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
