<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Model\Diff;

use Propel\Generator\Model\Index;
use Propel\Generator\Util\SqlPredicateNormalizer;

use function count;

/**
 * Service class for comparing Index objects
 * Heavily inspired by Doctrine2's Migrations
 * (see http://github.com/doctrine/dbal/tree/master/lib/Doctrine/DBAL/Schema/)
 */
class IndexComparator
{
    /**
     * Computes the difference between two index objects.
     */
    public static function computeDiff(Index $fromIndex, Index $toIndex, bool $caseInsensitive = false): bool
    {
        if ($fromIndex->getUsing() !== $toIndex->getUsing()) {
            return true;
        }

        // Check for removed index columns in $toIndex
        $fromIndexColumns = $fromIndex->getColumns();
        $max = count($fromIndexColumns);
        for ($i = 0; $i < $max; $i++) {
            $indexColumn = $fromIndexColumns[$i];
            if (!$toIndex->hasColumnAtPosition($i, $indexColumn, $fromIndex->getColumnSize($indexColumn), $caseInsensitive)) {
                return true;
            }
            if ($fromIndex->getColumnOperatorClass($indexColumn, $caseInsensitive) !== $toIndex->getColumnOperatorClass($indexColumn, $caseInsensitive)) {
                return true;
            }
        }

        // Check for new index columns in $toIndex
        $toIndexColumns = $toIndex->getColumns();
        $max = count($toIndexColumns);
        for ($i = 0; $i < $max; $i++) {
            $indexColumn = $toIndexColumns[$i];
            if (!$fromIndex->hasColumnAtPosition($i, $indexColumn, $toIndex->getColumnSize($indexColumn), $caseInsensitive)) {
                return true;
            }
            if ($toIndex->getColumnOperatorClass($indexColumn, $caseInsensitive) !== $fromIndex->getColumnOperatorClass($indexColumn, $caseInsensitive)) {
                return true;
            }
        }

        if ($fromIndex->isUnique() !== $toIndex->isUnique()) {
            return true;
        }

        return !SqlPredicateNormalizer::areEqual($fromIndex->getWhere(), $toIndex->getWhere());
    }
}
