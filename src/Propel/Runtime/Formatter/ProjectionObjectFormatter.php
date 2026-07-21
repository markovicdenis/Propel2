<?php

/**
 * MIT License. This file is part of the Propel package.
 * See the LICENSE file distributed with this source code.
 */

namespace Propel\Runtime\Formatter;

use Propel\Runtime\ActiveRecord\ActiveRecordInterface;
use Propel\Runtime\Exception\LogicException;

/**
 * Formats selected root-table columns as partial model objects.
 *
 * It deliberately bypasses TableMap::populateObject(), preventing projected
 * data from entering or overwriting Propel's normal instance pool. Joins and
 * virtual columns need a multi-model projection plan and are rejected in v1.
 */
class ProjectionObjectFormatter extends ObjectFormatter
{
    /** @var list<string> */
    private array $projectionColumns = [];

    /** @param list<string> $projectionColumns PHP column names in SELECT order */
    public function setProjectionColumns(array $projectionColumns): self
    {
        $this->projectionColumns = $projectionColumns;

        return $this;
    }

    /** @return list<string> */
    public function getProjectionColumns(): array
    {
        return $this->projectionColumns;
    }

    public function getAllObjectsFromRow(array $row): ActiveRecordInterface
    {
        if ($this->getWith() || $this->getAsColumns()) {
            throw new LogicException('Projection queries do not support with(), joinWith(), or withColumn().');
        }

        $class = $this->getClass();
        if ($class === null) {
            throw new LogicException('Projection formatter requires a model class.');
        }

        /** @var ActiveRecordInterface $object */
        $object = new $class();
        if (!method_exists($object, 'hydrateProjection')) {
            throw new LogicException(sprintf('%s must use ActiveRecordHydrationTrait to support projections.', $class));
        }

        /** @phpstan-ignore-next-line hydrateProjection is provided by ActiveRecordHydrationTrait. */
        $object->hydrateProjection($row, $this->projectionColumns, $this->getDataFetcher()->getIndexType());

        return $object;
    }
}
