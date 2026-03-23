<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\ActiveRecord;

use Propel\Runtime\ActiveRecord\ActiveRecordCommonTrait;
use Propel\Runtime\ActiveRecord\ActiveRecordInterface;

class TestableActiveRecord implements ActiveRecordInterface
{
    use ActiveRecordCommonTrait;

    protected $modifiedColumns = [];

    protected $new = true;

    protected $deleted = false;

    public $virtualColumns = [];

    public $primaryKey;

    public function isPrimaryKeyNull(): bool
    {
        return $this->primaryKey === null;
    }

    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    public function toArray(string $keyType = 'phpName', bool $includeLazyLoadColumns = true, array $alreadyDumpedObjects = []): array
    {
        return ['foo' => 'bar'];
    }

    public function clearAllReferences(): void
    {
    }
}
