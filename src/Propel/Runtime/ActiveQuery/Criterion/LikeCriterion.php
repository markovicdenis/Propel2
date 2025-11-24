<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\ActiveQuery\Criterion;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Adapter\Pdo\PgsqlAdapter;
use Propel\Runtime\Adapter\SqlAdapterInterface;
use Propel\Runtime\Map\ColumnMap;

use function count;
use function in_array;

/**
 * Specialized Criterion used for LIKE expressions
 * e.g. table.column LIKE ? or table.column NOT LIKE ? (or ILIKE for Postgres)
 */
class LikeCriterion extends AbstractCriterion
{
    /**
     * @var bool
     */
    protected $ignoreStringCase = true;

    /**
     * Create a new instance.
     *
     * @param \Propel\Runtime\ActiveQuery\Criteria $outer The outer class (this is an "inner" class).
     * @param \Propel\Runtime\Map\ColumnMap|string $column ignored
     * @param mixed $value The condition to be added to the query string
     * @param string $comparison One of Criteria::LIKE and Criteria::NOT_LIKE
     */
    public function __construct(private Criteria $outer, $column, $value, string $comparison = Criteria::LIKE)
    {
        parent::__construct($outer, $column, $value, $comparison);
    }

    /**
     * Sets ignore case.
     *
     * @param bool $b True if case should be ignored.
     *
     * @return $this A modified Criterion object.
     */
    public function setIgnoreCase(bool $b)
    {
        $this->ignoreStringCase = $b;

        return $this;
    }

    /**
     * Is ignore case on or off?
     *
     * @return bool True if case is ignored.
     */
    public function isIgnoreCase(): bool
    {
        return $this->ignoreStringCase;
    }

    /**
     * Appends a Prepared Statement representation of the Criterion onto the buffer
     *
     * @param string $sb The string that will receive the Prepared Statement
     * @param array $params A list to which Prepared Statement parameters will be appended
     *
     * @return void
     */
    protected function appendPsForUniqueClauseTo(string &$sb, array &$params): void
    {
        $field = $this->getQualifiedColumn();
        $db = $this->getAdapter();
        // If selection is case insensitive use ILIKE for PostgreSQL or SQL
        // UPPER() function on column name for other databases.
        if ($this->ignoreStringCase) {
            if ($db instanceof PgsqlAdapter) {
                if ($this->comparison === Criteria::LIKE) {
                    $this->comparison = Criteria::ILIKE;
                } elseif ($this->comparison === Criteria::NOT_LIKE) {
                    $this->comparison = Criteria::NOT_ILIKE;
                }
            } else {
                if ($db instanceof SqlAdapterInterface) {
                    $field = $db->ignoreCase($field);
                }
            }
        }
        if ($db instanceof PgsqlAdapter) {
            $columnType = match (true) {
                $this->column instanceof ColumnMap => $this->column->getType(),
                $this->outer instanceof ModelCriteria => $this->outer->getTableMap()->getColumn($this->column)->getType(),
                default => null,
            };
            // check if column is numeric
            if (in_array($columnType, ['INTEGER', 'BIGINT', 'SMALLINT', 'TINYINT', 'DECIMAL', 'NUMERIC', 'FLOAT', 'DOUBLE'], true)) {
                $field = "$field::text";
            }
        } else {
            // check if comparison is ILIKE or NOT ILIKE and change to LIKE or NOT LIKE for non-Postgres DBs
            $this->comparison = match ($this->comparison) {
                Criteria::ILIKE => Criteria::LIKE,
                Criteria::NOT_ILIKE => Criteria::NOT_LIKE,
                default => $this->comparison,
            };
        }

        if (str_contains($this->value, '%')) {
            $params[] = ['column' => $this->column, 'value' => $this->value];
        } else {
            $params[] = ['table' => $this->realtable, 'column' => $this->column, 'value' => $this->value];
        }

        $sb .= "{$field}{$this->comparison}";

        // If selection is case insensitive use SQL UPPER() function
        // on criteria or, if Postgres we are using ILIKE, so not necessary.
        if ($this->ignoreStringCase && !($db instanceof PgsqlAdapter)) {
            $sb .= $db->ignoreCase(':p' . count($params));
        } else {
            $sb .= ':p' . count($params);
        }
    }
}
