<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\ActiveQuery\Criterion;

use Propel\Runtime\ActiveQuery\Criteria;
use function count;

/**
 * Criterion for PostgreSQL native-array containment and overlap operations.
 */
class NativeArrayCriterion extends AbstractCriterion
{
    /**
     * @param string $sb
     * @param array<mixed> $params
     *
     * @return void
     */
    protected function appendPsForUniqueClauseTo(string &$sb, array &$params): void
    {
        $field = $this->getQualifiedColumn();
        $params[] = ['table' => $this->realtable, 'column' => $this->column, 'value' => $this->value];
        $parameter = ':p' . count($params);

        if ($this->comparison === Criteria::ARRAY_NOT_OVERLAPS) {
            $sb .= '(' . $field . ' IS NULL OR NOT (' . $field . Criteria::ARRAY_OVERLAPS . $parameter . '))';

            return;
        }

        $sb .= $field . $this->comparison . $parameter;
    }
}
