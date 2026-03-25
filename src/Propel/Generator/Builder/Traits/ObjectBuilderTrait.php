<?php

namespace Propel\Generator\Builder\Traits;

use DateTime;
use Exception;
use Propel\Common\Util\SetColumnConverter;
use Propel\Generator\Exception\EngineException;
use Propel\Generator\Exception\InvalidArgumentException;
use Propel\Generator\Model\Column;

use function in_array;
use function sprintf;

trait ObjectBuilderTrait
{
    protected bool $castToNull = false;
    protected bool $shouldGenerateTryAccessors = false;


    protected function getDefaultValueForColumn(Column $column, bool $acceptNull = true): string
    {
        if ($column->isNotNull()) {
            return match($column->getType()) {
                'INTEGER', 'SMALLINT', 'TINYINT' => '0',
                'FLOAT', 'DOUBLE', 'REAL' => '0.0',
                'BOOLEAN' => 'false',
                'VARCHAR', 'CHAR', 'LONGVARCHAR', 'CLOB', 'TEXT', 'BIGINT' => "''",
                'ARRAY' => '[]',
                'DATE', 'DATETIME', 'TIME', 'TIMESTAMP' => 'null',
                default => throw new EngineException('Cannot get default value for ' . $column->getFullyQualifiedName() . ' ' . $column->getType()),
            };
        }
        return 'null';
    }

    protected function getUnsetValueForAccessor(Column $column): ?string
    {
        if ($column->hasDefaultValue()) {
            return $this->getDefaultValueForColumn($column);
        }

        if ($column->isPrimaryKey() && $column->isAutoIncrement()) {
            return null;
        }

        if ($column->isForeignKey() && !$column->isNotNull()) {
            return null;
        }

        return $column->isNotNull() ? $this->getDefaultValueForColumn($column) : null;
    }

    protected function isNullableInGeneratedObjectApi(Column $column): bool
    {
        if ($column->isPrimaryKey()) {
            return true;
        }

        return $this->getUnsetValueForAccessor($column) === null;
    }

    /**
    * Returns the type-casted and stringified default value for the specified
    * Column. This only works for scalar default values currently.
    *
    * @param \Propel\Generator\Model\Column $column
    *
    * @throws \Propel\Generator\Exception\EngineException
    *
    * @return string
    */
    protected function getDefaultValueString(Column $column, bool $acceptNull = true): string
    {
        $defaultValue = var_export(null, true);
        $val = $column->getPhpDefaultValue();
        if ($val === null && $acceptNull) {
            if ($defaultValue === 'NULL') {
                $defaultValue = 'null';
            }
            return $defaultValue;
        }

        if ($column->isTemporalType()) {
            $fmt = $this->getTemporalFormatter($column);
            try {
                if (
                    !($this->getPlatform() instanceof MysqlPlatform &&
                    ($val === '0000-00-00 00:00:00' || $val === '0000-00-00'))
                ) {
                    // while technically this is not a default value of NULL,
                    // this seems to be closest in meaning.
                    $defDt = new DateTime($val);
                    $defaultValue = var_export($defDt->format((string)$fmt), true);
                }
            } catch (Exception $exception) {
                // prevent endless loop when timezone is undefined
                date_default_timezone_set('America/Los_Angeles');

                throw new EngineException(sprintf('Unable to parse default temporal value "%s" for column "%s"', $column->getDefaultValueString(), $column->getFullyQualifiedName()), 0, $exception);
            }
        } elseif ($column->isEnumType()) {
            $valueSet = $column->getValueSet();
            if (!in_array($val, $valueSet)) {
                throw new EngineException(sprintf('Default Value "%s" is not among the enumerated values', $val));
            }
            $defaultValue = (string)array_search($val, $valueSet);
        } elseif ($column->isSetType()) {
            $defaultValue = SetColumnConverter::convertToInt($val, $column->getValueSet());
        } elseif ($column->isPhpPrimitiveType()) {
            settype($val, $column->getPhpType());
            $defaultValue = var_export($val, true);
        } elseif ($column->isPhpObjectType()) {
            $defaultValue = 'new ' . $column->getPhpType() . '(' . var_export($val, true) . ')';
        } elseif ($column->isPhpArrayType()) {
            $defaultValue = var_export($val, true);
        } else {
            throw new EngineException('Cannot get default value string for ' . $column->getFullyQualifiedName());
        }

        if ($defaultValue === 'NULL') {
            $defaultValue = 'null';
        }

        return $defaultValue;
    }

    protected function getAssertedCurrentChildObjectSnippet(string $name = '$this'): string
    {
        $className = $this->getCurrentChildObjectClassName();

        return "assert($name instanceof {$className});";
    }

    protected function getPrimaryKeyUnsetValue(Column $column): string
    {
        if (($column->isAutoIncrement() || $column->isForeignKey()) && !$column->hasDefaultValue()) {
            return 'null';
        }

        return $this->getDefaultValueForColumn($column);
    }

    protected function shouldGenerateTryDefaultAccessor(Column $column): bool
    {
        if (!$this->shouldGenerateTryAccessors) {
            return false;
        }
        return $column->isPrimaryKey() || ($column->isForeignKey() && $column->isNotNull());
    }

    protected function getNullGuardExceptionForAccessor(Column $column): ?string
    {
        $cfc = $column->getPhpName();

        if ($column->isPrimaryKey()) {
            return "Cannot return a null primary key from get$cfc(). Use tryGet$cfc() if you need the nullable value.";
        }

        if ($column->isForeignKey() && $column->isNotNull()) {
            return "Cannot return a null required relation column from get$cfc(). Use tryGet$cfc() if you need the nullable value.";
        }

        return null;
    }
}
