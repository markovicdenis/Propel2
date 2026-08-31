<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\Adapter;

/**
 * Placement of NULL values in an ORDER BY clause.
 *
 * SQL leaves this implementation-defined, so the same query orders rows
 * differently depending on the database system: MySQL, SQLite and SQL Server
 * sort NULLs as the smallest value, PostgreSQL and Oracle as the largest.
 * Adapters that understand an explicit NULLS FIRST/NULLS LAST clause can be
 * told to emit one, which makes the ordering portable.
 *
 * @see \Propel\Runtime\Adapter\Pdo\PdoAdapter::setDefaultNullOrdering()
 */
enum NullOrdering: string
{
    /**
     * Leave the placement to the database system, no clause is emitted.
     */
    case Native = 'native';

    /**
     * NULLs sort before all other values (ASC: NULLS FIRST, DESC: NULLS LAST), like MySQL.
     */
    case NullsSmallest = 'nulls_smallest';

    /**
     * NULLs sort after all other values (ASC: NULLS LAST, DESC: NULLS FIRST), like PostgreSQL.
     */
    case NullsLargest = 'nulls_largest';
}
