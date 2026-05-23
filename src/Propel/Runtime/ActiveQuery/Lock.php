<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Propel\Runtime\ActiveQuery;

use Propel\Runtime\Exception\InvalidArgumentException;

/**
 * Class represents a query lock
 *
 * @author Tomasz Wójcik <tomasz.prgtw.wojcik@gmail.com>
 */
class Lock
{
    /**
     * @var string
     */
    public const SHARED = 'SHARED';

    /**
     * @var string
     */
    public const EXCLUSIVE = 'EXCLUSIVE';

    /**
     * Lock type, either shared or exclusive
     *
     * @see self::SHARED
     * @see self::EXCLUSIVE
     *
     * @var string
     */
    protected $type;

    /**
     * Table names to lock
     *
     * @var array<string>
     */
    protected $tableNames;

    /**
     * Whether to issue a non-blocking lock
     *
     * @var bool
     */
    protected $noWait;

    /**
     * Whether to skip already locked rows
     *
     * @var bool
     */
    protected $skipLocked;

    /**
     * @param string $type Lock type
     * @param array<string> $tableNames Table names to lock
     * @param bool $noWait Whether to issue a non-blocking lock
     * @param bool $skipLocked Whether to skip already locked rows
     */
    public function __construct(string $type, array $tableNames = [], bool $noWait = false, bool $skipLocked = false)
    {
        if ($noWait && $skipLocked) {
            throw new InvalidArgumentException('Lock cannot use NOWAIT and SKIP LOCKED at the same time.');
        }

        $this->type = $type;
        $this->tableNames = $tableNames;
        $this->noWait = $noWait;
        $this->skipLocked = $skipLocked;
    }

    /**
     * Lock type
     *
     * @see self::SHARED
     * @see self::EXCLUSIVE
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Returns table names to lock
     *
     * @return array<string>
     */
    public function getTableNames(): array
    {
        return $this->tableNames;
    }

    /**
     * Whether to issue a non-blocking lock
     *
     * @return bool
     */
    public function isNoWait(): bool
    {
        return $this->noWait;
    }

    /**
     * Whether to skip already locked rows
     *
     * @return bool
     */
    public function isSkipLocked(): bool
    {
        return $this->skipLocked;
    }

    /**
     * Checks whether a lock equals another lock object
     *
     * @param \Propel\Runtime\ActiveQuery\Lock|mixed $lock
     *
     * @return bool
     */
    public function equals($lock): bool
    {
        if (!($lock instanceof self)) {
            return false;
        }

        $aTableNames = $this->getTableNames();
        $bTableNames = $lock->getTableNames();

        return $this->getType() === $lock->getType()
            && $this->isNoWait() === $lock->isNoWait()
            && $this->isSkipLocked() === $lock->isSkipLocked()
            && $aTableNames === array_intersect($aTableNames, $bTableNames)
            && $bTableNames === array_intersect($bTableNames, $aTableNames);
    }
}
