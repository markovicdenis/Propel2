<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests;

use PHPUnit\Framework\MockObject\Stub\ReturnStub;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Propel\Generator\Platform\PlatformInterface;
use ReflectionClass;
use BadMethodCallException;

use function sprintf;

class TestCase extends PHPUnitTestCase
{
    /**
     * @param mixed $value
     *
     * @return \PHPUnit\Framework\MockObject\Stub\Stub
     */
    protected function returnValue($value)
    {
        return new ReturnStub($value);
    }

    /**
     * @param string $attributeName
     * @param string $className
     * @param string $message
     *
     * @return void
     */
    protected function assertClassHasAttribute($attributeName, $className, $message = '')
    {
        if ($message === '') {
            $message = sprintf('Failed asserting that class "%s" has attribute "%s".', $className, $attributeName);
        }

        $this->assertTrue((new ReflectionClass($className))->hasProperty($attributeName), $message);
    }

    /**
     * Compatibility shim for PHPUnit versions where getMockForTrait() was removed.
     *
     * @param string $traitName
     *
     * @return object
     */
    protected function getMockForTrait($traitName)
    {
        if ($traitName !== 'Propel\\Runtime\\Connection\\TransactionTrait') {
            throw new BadMethodCallException(sprintf('Unsupported trait mock requested for "%s".', $traitName));
        }

        $className = 'PropelTestTransactionTraitMock';

        if (!class_exists($className, false)) {
            eval('class ' . $className . ' { use \\Propel\\Runtime\\Connection\\TransactionTrait; public function beginTransaction(): bool { return true; } public function commit(): bool { return true; } public function rollBack(): bool { return true; } }');
        }

        return $this->getMockBuilder($className)
            ->onlyMethods(['beginTransaction', 'commit', 'rollBack'])
            ->getMock();
    }

    /**
     * @return string
     */
    protected function getDriver()
    {
        return 'sqlite';
    }

    /**
     * Makes the sql compatible with the current database.
     * Means: replaces ` etc.
     *
     * @param string $sql
     * @param string $source
     * @param string|null $target
     *
     * @return mixed
     */
    protected function getSql($sql, $source = 'mysql', $target = null)
    {
        if (!$target) {
            $target = $this->getDriver();
        }

        if ('sqlite' === $target && 'mysql' === $source) {
            return preg_replace('/`([^`]*)`/', '[$1]', $sql);
        }
        if ('pgsql' === $target && 'mysql' === $source) {
            return preg_replace('/`([^`]*)`/', '"$1"', $sql);
        }
        if ('mysql' !== $target && 'mysql' === $source) {
            return str_replace('`', '', $sql);
        }

        return $sql;
    }

    /**
     * Returns true if the current driver in the connection ($this->con) is $db.
     *
     * @param string $db
     *
     * @return bool
     */
    protected function isDb($db = 'mysql')
    {
        return $this->getDriver() == $db;
    }

    /**
     * @return bool
     */
    protected function runningOnPostgreSQL()
    {
        return $this->isDb('pgsql');
    }

    /**
     * @return bool
     */
    protected function runningOnMySQL()
    {
        return $this->isDb('mysql');
    }

    /**
     * @return bool
     */
    protected function runningOnSQLite()
    {
        return $this->isDb('sqlite');
    }

    /**
     * @return bool
     */
    protected function runningOnOracle()
    {
        return $this->isDb('oracle');
    }

    /**
     * @return bool
     */
    protected function runningOnMSSQL()
    {
        return $this->isDb('mssql');
    }

    /**
     * @return \Propel\Generator\Platform\PlatformInterface
     */
    protected function getPlatform(): PlatformInterface
    {
        $className = sprintf('\\Propel\\Generator\\Platform\\%sPlatform', ucfirst($this->getDriver()));

        return new $className();
    }

    /**
     * @param \Propel\Runtime\Connection\ConnectionInterface $con
     *
     * @return \Propel\Generator\Reverse\SchemaParserInterface
     */
    protected function getParser($con)
    {
        $className = sprintf('\\Propel\\Generator\\Reverse\\%sSchemaParser', ucfirst($this->getDriver()));

        $obj = new $className($con);

        return $obj;
    }
}
