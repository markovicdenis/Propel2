<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om;

use DateTime;
use Exception;
use Propel\Common\Util\SetColumnConverter;
use Propel\Generator\Builder\Traits\ObjectBuilderTrait;
use Propel\Generator\Exception\EngineException;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\CrossForeignKeys;
use Propel\Generator\Model\ForeignKey;
use Propel\Generator\Model\IdMethod;
use Propel\Generator\Model\PropelTypes;
use Propel\Generator\Model\Table;
use Propel\Generator\Platform\MssqlPlatform;
use Propel\Generator\Platform\MysqlPlatform;
use Propel\Generator\Platform\OraclePlatform;
use Propel\Generator\Platform\PlatformInterface;
use Propel\Generator\Platform\SqlsrvPlatform;
use Propel\Runtime\Exception\PropelException;

use function count;
use function in_array;
use function sprintf;

/**
 * Generates a base Object class for user object model (OM).
 *
 * This class produces the base object class (e.g. BaseMyTable) which contains
 * all the custom-built accessor and setter methods.
 *
 * @author Hans Lellelid <hans@xmpl.org>
 */
class ObjectBuilder extends AbstractObjectBuilder
{
    use ObjectBuilderTrait;

    /**
     * Returns the package for the base object classes.
     *
     * @return string
     */
    public function getPackage(): string
    {
        return parent::getPackage() . '.Base';
    }

    /**
     * Returns the namespace for the base class.
     *
     * @see \Propel\Generator\Builder\Om\AbstractOMBuilder::getNamespace()
     *
     * @return string|null
     */
    public function getNamespace(): ?string
    {
        $namespace = parent::getNamespace();
        if ($namespace) {
            return $namespace . '\\Base';
        }

        return 'Base';
    }

    /**
     * Returns default key type.
     *
     * If not presented in configuration default will be 'TYPE_PHPNAME'
     *
     * @return string
     */
    public function getDefaultKeyType(): string
    {
        $defaultKeyType = $this->getBuildProperty('generator.objectModel.defaultKeyType') ?: 'phpName';

        return 'TYPE_' . strtoupper($defaultKeyType);
    }

    /**
     * Returns the name of the current class being built.
     *
     * @return string
     */
    public function getUnprefixedClassName(): string
    {
        return $this->getStubObjectBuilder()->getUnprefixedClassName();
    }

    /**
     * Validates the current table to make sure that it won't result in
     * generated code that will not parse.
     *
     * This method may emit warnings for code which may cause problems
     * and will throw exceptions for errors that will definitely cause
     * problems.
     *
     * @throws EngineException
     *
     * @return void
     */
    protected function validateModel(): void
    {
        parent::validateModel();

        $table = $this->getTable();

        // Check to see whether any generated foreign key names
        // will conflict with column names.

        $colPhpNames = [];
        $fkPhpNames = [];

        foreach ($table->getColumns() as $col) {
            $colPhpNames[] = $col->getPhpName();
        }

        foreach ($table->getForeignKeys() as $fk) {
            $fkPhpNames[] = $this->getFKPhpNameAffix($fk, false);
        }

        $intersect = array_intersect($colPhpNames, $fkPhpNames);
        if ($intersect) {
            throw new EngineException('One or more of your column names for [' . $table->getName() . '] table conflict with foreign key names (' . implode(', ', $intersect) . ')');
        }

        // Check foreign keys to see if there are any foreign keys that
        // are also matched with an inversed referencing foreign key
        // (this is currently unsupported behavior)
        // see: http://propel.phpdb.org/trac/ticket/549

        foreach ($table->getForeignKeys() as $fk) {
            if ($fk->isMatchedByInverseFK()) {
                throw new EngineException(sprintf('The 1:1 relationship expressed by foreign key %s is defined in both directions; Propel does not currently support this (if you must have both foreign key constraints, consider adding this constraint with a custom SQL file.)', $fk->getName()));
            }
        }
    }

    /**
     * Returns the appropriate formatter (from platform) for a date/time column.
     *
     * @param Column $column
     *
     * @return string|null
     */
    protected function getTemporalFormatter(Column $column): ?string
    {
        return match($column->getType()) {
            PropelTypes::DATE => $this->getPlatformOrFail()->getDateFormatter(),
            PropelTypes::TIME => $this->getPlatformOrFail()->getTimeFormatter(),
            PropelTypes::TIMESTAMP, PropelTypes::DATETIME => $this->getPlatformOrFail()->getTimestampFormatter(),
            default => null,
        };
    }

    protected function getRelationObjectClassName(ForeignKey $fk): string
    {
        $interface = $fk->getInterface();
        if ($interface) {
            return $this->declareClass($interface);
        }

        return $this->getRelationGetterClassName($fk);
    }

    protected function getRelationGetterClassName(ForeignKey $fk): string
    {
        $interface = $fk->getInterface();
        if ($interface) {
            return $this->declareClass($interface);
        }

        return $this->getClassNameFromBuilder($this->getNewStubObjectBuilder($fk->getForeignTable()));
    }

    protected function getRefRelationGetterClassName(ForeignKey $fk): string
    {
        return $this->getClassNameFromBuilder($this->getNewStubObjectBuilder($fk->getTable()));
    }

    protected function getCurrentChildObjectClassName(): string
    {
        return $this->getClassNameFromTable($this->getTable());
    }

    /**
     * Return the parent class name, or null.
     *
     * @return string|null
     */
    protected function getParentClass(): ?string
    {
        $parentClass = $this->getBehaviorContent('parentClass');
        if ($parentClass !== null) {
            return $parentClass;
        }

        return ClassTools::classname($this->getBaseClass());
    }

    /**
     * Adds class phpdoc comment and opening of class.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addClassOpen(string &$script): void
    {
        $table = $this->getTable();
        $tableName = $table->getName();
        $tableDesc = $table->getDescription();

        $parentClass = $this->getParentClass();
        if ($parentClass !== null) {
            $parentClass = ' extends ' . $parentClass;
        }

        if ($this->getBuildProperty('generator.objectModel.addClassLevelComment')) {
            $script .= "
/**
 * Base class that represents a row from the '$tableName' table.
 *
 * $tableDesc
 *";
            if ($this->getBuildProperty('generator.objectModel.addTimeStamp')) {
                $now = $this->getGeneratedAtTimestamp();
                $script .= "
 * This class was autogenerated by Propel " . $this->getBuildProperty('general.version') . " on:
 *
 * $now
 *";
            }
            $script .= "
 * @package    propel.generator." . $this->getPackage() . "
 */";
        }

        $script .= "
abstract class " . $this->getUnqualifiedClassName() . $parentClass . ' implements ActiveRecordInterface ';

        $interface = $this->getInterface();
        if ($interface) {
            $script .= ', Child' . ClassTools::classname($interface);
            if ($interface !== ClassTools::classname($interface)) {
                $this->declareClass($interface);
            } else {
                $this->declareClassFromBuilder($this->getInterfaceBuilder());
            }
        }

        $script .= "
{";
    }

    /**
     * Specifies the methods that are added as part of the basic OM class.
     * This can be overridden by subclasses that wish to add more methods.
     *
     * @see ObjectBuilder::addClassBody()
     *
     * @param string $script
     *
     * @return void
     */
    protected function addClassBody(string &$script): void
    {
        $this->declareClassFromBuilder($this->getStubObjectBuilder());
        $this->declareClassFromBuilder($this->getStubQueryBuilder());
        $this->declareClassFromBuilder($this->getTableMapBuilder());

        $this->declareClasses(
            '\Exception',
            '\PDO',
            '\Propel\Runtime\Exception\PropelException',
            '\Propel\Runtime\Connection\ConnectionInterface',
            '\Propel\Runtime\Collection\Collection',
            '\Propel\Runtime\Collection\ObjectCollection',
            '\Propel\Runtime\Collection\ObjectCombinationCollection',
            '\Propel\Runtime\Exception\BadMethodCallException',
            '\Propel\Runtime\Exception\PropelException',
            '\Propel\Runtime\ActiveQuery\Criteria',
            '\Propel\Runtime\ActiveQuery\ModelCriteria',
            '\Propel\Runtime\ActiveRecord\ActiveRecordInterface',
            '\Propel\Runtime\Parser\AbstractParser',
            '\Propel\Runtime\Propel',
            '\Propel\Runtime\Map\TableMap',
        );

        $baseClass = $this->getBaseClass();
        if ($baseClass && strrpos($baseClass, '\\') !== false) {
            $this->declareClasses($baseClass);
        }

        $table = $this->getTable();

        $additionalModelClasses = $table->getAdditionalModelClassImports();
        if ($additionalModelClasses) {
            $this->declareClasses(...$additionalModelClasses);
        }

        $this->addCommonTraitUses($script);

        if (!$table->isAlias()) {
            $this->addConstants($script);
            $this->addAttributes($script);
        }

        if ($table->hasCrossForeignKeys()) {
            foreach ($table->getCrossFks() as $crossFKs) {
                $this->addCrossScheduledForDeletionAttribute($script, $crossFKs);
            }
        }

        foreach ($table->getReferrersForCodeGeneration() as $refFK) {
            if (!$refFK->isLocalPrimaryKey()) {
                $this->addRefFkScheduledForDeletionAttribute($script, $refFK);
            }
        }

        if ($this->hasDefaultValues()) {
            $this->addApplyDefaultValues($script);
        }
        $this->addConstructor($script);

        $this->addBaseObjectMethods($script);
        if ($this->needsPhpTypeMethods()) {
            $this->addPhpTypeMethods($script);
        }

        $this->addColumnAccessorMethods($script);
        $this->addColumnMutatorMethods($script);

        $this->addHasOnlyDefaultValues($script);

        $this->addHydrate($script);
        $this->addEnsureConsistency($script);

        if (!$table->isReadOnly()) {
            $this->addManipulationMethods($script);
        }

        if ($this->isAddGenericAccessors()) {
            $this->addGetByName($script);
            $this->addGetByPosition($script);
            $this->addToArray($script);
        }

        if (!$table->isReadOnly() && $this->isAddGenericMutators()) {
            $this->addSetByName($script);
            $this->addSetByPosition($script);
            $this->addFromArray($script);
            $this->addImportFrom($script);
        }

        $this->addBuildCriteria($script);
        $this->addBuildPkeyCriteria($script);
        $this->addHashCode($script);
        $this->addGetPrimaryKey($script);
        $this->addSetPrimaryKey($script);
        $this->addIsPrimaryKeyNull($script);

        if (!$table->isReadOnly()) {
            $this->addCopy($script);
        }

        $this->addFKMethods($script);
        $this->addRefFKMethods($script);
        $this->addCrossFKMethods($script);
        $this->addClear($script);
        $this->addClearAllReferences($script);

        $this->addPrimaryString($script);

        // apply behaviors
        $this->applyBehaviorModifier('objectMethods', $script, '    ');

        if ($this->getBuildProperty('generator.objectModel.addHooks')) {
            $this->addHookMethods($script);
        }

        $this->addMagicCall($script);
    }

    /**
     * Closes class.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addClassClose(string &$script): void
    {
        $script .= "
}
";
        $this->applyBehaviorModifier('objectFilter', $script, '');
    }

    /**
     * Adds any constants to the class.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addConstants(string &$script): void
    {
        $script .= "
    /**
     * TableMap class name
     *
     * @var string
     */
    public const TABLE_MAP = '" . addslashes($this->getTableMapBuilder()->getFullyQualifiedClassName()) . "';
";
    }

    /**
     * Adds class attributes.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addAttributes(string &$script): void
    {
        $table = $this->getTable();

        $script .= "
";

        $script .= $this->renderTemplate('baseObjectAttributes');

        if (!$table->isAlias()) {
            $this->addColumnAttributes($script);
        }

        foreach ($table->getForeignKeys() as $fk) {
            $this->addFKAttributes($script, $fk);
        }

        foreach ($table->getReferrersForCodeGeneration() as $refFK) {
            $this->addRefFKAttributes($script, $refFK);
        }

        // many-to-many relationships
        foreach ($table->getCrossFks() as $crossFKs) {
            $this->addCrossFKAttributes($script, $crossFKs);
        }

        $this->addAlreadyInSaveAttribute($script);

        // apply behaviors
        $this->applyBehaviorModifier('objectAttributes', $script, '    ');
    }

    /**
     * Adds variables that store column values.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addColumnAttributes(string &$script): void
    {
        $table = $this->getTable();

        foreach ($table->getColumns() as $col) {
            $this->addColumnAttributeComment($script, $col);
            $this->addColumnAttributeDeclaration($script, $col);
            if ($col->isLazyLoad()) {
                $this->addColumnAttributeLoaderComment($script, $col);
                $this->addColumnAttributeLoaderDeclaration($script, $col);
            }
            if ($col->getType() == PropelTypes::OBJECT || $col->getType() == PropelTypes::PHP_ARRAY) {
                $this->addColumnAttributeUnserializedComment($script, $col);
                $this->addColumnAttributeUnserializedDeclaration($script, $col);
            }
            if ($col->isSetType()) {
                $this->addColumnAttributeConvertedDeclaration($script, $col);
            }
        }
    }

    /**
     * Adds comment about the attribute (variable) that stores column values.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addColumnAttributeComment(string &$script, Column $column): void
    {
        $cptype = $this->getColumnStorageType($column);
        $clo = $column->getLowercasedName();

        // Temporal values remain nullable in-memory even for required columns until they are hydrated or set.
        $orNull = '|null';

        $script .= "
    /**
     * The value for the $clo field.
     * " . $column->getDescription();
        if ($column->getDefaultValue()) {
            if ($column->getDefaultValue()->isExpression()) {
                $script .= "
     * Note: this column has a database default value of: (expression) " . $column->getDefaultValue()->getValue();
            } else {
                // array default values are exported over several lines, which would break out of the comment
                $defaultValueString = (string)preg_replace('/\s+/', ' ', $this->getDefaultValueString($column));
                $script .= "
     * Note: this column has a database default value of: " . $defaultValueString;
            }
        }
        $script .= "
     * @var $cptype{$orNull}
     */";
    }

    /**
     * Returns the PHP type of the attribute which stores the value of the given column.
     *
     * This is not necessarily the PHP type of the column, as values of some column types are
     * stored in their encoded form and only converted when accessed through the getter.
     *
     * @param \Propel\Generator\Model\Column $column
     *
     * @return string
     */
    protected function getColumnStorageType(Column $column): string
    {
        if ($column->isTemporalType()) {
            return $this->getDateTimeClass($column);
        }

        return match ($column->getType()) {
            // stored as a stream resource, the object is kept in $col_unserialized
            PropelTypes::OBJECT => 'resource',
            // stored as '| value | value |', the array is kept in $col_unserialized
            PropelTypes::PHP_ARRAY => 'string',
            // stored as the decimal representation of the value bitmask, the array is kept in $col_converted
            PropelTypes::SET => 'string',
            default => $column->getPhpType(),
        };
    }

    /**
     * Adds the declaration of a column value storage attribute.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addColumnAttributeDeclaration(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $script .= "
    protected \$" . $clo . ";
";
    }

    /**
     * Adds the comment about the attribute keeping track if an attribute value
     * has been loaded.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addColumnAttributeLoaderComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $script .= "
    /**
     * Whether the lazy-loaded \$$clo value has been loaded from database.
     * This is necessary to avoid repeated lookups if \$$clo column is NULL in the db.
     * @var bool
     */";
    }

    /**
     * Adds the declaration of the attribute keeping track of an attribute
     * loaded state.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addColumnAttributeLoaderDeclaration(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $script .= "
    protected \$" . $clo . "_isLoaded = false;
";
    }

    /**
     * Adds the comment about the serialized attribute.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addColumnAttributeUnserializedComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $script .= "
    /**
     * The unserialized \$$clo value - i.e. the persisted object.
     * This is necessary to avoid repeated calls to unserialize() at runtime.
     * @var array|null
     */";
    }

    /**
     * Adds the declaration of the serialized attribute.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addColumnAttributeUnserializedDeclaration(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName() . '_unserialized';
        $script .= "
    protected \$" . $clo . ";
";
    }

    /**
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addColumnAttributeConvertedDeclaration(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName() . '_converted';
        $script .= "
    /**
     * The converted \$" . $column->getLowercasedName() . " value - i.e. the value set as an array.
     * This is necessary to avoid repeated conversions at runtime.
     * @var array|null
     */
    protected \$" . $clo . ";
";
    }

    /**
     * Adds the constructor for this object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addConstructor(string &$script): void
    {
        $this->addConstructorComment($script);
        $this->addConstructorOpen($script);
        $this->addConstructorBody($script);
        $this->addConstructorClose($script);
    }

    /**
     * Adds the comment for the constructor
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addConstructorComment(string &$script): void
    {
        $script .= "
    /**
     * Initializes internal state of " . $this->getQualifiedClassName() . ' object.';
        if ($this->hasDefaultValues()) {
            $script .= "
     * @see applyDefaults()";
        }
        $script .= "
     */";
    }

    /**
     * Adds the function declaration for the constructor.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addConstructorOpen(string &$script): void
    {
        $script .= "
    public function __construct()
    {";
    }

    /**
     * Adds the function body for the constructor.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addConstructorBody(string &$script): void
    {
        if ($this->getParentClass() !== null) {
            $script .= "
        parent::__construct();";
        }
        if ($this->hasDefaultValues()) {
            $script .= "
        \$this->applyDefaultValues();";
        }
    }

    /**
     * Adds the function close for the constructor.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addConstructorClose(string &$script): void
    {
        $script .= "
    }
";
    }

    /**
     * Adds the base object functions.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addBaseObjectMethods(string &$script): void
    {
        $script .= $this->renderTemplate('baseObjectMethods', ['className' => $this->getUnqualifiedClassName()]);
    }

    protected function needsPhpTypeMethods(): bool
    {
        foreach ($this->getTable()->getColumns() as $column) {
            if ($column->isPrimaryKey()) {
                continue;
            }

            if ($this->getColumnMutatorType($column) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds PHP-type conversion and comparison hooks that generated models can override.
     * convertValueToPhpType() Convert a value to the configured PHP type while preserving nulls.
     * arePhpTypeValuesEqual() Compare two values using PHP-type-aware equality.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addPhpTypeMethods(string &$script): void
    {
        // Only emit object-type hooks if needed
        if ($this->needsObjectTypeHooks()) {
            $this->addConvertValueToObjectTypeMethod($script);
            $this->addAreObjectTypeValuesEqualMethod($script);
        }
    }


    protected function addConvertValueToObjectTypeMethod(string &$script): void
    {
        $script .= "
    protected function convertValueToObjectType(mixed \$value, string \$phpType, bool \$isNullable): mixed
    {
        // Default object conversion (override in model if needed)
        return \$this->convertValueToPhpType(\$value, \$phpType, \$isNullable);
    }
";
    }


    protected function addAreObjectTypeValuesEqualMethod(string &$script): void
    {
        $script .= "
    protected function areObjectTypeValuesEqual(mixed \$currentValue, mixed \$newValue, string \$phpType): bool
    {
        // Default object equality (override in model if needed)
        return \$this->arePhpTypeValuesEqual(\$currentValue, \$newValue, \$phpType);
    }
";
    }

    protected function needsObjectTypeHooks(): bool
    {
        foreach ($this->getTable()->getColumns() as $column) {
            if ($column->isPrimaryKey()) {
                continue;
            }
            if ($column->isPhpObjectType()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Adds shared trait uses at the top of the generated class body.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addCommonTraitUses(string &$script): void
    {
        $this->declareClasses(
            '\Propel\Runtime\ActiveRecord\ActiveRecordCommonTrait',
            '\Propel\Runtime\ActiveRecord\ActiveRecordHydrationTrait',
        );

        $script .= "
    use ActiveRecordCommonTrait, ActiveRecordHydrationTrait;
";
    }

    /**
     * Adds the base object hook functions.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addHookMethods(string &$script): void
    {
        $hooks = [];
        foreach (['pre', 'post'] as $hook) {
            foreach (['Insert', 'Update', 'Save', 'Delete'] as $action) {
                $hooks[$hook . $action] = strpos($script, 'function ' . $hook . $action . '(') === false;
            }
        }

        /** @var string|null $className */
        $className = ClassTools::classname($this->getBaseClass());
        $hooks['hasBaseClass'] = $this->getBehaviorContent('parentClass') !== null || $className !== null;

        $script .= $this->renderTemplate('baseObjectMethodHook', $hooks);
    }

    /**
     * Adds the applyDefaults() method, which is called from the constructor.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addApplyDefaultValues(string &$script): void
    {
        $this->addApplyDefaultValuesComment($script);
        $this->addApplyDefaultValuesOpen($script);
        $this->addApplyDefaultValuesBody($script);
        $this->addApplyDefaultValuesClose($script);
    }

    /**
     * Adds the comment for the applyDefaults method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addApplyDefaultValuesComment(string &$script): void
    {
        $script .= "
    /**
     * Applies default values to this object.
     * This method should be called from the object's constructor (or
     * equivalent initialization method).
     * @see __construct()
     */";
    }

    /**
     * Adds the function declaration for the applyDefaults method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addApplyDefaultValuesOpen(string &$script): void
    {
        $script .= "
    public function applyDefaultValues(): void
    {";
    }

    /**
     * Adds the function body of the applyDefault method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addApplyDefaultValuesBody(string &$script): void
    {
        $table = $this->getTable();
        // FIXME - Apply support for PHP default expressions here
        // see: http://propel.phpdb.org/trac/ticket/378

        $colsWithDefaults = [];
        foreach ($table->getColumns() as $column) {
            $def = $column->getDefaultValue();
            if ($def !== null && !$def->isExpression()) {
                $colsWithDefaults[] = $column;
            }
        }

        foreach ($colsWithDefaults as $column) {
            /** @var Column $column */
            $clo = $column->getLowercasedName();
            $defaultValue = $this->getDefaultValueString($column);
            if ($column->isTemporalType()) {
                $dateTimeClass = $this->getDateTimeClass($column);
                $script .= "
        \$this->" . $clo . " = PropelDateTime::newInstance($defaultValue, null, '$dateTimeClass');";
            } else {
                $script .= "
        \$this->" . $clo . " = $defaultValue;";
            }
        }
    }

    /**
     * Adds the function close for the applyDefaults method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addApplyDefaultValuesClose(string &$script): void
    {
        $script .= "
    }
";
    }

    /**
     * Adds a date/time/timestamp getter method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addTemporalAccessor(string &$script, Column $column): void
    {
        $this->addTemporalAccessorComment($script, $column);
        $this->addTemporalAccessorOpen($script, $column);
        $this->addTemporalAccessorBody($script, $column);
        $this->addTemporalAccessorClose($script);
    }

    /**
     * Adds the comment for a temporal accessor.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    public function addTemporalAccessorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $dateTimeClass = $this->getDateTimeClass($column);

        // Temporal getters stay nullable because required temporal columns still have no meaningful unset value.
        $script .= "
    /**
     * Get the [$clo] column value.
     * {$column->getDescription()}";
        if ($column->isLazyLoad()) {
            $script .= "
     * @param mixed \$con An optional connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     * @return {$dateTimeClass}|null
     */";
    }

    /**
     * Gets the default format for a temporal column from the configuration
     *
     * @param Column $column
     *
     * @return string|null
     */
    protected function getTemporalTypeDefaultFormat(Column $column): ?string
    {
        $configKey = $this->getTemporalTypeDefaultFormatConfigKey($column);

        return $configKey ? $this->getBuildProperty($configKey) : null;
    }

    /**
     * Knows which key in the configuration holds the default format for a
     * temporal type column.
     *
     * @param Column $column
     *
     * @return string|null
     */
    protected function getTemporalTypeDefaultFormatConfigKey(Column $column): ?string
    {
        return match($column->getType()) {
            PropelTypes::DATE => 'generator.dateTime.defaultDateFormat',
            PropelTypes::TIME => 'generator.dateTime.defaultTimeFormat',
            PropelTypes::TIMESTAMP, PropelTypes::DATETIME => 'generator.dateTime.defaultTimeStampFormat',
            default => null,
        };
    }

    /**
     * Adds the function declaration for a temporal accessor.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    public function addTemporalAccessorOpen(string &$script, Column $column): void
    {
        $cfc = $column->getPhpName();
        $visibility = $column->getAccessorVisibility();

        $script .= '
    ' . $visibility . " function get$cfc(";
        if ($column->isLazyLoad()) {
            $script .= '$con = null';
        }
        $script .= ")
    {";
    }

    /**
     * Gets accessor lazy loaded snippets.
     *
     * @param Column $column
     *
     * @return string
     */
    protected function getAccessorLazyLoadSnippet(Column $column): string
    {
        if ($column->isLazyLoad()) {
            $clo = $column->getLowercasedName();
            $defaultValueString = 'null';
            $def = $column->getDefaultValue();
            if ($def !== null && !$def->isExpression()) {
                $defaultValueString = $this->getDefaultValueString($column);
            }

            return "
        if (!\$this->{$clo}_isLoaded && \$this->{$clo} === {$defaultValueString} && !\$this->isNew()) {
            \$this->load{$column->getPhpName()}(\$con);
        }
";
        }

        return '';
    }

    /**
     * Adds the body of the temporal accessor.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addTemporalAccessorBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $dateTimeClass = $this->getDateTimeClass($column);

        $this->declareClasses($dateTimeClass);

        $script .= $this->getProjectionColumnGuardSnippet($column);

        if ($column->isLazyLoad()) {
            $script .= $this->getAccessorLazyLoadSnippet($column);
        }

        $script .= "
        return \$this->$clo;";
    }

    /**
     * Adds the body of the temporal accessor.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addTemporalAccessorClose(string &$script): void
    {
        $script .= "
    }
";
    }

    /**
     * Adds an object getter method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addObjectAccessor(string &$script, Column $column): void
    {
        $this->addObjectAccessorComment($script, $column);
        $this->addDefaultAccessorOpen($script, $column);
        $this->addObjectAccessorBody($script, $column);
        $this->addDefaultAccessorClose($script);
    }

    public function addObjectAccessorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $type = $column->getTypeHint() ?: ($column->getPhpType() ?: 'mixed');

        $script .= "
    /**
     * Get the [$clo] column value.
     * " . $column->getDescription();
        if ($column->isLazyLoad()) {
            $script .= "
     * @param ?ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     * @return {$type}|null
     */";
    }

    /**
     * Adds the function body for an object accessor method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addObjectAccessorBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $cloUnserialized = $clo . '_unserialized';
        $script .= $this->getProjectionColumnGuardSnippet($column);
        if ($column->isLazyLoad()) {
            $script .= $this->getAccessorLazyLoadSnippet($column);
        }

        $script .= "
        if (null == \$this->$cloUnserialized && is_resource(\$this->$clo)) {
            if (\$serialisedString = stream_get_contents(\$this->$clo)) {
                \$this->$cloUnserialized = unserialize(\$serialisedString);
            }
        }

        return \$this->$cloUnserialized;";
    }

    /**
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addJsonAccessor(string &$script, Column $column): void
    {
        $this->addJsonAccessorComment($script, $column);
        $this->addJsonAccessorOpen($script, $column);
        $this->addJsonAccessorBody($script, $column);
        $this->addDefaultAccessorClose($script);
    }

    /**
     * Add the comment for a json accessor method (a getter).
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    public function addJsonAccessorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $script .= "
    /**
     * Get the [$clo] column value.
     * " . $column->getDescription() . "
     * @param bool \$asArray Returns the JSON data as array instead of object
     ";
        if ($column->isLazyLoad()) {
            $script .= "
     * @param ?ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
      * @return object|array|null
     */";
    }

    /**
     * Adds the function declaration for a JSON accessor.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    public function addJsonAccessorOpen(string &$script, Column $column): void
    {
        $cfc = $column->getPhpName();
        $visibility = $column->getAccessorVisibility();

        $script .= "
    " . $visibility . " function get$cfc(\$asArray = true";
        if ($column->isLazyLoad()) {
            $script .= ', ?ConnectionInterface $con = null';
        }

        $script .= ")
    {";
    }

    /**
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addJsonAccessorBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $script .= "
        \$this->assertColumnLoaded('" . $column->getPhpName() . "');
        return json_decode(\$this->$clo, \$asArray);";
    }

    /**
     * Adds an array getter method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addArrayAccessor(string &$script, Column $column): void
    {
        $this->addArrayAccessorComment($script, $column);
        $this->addDefaultAccessorOpen($script, $column);
        $this->addArrayAccessorBody($script, $column);
        $this->addDefaultAccessorClose($script);
    }

    public function addArrayAccessorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $type = $column->getTypeHint() ?: ($column->getPhpType() ?: 'mixed');

        $script .= "
    /**
     * Get the [$clo] column value.
     * " . $column->getDescription();
        if ($column->isLazyLoad()) {
            $script .= "
     * @param ?ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     * @return {$type}
     */";
    }

    /**
     * Adds the function body for an array accessor method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addArrayAccessorBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $cloUnserialized = $clo . '_unserialized';
        $script .= $this->getProjectionColumnGuardSnippet($column);
        if ($column->isLazyLoad()) {
            $script .= $this->getAccessorLazyLoadSnippet($column);
        }

        $script .= "
        if (null === \$this->$cloUnserialized) {
            \$this->$cloUnserialized = [];
        }
        if (!\$this->$cloUnserialized && null !== \$this->$clo) {
            \$$cloUnserialized = substr(\$this->$clo ?? '', 2, -2);
            \$this->$cloUnserialized = '' !== \$$cloUnserialized ? explode(' | ', \$$cloUnserialized) : [];
        }

        return \$this->$cloUnserialized ?? [];";
    }

    /**
     * Adds a boolean isser method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addBooleanAccessor(string &$script, Column $column): void
    {
        $name = self::getBooleanAccessorName($column);
        if (in_array($name, ClassTools::getPropelReservedMethods(), true)) {
            //TODO: Issue a warning telling the user to use default accessors
            return; // Skip boolean accessors for reserved names
        }
        $this->addDefaultAccessorComment($script, $column);
        $this->addBooleanAccessorOpen($script, $column);
        $this->addBooleanAccessorBody($script, $column);
        $this->addDefaultAccessorClose($script);
    }

    /**
     * Returns the name to be used as boolean accessor name
     *
     * @param Column $column
     *
     * @return string
     */
    protected static function getBooleanAccessorName(Column $column): string
    {
        $name = $column->getCamelCaseName();
        if (!preg_match('/^(?:is|has)(?=[A-Z])/', $name)) {
            $name = 'is' . ucfirst($name);
        }

        return $name;
    }

    /**
     * Adds the function declaration for a boolean accessor.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    public function addBooleanAccessorOpen(string &$script, Column $column): void
    {
        $name = self::getBooleanAccessorName($column);
        $visibility = $column->getAccessorVisibility();

        $script .= "
    " . $visibility . " function $name(";
        if ($column->isLazyLoad()) {
            $script .= '?ConnectionInterface $con = null';
        }

        $script .= ")
    {";
    }

    /**
     * Adds the function body for a boolean accessor method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addBooleanAccessorBody(string &$script, Column $column): void
    {
        $cfc = $column->getPhpName();

        $script .= "
        return \$this->get$cfc(";

        if ($column->isLazyLoad()) {
            $script .= '$con';
        }

        $script .= ');';
    }

    /**
     * Adds an enum getter method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addEnumAccessor(string &$script, Column $column): void
    {
        $this->addEnumAccessorComment($script, $column);
        $this->addDefaultAccessorOpen($script, $column);
        $this->addEnumAccessorBody($script, $column);
        $this->addDefaultAccessorClose($script);
    }

    /**
     * Add the comment for an enum accessor method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    public function addEnumAccessorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $script .= "
    /**
     * Get the [$clo] column value.
     * " . $column->getDescription();
        if ($column->isLazyLoad()) {
            $script .= "
     * @param ?ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     * @return string|null
     * @throws \\Propel\\Runtime\\Exception\\PropelException
     */";
    }

    /**
     * Adds the function body for an enum accessor method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addEnumAccessorBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $script .= $this->getProjectionColumnGuardSnippet($column);
        if ($column->isLazyLoad()) {
            $script .= $this->getAccessorLazyLoadSnippet($column);
        }

        $script .= "
        if (null === \$this->$clo) {
            return null;
        }
        \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->getColumnConstant($column) . ");
        if (!isset(\$valueSet[\$this->$clo])) {
            throw new PropelException('Unknown stored enum key: ' . \$this->$clo);
        }

        return \$valueSet[\$this->$clo];";
    }

    /**
     * Adds a SET column getter method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addSetAccessor(string &$script, Column $column): void
    {
        $this->addSetAccessorComment($script, $column);
        $this->addDefaultAccessorOpen($script, $column);
        $this->addSetAccessorBody($script, $column);
        $this->addDefaultAccessorClose($script);
    }

    /**
     * Add the comment for a SET column accessor method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    public function addSetAccessorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $script .= "
    /**
     * Get the [$clo] column value.
     * " . $column->getDescription();
        if ($column->isLazyLoad()) {
            $script .= "
     * @param ?ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     * @return array|null
     * @throws \\Propel\\Runtime\\Exception\\PropelException
     */";
    }

    /**
     * Adds the function body for a SET column accessor method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addSetAccessorBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $cloConverted = $clo . '_converted';
        $script .= $this->getProjectionColumnGuardSnippet($column);
        if ($column->isLazyLoad()) {
            $script .= $this->getAccessorLazyLoadSnippet($column);
        }
        $this->declareClasses(
            'Propel\Common\Util\SetColumnConverter',
            'Propel\Common\Exception\SetColumnConverterException',
        );

        $script .= "
        if (null === \$this->$cloConverted) {
            \$this->$cloConverted = [];
        }
        if (!\$this->$cloConverted && null !== \$this->$clo) {
            \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->getColumnConstant($column) . ");
            try {
                \$this->$cloConverted = SetColumnConverter::convertIntToArray(\$this->$clo, \$valueSet);
            } catch (SetColumnConverterException \$e) {
                throw new PropelException('Unknown stored set key: ' . \$e->getValue(), \$e->getCode(), \$e);
            }
        }

        return \$this->$cloConverted;";
    }

    /**
     * Adds a tester method for an array column.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addHasArrayElement(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $cfc = $column->getPhpName();
        $visibility = $column->getAccessorVisibility();
        $singularPhpName = $column->getPhpSingularName();
        $columnType = $column->isSetType() ? 'set' : 'array';
        $script .= "
    /**
     * Test the presence of a value in the [$clo] $columnType column value.
     * @param mixed \$value
     * " . $column->getDescription();
        if ($column->isLazyLoad()) {
            $script .= "
     * @param ?ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     * @return bool
     */
    $visibility function has$singularPhpName(\$value";
        if ($column->isLazyLoad()) {
            $script .= ', ?ConnectionInterface $con = null';
        }

        $script .= "): bool
    {
        return in_array(\$value, \$this->get$cfc(";
        if ($column->isLazyLoad()) {
            $script .= '$con';
        }

        $script .= "));
    }
";
    }

    /**
     * Adds a normal (non-temporal) getter method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addDefaultAccessor(string &$script, Column $column): void
    {
        $this->addDefaultAccessorComment($script, $column);
        $this->addDefaultAccessorOpen($script, $column);
        $this->addDefaultAccessorBody($script, $column);
        $this->addDefaultAccessorClose($script);

        if ($this->shouldGenerateTryDefaultAccessor($column)) {
            $this->addTryDefaultAccessor($script, $column);
        }
    }



    /**
     * Add the comment for a default accessor method (a getter).
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    public function addDefaultAccessorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $orNull = (!$column->isPrimaryKey() && $this->isNullableInGeneratedObjectApi($column)) ? '|null' : '';

        $script .= "
    /**
     * Get the [$clo] column value.
     * " . $column->getDescription();
        if ($column->isLazyLoad()) {
            $script .= "
     * @param ?ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     * @return " . ($column->getTypeHint() ?: ($column->getPhpType() ?: 'mixed')) . $orNull . "
     */";
    }

    /**
     * Adds the function declaration for a default accessor.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    public function addDefaultAccessorOpen(string &$script, Column $column): void
    {
        $cfc = $column->getPhpName();
        $visibility = $column->getAccessorVisibility();

        $script .= "
    " . $visibility . " function get$cfc(";
        if ($column->isLazyLoad()) {
            $script .= '?ConnectionInterface $con = null';
        }

        $script .= ")
    {";
    }

    /**
     * Adds the function body for a default accessor method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addDefaultAccessorBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $script .= $this->getProjectionColumnGuardSnippet($column);
        if ($column->isLazyLoad()) {
            $script .= $this->getAccessorLazyLoadSnippet($column);
        }

        $this->triggerDebugger('UserStatsDaily', 'CasinoCreditRollback', $column);

        $nullGuardException = $this->getNullGuardExceptionForAccessor($column);
        if ($nullGuardException !== null) {
            $script .= "
        if (\$this->$clo === null) {
            throw new PropelException('$nullGuardException');
        }

        return \$this->$clo;";

            return;
        }

        $unsetValue = $this->getUnsetValueForAccessor($column);
        if ($unsetValue === null) {
            $script .= "
        return \$this->$clo;";

            return;
        }

        $script .= "
        return \$this->$clo ?? {$unsetValue};";
    }

    /**
     * Adds the function close for a default accessor method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addDefaultAccessorClose(string &$script): void
    {
        $script .= "
    }
";
    }

    protected function getProjectionColumnGuardSnippet(Column $column): string
    {
        return "
        \$this->assertColumnLoaded('" . $column->getPhpName() . "');
";
    }

    /**
     * Adds a nullable accessor for primary key columns.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addTryDefaultAccessor(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $cfc = $column->getPhpName();
        $visibility = $column->getAccessorVisibility();
        $type = $column->getTypeHint() ?: ($column->getPhpType() ?: 'mixed');

        $script .= "

    /**
     * Try to get the [$clo] column value.
     * " . $column->getDescription();
        if ($column->isLazyLoad()) {
            $script .= "
     * @param ?ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     * @return {$type}|null
     */
    {$visibility} function tryGet{$cfc}(";
        if ($column->isLazyLoad()) {
            $script .= '?ConnectionInterface $con = null';
        }

        $script .= ")
    {";

        if ($column->isLazyLoad()) {
            $script .= $this->getAccessorLazyLoadSnippet($column);
        }

        $script .= "
        return \$this->$clo;
    }
";
    }

    /**
     * Adds the lazy loader method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addLazyLoader(string &$script, Column $column): void
    {
        $this->addLazyLoaderComment($script, $column);
        $this->addLazyLoaderOpen($script, $column);
        $this->addLazyLoaderBody($script, $column);
        $this->addLazyLoaderClose($script);
    }

    /**
     * Adds the comment for the lazy loader method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addLazyLoaderComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $script .= "
    /**
     * Load the value for the lazy-loaded [$clo] column.
     *
     * This method performs an additional query to return the value for
     * the [$clo] column, since it is not populated by
     * the hydrate() method.
     *
     * @param \$con ConnectionInterface|null (optional) The ConnectionInterface connection to use.
     * @return void
     * @throws \Propel\Runtime\Exception\PropelException - any underlying error will be wrapped and re-thrown.
     */";
    }

    /**
     * Adds the function declaration for the lazy loader method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addLazyLoaderOpen(string &$script, Column $column): void
    {
        $cfc = $column->getPhpName();
        $script .= "
    protected function load$cfc(?ConnectionInterface \$con = null)
    {";
    }

    /**
     * Adds the function body for the lazy loader method.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addLazyLoaderBody(string &$script, Column $column): void
    {
        $platform = $this->getPlatform();
        $clo = $column->getLowercasedName();

        // pdo_sqlsrv driver requires the use of PDOStatement::bindColumn() or a hex string will be returned
        if ($column->getType() === PropelTypes::BLOB && $platform instanceof SqlsrvPlatform) {
            $script .= "
        \$c = \$this->buildPkeyCriteria();
        \$c->addSelectColumn(" . $this->getColumnConstant($column) . ");
        try {
            \$row = [0 => null];
            /** @var \\Propel\\Runtime\\DataFetcher\\DataFetcherInterface */
            \$dataFetcher = " . $this->getQueryClassName() . "::create(null, \$c)->setFormatter(ModelCriteria::FORMAT_STATEMENT)->find(\$con);

            if (\$dataFetcher instanceof PDODataFetcher) {
                \$dataFetcher->bindColumn(1, \$row[0], PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
            }

            \$row = \$dataFetcher->fetch(PDO::FETCH_BOUND);
            \$dataFetcher->close();";
        } else {
            $script .= "
        \$c = \$this->buildPkeyCriteria();
        \$c->addSelectColumn(" . $this->getColumnConstant($column) . ");
        try {
            /** @var \\Propel\\Runtime\\DataFetcher\\DataFetcherInterface */
            \$dataFetcher = " . $this->getQueryClassName() . "::create(null, \$c)->setFormatter(ModelCriteria::FORMAT_STATEMENT)->find(\$con);

            \$row = \$dataFetcher->fetch();
            \$dataFetcher->close();";
        }

        $script .= "

            \$firstColumn = is_array(\$row) ? current(\$row) : null;
";

        if ($column->getType() === PropelTypes::CLOB && $platform instanceof OraclePlatform) {
            // PDO_OCI returns a stream for CLOB objects, while other PDO adapters return a string...
            $script .= "
            if (\$firstColumn) {
                \$this->$clo = stream_get_contents(\$firstColumn);
            }";
        } elseif ($column->isLobType() && !$platform->hasStreamBlobImpl()) {
            $script .= "
            if (\$firstColumn !== null) {
                \$this->$clo = fopen('php://memory', 'r+');
                fwrite(\$this->$clo, \$firstColumn);
                rewind(\$this->$clo);
            } else {
                \$this->$clo = null;
            }";
        } elseif ($column->requiresUidBinaryConversion()) {
            $script .= "
            if (is_resource(\$firstColumn)) {
                \$firstColumn = stream_get_contents(\$firstColumn);
            }
            \$this->$clo = (\$firstColumn !== null && \$firstColumn !== '') ? UuidConverter::binToUid(\$firstColumn) : null;";
        } elseif ($column->requiresUidStringConversion()) {
            $script .= "
            \$this->$clo = (\$firstColumn !== null && \$firstColumn !== '') ? UuidConverter::stringToUid(\$firstColumn) : null;";
        } elseif ($column->isPhpPrimitiveType()) {
            $script .= "
            \$this->$clo = (\$firstColumn !== null) ? (" . $column->getPhpType() . ') $firstColumn : null;';
        } elseif ($column->isPhpObjectType()) {
            $script .= "
            \$this->$clo = (\$firstColumn !== null) ? new " . $column->getPhpType() . '($firstColumn) : null;';
        } elseif ($column->getType() === PropelTypes::UUID_BINARY || $column->requiresMysqlUuidBinaryConversion()) {
            $uuidSwapFlag = $this->getUuidSwapFlagLiteral($column);
            $script .= "
            if (is_resource(\$firstColumn)) {
                \$firstColumn = stream_get_contents(\$firstColumn);
            }
            \$this->$clo = (\$firstColumn) ? UuidConverter::binToUuid(\$firstColumn, $uuidSwapFlag) : null;";
        } else {
            $script .= "
            \$this->$clo = \$firstColumn;";
        }

        $script .= "
            \$this->" . $clo . "_isLoaded = true;
        } catch (Exception \$e) {
            throw new PropelException(\"Error loading value for [$clo] column on demand.\", 0, \$e);
        }";
    }

    /**
     * Adds the function close for the lazy loader.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addLazyLoaderClose(string &$script): void
    {
        $script .= "
    }";
    }

    /**
     * Adds the open of the mutator (setter) method for a column.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addMutatorOpen(string &$script, Column $column): void
    {
        $this->addMutatorComment($script, $column);
        $this->addMutatorOpenOpen($script, $column);
        $this->addMutatorOpenBody($script, $column);
    }

    /**
     * Adds the open of the mutator (setter) method for a JSON column.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addJsonMutatorOpen(string &$script, Column $column): void
    {
        $this->addJsonMutatorComment($script, $column);
        $this->addMutatorOpenOpen($script, $column);
        $this->addMutatorOpenBody($script, $column);
    }

    /**
     * Adds the comment for a mutator.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    public function addJsonMutatorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $orNull = $column->isNotNull() ? '' : '|null';

        $script .= "
    /**
     * Set the value of [$clo] column.
     * " . $column->getDescription() . "
     * @param string|array|object{$orNull} \$v new value
     * @return \$this The current object (for fluent API support)
     */";
    }

    /**
     * Adds the comment for a mutator.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    public function addMutatorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $type = $column->getPhpType();
        if ($type && $this->isNullableInGeneratedObjectApi($column)) {
            $type .= '|null';
        } elseif (!$type) {
            // object columns have no PHP type, they accept anything serializable
            $type = 'mixed';
        }

        $script .= "
    /**
     * Set the value of [$clo] column.
     * " . $column->getDescription() . "
     * @param " . $type . " \$v New value
     * @return \$this The current object (for fluent API support)
     */";
    }

    /**
     * Adds the mutator function declaration.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    public function addMutatorOpenOpen(string &$script, Column $column): void
    {
        $cfc = $column->getPhpName();
        $visibility = $this->getTable()->isReadOnly() ? 'protected' : $column->getMutatorVisibility();

        $typeHint = '';
        $null = '';

        if ($column->getTypeHint()) {
            $typeHint = $column->getTypeHint();
            if ($typeHint !== 'array') {
                $typeHint = $this->declareClass($typeHint);
            }

            $typeHint .= ' ';

            if (!$column->isNotNull()) {
                $null = ' = null';
            }
        }

        $script .= "
    " . $visibility . " function set$cfc($typeHint\$v$null)
    {";
    }

    /**
     * Adds the mutator open body part.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addMutatorOpenBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $cfc = $column->getPhpName();
        if (
            $column->hasTransformer()
            && !$column->isNativeArrayType()
            && $column->getType() !== PropelTypes::PHP_ARRAY
        ) {
            $transformer = $column->getTransformer();
            $script .= "
        if (\$v !== null) {
            \$v = $transformer(\$v);
        }
";
        }
        if ($column->isLazyLoad()) {
            $script .= "
        // explicitly set the is-loaded flag to true for this lazy load col;
        // it doesn't matter if the value is actually set or not (logic below) as
        // any attempt to set the value means that no db lookup should be performed
        // when the get$cfc() method is called.
        \$this->" . $clo . "_isLoaded = true;
";
        }
    }

    /**
     * Adds the close of the mutator (setter) method for a column.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addMutatorClose(string &$script, Column $column): void
    {
        $this->addMutatorCloseBody($script, $column);
        $this->addMutatorCloseClose($script, $column);
    }

    /**
     * Adds the body of the close part of a mutator.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addMutatorCloseBody(string &$script, Column $column): void
    {
        $table = $this->getTable();

        if ($column->isForeignKey()) {
            foreach ($column->getForeignKeys() as $fk) {
                $tblFK = $table->getDatabase()->getTable($fk->getForeignTableName());
                $colFK = $tblFK->getColumn($fk->getMappedForeignColumn($column->getName()));

                if (!$colFK) {
                    continue;
                }

                $varName = $this->getFKVarName($fk);

                $script .= "
        if (\$this->$varName !== null && \$this->" . $varName . '->get' . $colFK->getPhpName() . "() !== \$v) {
            \$this->$varName = null;
        }
";
            }
        }

        foreach ($column->getReferrers() as $refFK) {
            if ($refFK->isSkipRefCode()) {
                continue;
            }

            $tblFK = $this->getDatabase()->getTable($refFK->getForeignTableName());

            if ($tblFK->getName() != $table->getName()) {
                foreach ($column->getForeignKeys() as $fk) {
                    $tblFK = $table->getDatabase()->getTable($fk->getForeignTableName());
                    $colFK = $tblFK->getColumn($fk->getMappedForeignColumn($column->getName()));

                    if ($refFK->isLocalPrimaryKey()) {
                        $varName = $this->getPKRefFKVarName($refFK);
                        $script .= "
        // update associated " . $tblFK->getPhpName() . "
        if (\$this->$varName !== null) {
            \$this->{$varName}->set" . $colFK->getPhpName() . "(\$v);
        }
";
                    } else {
                        $collName = $this->getRefFKCollVarName($refFK);
                        $script .= "

        // update associated " . $tblFK->getPhpName() . "
        if (\$this->$collName !== null) {
            foreach (\$this->$collName as \$referrerObject) {
                    \$referrerObject->set" . $colFK->getPhpName() . "(\$v);
                }
            }
";
                    }
                }
            }
        }
    }

    /**
     * Adds the close for the mutator close
     *
     * @see addMutatorClose()
     *
     * @param string $script The script will be modified in this method.
     * @param Column $col The current column.
     *
     * @return void
     */
    protected function addMutatorCloseClose(string &$script, Column $col): void
    {
        $script .= "
        return \$this;
    }
";
    }

    /**
     * Adds a setter for BLOB columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param Column $col The current column.
     *
     * @return void
     */
    protected function addLobMutator(string &$script, Column $col): void
    {
        $this->addMutatorOpen($script, $col);
        $clo = $col->getLowercasedName();
        $script .= "
        // Because BLOB columns are streams in PDO we have to assume that they are
        // always modified when a new value is passed in.  For example, the contents
        // of the stream itself may have changed externally.
        if (!is_resource(\$v) && \$v !== null) {
            \$this->$clo = fopen('php://memory', 'r+');
            fwrite(\$this->$clo, \$v);
            rewind(\$this->$clo);
        } else { // it's already a stream
            \$this->$clo = \$v;
        }
        \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * Adds a setter method for date/time/timestamp columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param Column $col The current column.
     *
     * @return void
     */
    protected function addTemporalMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();
        $dateTimeClass = $this->getDateTimeClass($col);

        $this->declareClasses($dateTimeClass, '\Propel\Runtime\Util\PropelDateTime');

        $this->addTemporalMutatorComment($script, $col);
        $this->addMutatorOpenOpen($script, $col);
        $this->addMutatorOpenBody($script, $col);

        $format = match($col->getType()) {
            PropelTypes::DATE => 'Y-m-d',
            PropelTypes::TIME => 'H:i:s.u',
            default => 'Y-m-d H:i:s.u',
        };

        $def = $col->getDefaultValue();
        if ($def !== null && !$def->isExpression()) {
            $defaultValue = $this->getDefaultValueString($col);
            $defaultFormat = var_export($this->getTemporalFormatter($col), true);
            $script .= "
        \$this->setTemporalValue(\$this->$clo, \$v, '$dateTimeClass', " . $this->getColumnConstant($col) . ", '$format', $defaultValue, $defaultFormat);
";
        } else {
            $script .= "
        \$this->setTemporalValue(\$this->$clo, \$v, '$dateTimeClass', " . $this->getColumnConstant($col) . ", '$format');
";
        }
        $this->addMutatorClose($script, $col);
    }

    /**
     * @param string $script
     * @param Column $col
     *
     * @return void
     */
    public function addTemporalMutatorComment(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();
        $orNull = $col->isNotNull() ? '' : '|null';

        $script .= "
    /**
     * Sets the value of [$clo] column.
     * " . $col->getDescription() . "
     * @param string|integer|\DateTimeInterface{$orNull} \$v string, integer (timestamp), or \DateTimeInterface value.
     *               Empty strings are treated as NULL.
     * @return \$this The current object (for fluent API support)
     */";
    }

    /**
     * Adds a setter for Object columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param Column $col The current column.
     *
     * @return void
     */
    protected function addObjectMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();
        $cloUnserialized = $clo . '_unserialized';
        $this->addMutatorOpen($script, $col);

        $script .= "
        if (null === \$this->$clo || stream_get_contents(\$this->$clo) !== serialize(\$v)) {
            \$this->$cloUnserialized = \$v;
            \$this->$clo = fopen('php://memory', 'r+');
            fwrite(\$this->$clo, serialize(\$v));
            \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
        }
        rewind(\$this->$clo);
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * Adds a setter for Json columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param Column $col The current column.
     *
     * @return void
     */
    protected function addJsonMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();

        $this->addJsonMutatorOpen($script, $col);

        $script .= "
        if (is_string(\$v)) {
            // JSON as string needs to be decoded/encoded to get a reliable comparison (spaces, ...)
            \$v = json_decode(\$v);
        }
        \$encodedValue = json_encode(\$v);
        if (\$encodedValue !== \$this->$clo) {
            \$this->$clo = \$encodedValue;
            \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
        }
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * Adds a setter for Array columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param Column $col The current column.
     *
     * @return void
     */
    protected function addArrayMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();
        $cloUnserialized = $clo . '_unserialized';
        $this->addMutatorOpen($script, $col);
        $this->addArrayElementTransformer($script, $col);

        $script .= "
        if (\$this->$cloUnserialized !== \$v) {
            \$this->$cloUnserialized = \$v;
            \$this->$clo = \$v ? '| ' . implode(' | ', \$v) . ' |' : null;
            \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
        }
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * Adds a push method for an array column.
     *
     * @param string $script The script will be modified in this method.
     * @param Column $col The current column.
     *
     * @return void
     */
    protected function addAddArrayElement(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();
        $cfc = $col->getPhpName();
        $visibility = $col->getAccessorVisibility();
        $singularPhpName = $col->getPhpSingularName();
        $columnType = $col->isSetType() ? 'set' : 'array';
        $script .= "
    /**
     * Adds a value to the [$clo] $columnType column value.
     * @param mixed \$value
     * " . $col->getDescription();
        if ($col->isLazyLoad()) {
            $script .= "
     * @param ?ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     * @return \$this The current object (for fluent API support)
     */
    $visibility function add$singularPhpName(\$value";
        if ($col->isLazyLoad()) {
            $script .= ', ?ConnectionInterface $con = null';
        }

        $script .= ")
    {
        \$currentArray = \$this->get$cfc(";
        if ($col->isLazyLoad()) {
            $script .= '$con';
        }

        $script .= ");
        \$currentArray[] = \$value;
        \$this->set$cfc(\$currentArray);

        return \$this;
    }
";
    }

    /**
     * Adds a remove method for an array column.
     *
     * @param string $script The script will be modified in this method.
     * @param Column $col The current column.
     *
     * @return void
     */
    protected function addRemoveArrayElement(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();
        $cfc = $col->getPhpName();
        $visibility = $col->getAccessorVisibility();
        $singularPhpName = $col->getPhpSingularName();
        $columnType = $col->isSetType() ? 'set' : 'array';
        $script .= "
    /**
     * Removes a value from the [$clo] $columnType column value.
     * @param mixed \$value
     * " . $col->getDescription();
        if ($col->isLazyLoad()) {
            $script .= "
     * @param ?ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     * @return \$this The current object (for fluent API support)
     */
    $visibility function remove$singularPhpName(\$value";
        if ($col->isLazyLoad()) {
            $script .= ', ?ConnectionInterface $con = null';
        }
        // we want to reindex the array, so array_ functions are not the best choice
        $script .= ")
    {
        \$targetArray = [];
        foreach (\$this->get$cfc(";
        if ($col->isLazyLoad()) {
            $script .= '$con';
        }
        $script .= ") as \$element) {
            if (\$element != \$value) {
                \$targetArray[] = \$element;
            }
        }
        \$this->set$cfc(\$targetArray);

        return \$this;
    }
";
    }

    /**
     * Adds a setter for Enum columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param Column $col The current column.
     *
     * @return void
     */
    protected function addEnumMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();
        $this->addEnumMutatorComment($script, $col);
        $this->addMutatorOpenOpen($script, $col);
        $this->addMutatorOpenBody($script, $col);

        $script .= "
        if (\$v !== null) {
            \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->getColumnConstant($col) . ");
            \$valueKey = array_search(\$v, \$valueSet);
            if (\$valueKey === false) {
                throw new PropelException(sprintf('Value \"%s\" is not accepted in this enumerated column', \$v));
            }
            \$v = (int)\$valueKey;
        }

        if (\$this->$clo !== \$v) {
            \$this->$clo = \$v;
            \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
        }
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * Adds the comment for an enum mutator.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    public function addEnumMutatorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $orNull = $column->isNotNull() ? '' : '|null';

        $script .= "
    /**
     * Set the value of [$clo] column.
     * " . $column->getDescription() . "
     * @param string{$orNull} \$v new value
     * @return \$this The current object (for fluent API support)
     * @throws \\Propel\\Runtime\\Exception\\PropelException
     */";
    }

    /**
     * Adds a setter for SET column mutator.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param Column $col The current column.
     *
     * @return void
     */
    protected function addSetMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();
        $this->addSetMutatorComment($script, $col);
        $this->addMutatorOpenOpen($script, $col);
        $this->addMutatorOpenBody($script, $col);
        $cloConverted = $clo . '_converted';

        $this->declareClasses(
            'Propel\Common\Util\SetColumnConverter',
            'Propel\Common\Exception\SetColumnConverterException',
        );

        $script .= "
        \$valueArray = \$v ?? [];
        if (\$this->$cloConverted === null || count(array_diff(\$this->$cloConverted, \$valueArray)) > 0 || count(array_diff(\$valueArray, \$this->$cloConverted)) > 0) {
            \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->getColumnConstant($col) . ");
            try {
                \$v = SetColumnConverter::convertToInt(\$v, \$valueSet);
            } catch (SetColumnConverterException \$e) {
                throw new PropelException(sprintf('Value \"%s\" is not accepted in this set column', \$e->getValue()), \$e->getCode(), \$e);
            }
            if (\$this->$clo !== \$v) {
                \$this->$cloConverted = null;
                \$this->$clo = \$v;
                \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
            }
        }
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * Adds the comment for a SET column mutator.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    public function addSetMutatorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $orNull = $column->isNotNull() ? '' : '|null';

        $script .= "
    /**
     * Set the value of [$clo] column.
     * " . $column->getDescription() . "
     * @param array{$orNull} \$v new value
     * @return \$this The current object (for fluent API support)
     * @throws \\Propel\\Runtime\\Exception\\PropelException
     */";
    }

    /**
     * Adds setter method for boolean columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param Column $col The current column.
     *
     * @return void
     */
    protected function addBooleanMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();

        $this->addBooleanMutatorComment($script, $col);
        $this->addMutatorOpenOpen($script, $col);
        $this->addMutatorOpenBody($script, $col);

        $script .= "
        if (is_string(\$v)) {
            \$v = in_array(strtolower(\$v), ['false', 'off', '-', 'no', 'n', '0', '']) ? false : true;
        } else {
            \$v = (bool) \$v;
        }

        if (\$this->$clo !== \$v) {
            \$this->$clo = \$v;
            \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
        }
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * @param string $script
     * @param Column $col
     *
     * @return void
     */
    public function addBooleanMutatorComment(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();

        $orNull = $col->isNotNull() ? '' : '|null';

        $script .= "
    /**
     * Sets the value of the [$clo] column.
     * Non-boolean arguments are converted using the following rules:
     *   * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *   * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     * Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     * " . $col->getDescription() . "
     * @param bool|integer|string{$orNull} \$v The new value
     * @return \$this The current object (for fluent API support)
     */";
    }

    /**
     * Adds setter method for "normal" columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param Column $col The current column.
     *
     * @return void
     */
    protected function addDefaultMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();
        $phpTypeReference = var_export($col->getPhpType(), true);

        if ($col->isPhpObjectType()) {
            $phpTypeReference = $this->declareClass($col->getPhpType()) . '::class';
        }

        $this->addMutatorOpen($script, $col);
        $isNullable = var_export(!$col->isNotNull(), true);

        // Use scalar or object conversion/equality as appropriate
        if (!$col->isPrimaryKey()) {
            if ($col->isPhpObjectType()) {
                $script .= "
        \$v = \$this->convertValueToObjectType(\$v, $phpTypeReference, $isNullable); 
    ";
            } else {
                $script .= "
        \$v = \$this->convertValueToPhpType(\$v, $phpTypeReference, $isNullable);
    ";
            }
        }

        if ($col->hasTransformer() && $col->isNativeArrayType()) {
            $this->addArrayElementTransformer($script, $col);
        }

        $comparison = $col->isPrimaryKey()
            ? "\$this->$clo !== \$v"
            : ($col->isPhpObjectType()
                ? "!\$this->areObjectTypeValuesEqual(\$this->$clo, \$v, $phpTypeReference)"
                : "!\$this->arePhpTypeValuesEqual(\$this->$clo, \$v, $phpTypeReference)");

        $script .= "
        if ($comparison) {
            \$this->$clo = \$v;
            \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
        }
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * Adds element-wise transformation for array column values.
     *
     * @param string $script
     * @param Column $column
     *
     * @return void
     */
    protected function addArrayElementTransformer(string &$script, Column $column): void
    {
        if (!$column->hasTransformer()) {
            return;
        }

        $transformer = $column->getTransformer();
        $script .= "
        if (\$v !== null) {
            \$v = array_map(
                static fn (\$value) => \$value === null ? null : $transformer(\$value),
                \$v,
            );
        }
";
    }

    /**
     * Adds the hasOnlyDefaultValues() method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHasOnlyDefaultValues(string &$script): void
    {
        $this->addHasOnlyDefaultValuesComment($script);
        $this->addHasOnlyDefaultValuesOpen($script);
        $this->addHasOnlyDefaultValuesBody($script);
        $this->addHasOnlyDefaultValuesClose($script);
    }

    /**
     * Adds the comment for the hasOnlyDefaultValues method
     *
     * @see addHasOnlyDefaultValues
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHasOnlyDefaultValuesComment(string &$script): void
    {
        $script .= "
    /**
     * Indicates whether the columns in this object are only set to default values.
     *
     * This method can be used in conjunction with isModified() to indicate whether an object is both
     * modified _and_ has some values set which are non-default.
     *
     * @return bool Whether the columns in this object are only been set with default values.
     */";
    }

    /**
     * Adds the function declaration for the hasOnlyDefaultValues method
     *
     * @see addHasOnlyDefaultValues
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHasOnlyDefaultValuesOpen(string &$script): void
    {
        $script .= "
    public function hasOnlyDefaultValues(): bool
    {";
    }

    /**
     * Adds the function body for the hasOnlyDefaultValues method
     *
     * @see addHasOnlyDefaultValues
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHasOnlyDefaultValuesBody(string &$script): void
    {
        $table = $this->getTable();
        $colsWithDefaults = [];
        foreach ($table->getColumns() as $col) {
            $def = $col->getDefaultValue();
            if ($def !== null && !$def->isExpression()) {
                $colsWithDefaults[] = $col;
            }
        }

        foreach ($colsWithDefaults as $col) {
            $clo = $col->getLowercasedName();
            $accessor = "\$this->$clo";
            $defaultValueString = $this->getDefaultValueString($col);
            if ($col->isTemporalType() && $defaultValueString !== 'null') {
                $fmt = $this->getTemporalFormatter($col);
                $accessor = "\$this->$clo && \$this->{$clo}->format('$fmt')";
            }
            $notEquals = '!==';
            if (strpos($defaultValueString, 'new ') === 0) {
                $notEquals = '!='; // allow object-comparison for custom PHP types
            }
            $script .= "
            if ($accessor $notEquals $defaultValueString) {
                return false;
            }
";
        }
    }

    /**
     * Adds the function close for the hasOnlyDefaultValues method
     *
     * @see addHasOnlyDefaultValues
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHasOnlyDefaultValuesClose(string &$script): void
    {
        $script .= "
        // otherwise, everything was equal, so return TRUE
        return true;";
        $script .= "
    }
";
    }

    /**
     * Adds the hydrate() method, which sets attributes of the object based on a ResultSet.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHydrate(string &$script): void
    {
        $this->addHydrateComment($script);
        $this->addHydrateOpen($script);
        $this->addHydrateBody($script);
        $this->addHydrateClose($script);
    }

    /**
     * Adds the comment for the hydrate method
     *
     * @see addHydrate()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHydrateComment(string &$script): void
    {
        $script .= "
    /**
     * Hydrates (populates) the object variables with values from the database resultset.
     *
     * An offset (0-based \"start column\") is specified so that objects can be hydrated
     * with a subset of the columns in the resultset rows.  This is needed, for example,
     * for results of JOIN queries where the resultset row includes columns from two or
     * more tables.
     *
     * @param array \$row The row returned by DataFetcher->fetch().
     * @param int \$startcol 0-based offset column which indicates which resultset column to start with.
     * @param bool \$rehydrate Whether this object is being re-hydrated from the database.
     * @param string \$indexType The index type of \$row. Mostly DataFetcher->getIndexType().
                                  One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                            TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *
     * @return int next starting column
     * @throws \Propel\Runtime\Exception\PropelException - Any caught Exception will be rewrapped as a PropelException.
     */";
    }

    /**
     * Adds the function declaration for the hydrate method
     *
     * @see addHydrate()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHydrateOpen(string &$script): void
    {
        $script .= "
    public function hydrate(array \$row, int \$startcol = 0, bool \$rehydrate = false, string \$indexType = TableMap::TYPE_NUM): int
    {";
    }

    protected function addHydrateColumn(string &$script, Column $col, string $name): bool
    {
        return false;
    }

    /**
     * Adds the function body for the hydrate method
     *
     * @see addHydrate()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHydrateBody(string &$script): void
    {
        $table = $this->getTable();
        $platform = $this->getPlatform();

        $tableMap = $this->getTableMapClassName();

        $script .= "
        try {";
        $n = 0;
        foreach ($table->getColumns() as $col) {
            if (!$col->isLazyLoad()) {
                $resolveFromRowExpression = $this->getResolveFromRowExpression($col, $n);
                if ($resolveFromRowExpression !== null) {
                    $clo = $col->getLowercasedName();
                    $script .= "

            \$this->$clo = $resolveFromRowExpression;";
                    $n++;

                    continue;
                }

                $indexName = "TableMap::TYPE_NUM == \$indexType ? $n + \$startcol : $tableMap::translateFieldName('{$col->getPhpName()}', TableMap::TYPE_PHPNAME, \$indexType)";

                $script .= "

            \$col = \$row[$indexName];";
                $clo = $col->getLowercasedName();
                if ($this->addHydrateColumn($script, $col, $clo)) {
                    $n++;
                    continue;
                }
                if ($col->getType() === PropelTypes::CLOB_EMU && $this->getPlatform() instanceof OraclePlatform) {
                    // PDO_OCI returns a stream for CLOB objects, while other PDO adapters return a string...
                    $script .= "
            \$this->$clo = stream_get_contents(\$col);";
                } elseif ($col->isLobType() && !$platform->hasStreamBlobImpl()) {
                    $script .= "
            if (null !== \$col) {
                \$this->$clo = fopen('php://memory', 'r+');
                fwrite(\$this->$clo, \$col);
                rewind(\$this->$clo);
            } else {
                \$this->$clo = null;
            }";
                } elseif ($col->isTemporalType()) {
                    $dateTimeClass = $this->getDateTimeClass($col);
                    $handleMysqlDate = false;
                    if ($this->getPlatform() instanceof MysqlPlatform) {
                        if (in_array($col->getType(), [PropelTypes::TIMESTAMP, PropelTypes::DATETIME], true)) {
                            $handleMysqlDate = true;
                            $mysqlInvalidDateString = '0000-00-00 00:00:00';
                        } elseif ($col->getType() === PropelTypes::DATE) {
                            $handleMysqlDate = true;
                            $mysqlInvalidDateString = '0000-00-00';
                        }
                        // 00:00:00 is a valid time, so no need to check for that.
                    }
                    if ($handleMysqlDate) {
                        $script .= "
            if (\$col === '$mysqlInvalidDateString') {
                \$col = null;
            }";
                    }
                    $script .= "
            \$this->$clo = (null !== \$col) ? PropelDateTime::newInstance(\$col, null, '$dateTimeClass') : null;";
                } elseif ($col->requiresUidBinaryConversion()) {
                    $script .= "
            if (is_resource(\$col)) {
                \$col = stream_get_contents(\$col);
            }
            \$this->$clo = (\$col !== null && \$col !== '') ? UuidConverter::binToUid(\$col) : null;";
                } elseif ($col->requiresUidStringConversion()) {
                    $script .= "
            \$this->$clo = (\$col !== null && \$col !== '') ? UuidConverter::stringToUid(\$col) : null;";
                } elseif ($col->isUuidBinaryType() || $col->requiresMysqlUuidBinaryConversion()) {
                    $uuidSwapFlag = $this->getUuidSwapFlagLiteral($col);
                    $script .= "
            if (is_resource(\$col)) {
                \$col = stream_get_contents(\$col);
            }
            \$this->$clo = (\$col) ? UuidConverter::binToUuid(\$col, $uuidSwapFlag) : null;";
                } elseif ($col->isNativeArrayType()) {
                    $this->declareClass('\Propel\Common\Util\PgsqlArrayCodec');
                    $elementType = var_export($col->getNativeArrayElementType(), true);
                    $script .= "
            \$this->$clo = PgsqlArrayCodec::decode(\$col, $elementType);";
                } elseif ($col->isSetType()) {
                    // has to be checked before isPhpPrimitiveType(), as the PHP type of a set column
                    // is `int`, while the attribute holds the value bitmask as a string.
                    $cloConverted = $clo . '_converted';
                    $script .= "
            \$this->$clo = \$col;
            \$this->$cloConverted = null;";
                } elseif ($col->isPhpPrimitiveType()) {
                    $script .= "
            \$this->$clo = (null !== \$col) ? (" . $col->getPhpType() . ') $col : null;';
                } elseif ($col->getType() === PropelTypes::OBJECT) {
                    $script .= "
            \$this->$clo = \$col;";
                } elseif ($col->getType() === PropelTypes::PHP_ARRAY) {
                    $cloUnserialized = $clo . '_unserialized';
                    $script .= "
            \$this->$clo = \$col;
            \$this->$cloUnserialized = null;";
                } elseif ($col->isUidBinaryType()) {
                    $script .= "
            if (is_resource(\$col)) {
                \$col = stream_get_contents(\$col);
            }
            \$this->$clo = (null !== \$col && \$col !== '') ? UuidConverter::binToUid(\$col) : null;";
                } elseif ($col->requiresUidStringConversion()) {
                    $script .= "
            \$this->$clo = (null !== \$col && \$col !== '') ? UuidConverter::stringToUid(\$col) : null;";
                } elseif ($col->isPhpObjectType()) {
                    $script .= "
            \$this->$clo = (null !== \$col) ? new " . $col->getPhpType() . '($col) : null;';
                } else {
                    $script .= "
            \$this->$clo = \$col;";
                }
                $n++;
            }
        }

        if ($this->getBuildProperty('generator.objectModel.addSaveMethod')) {
            $script .= "

            \$this->resetModified();";
        }

        $script .= "
            \$this->resetProjectionState();
            \$this->setNew(false);

            if (\$rehydrate) {
                \$this->ensureConsistency();
            }
";

        $this->applyBehaviorModifier('postHydrate', $script, '            ');

        $script .= "
            return \$startcol + $n; // $n = " . $this->getTableMapClass() . "::NUM_HYDRATE_COLUMNS.

        } catch (Exception \$e) {
            throw new PropelException(sprintf('Error populating %s object', " . var_export($this->getStubObjectBuilder()->getClassName(), true) . "), 0, \$e);
        }";
    }

    /**
     * Returns a resolveFromRow() expression for simple hydration scenarios.
     *
     * @param Column $column
     * @param int $position
     *
     * @return string|null
     */
    protected function getResolveFromRowExpression(Column $column, int $position): ?string
    {
        if (
            $column->getType() === PropelTypes::CLOB_EMU
            || $column->isLobType()
            || $column->isTemporalType()
            || $column->isUidType()
            || $column->isUuidBinaryType()
            || $column->getType() === PropelTypes::PHP_ARRAY
            || $column->isNativeArrayType()
            || $column->isSetType()
        ) {
            return null;
        }

        $castToNull = $this->castToNull && !$column->isNotNull();

        if ($column->isPhpPrimitiveType()) {
            $transformer = $castToNull
                ? "fn (\$v) => (" . $column->getPhpType() . ") \$v"
                : "fn (\$v) => null !== \$v ? (" . $column->getPhpType() . ") \$v : null";

            return "\$this->resolveFromRow(\$row, $position, \$startcol, \$indexType, $transformer)";
        }

        if ($column->isPhpObjectType()) {
            $transformer = $castToNull
                ? "fn (\$v) => new " . $column->getPhpType() . "(\$v)"
                : "fn (\$v) => null !== \$v ? new " . $column->getPhpType() . "(\$v) : null";

            return "\$this->resolveFromRow(\$row, $position, \$startcol, \$indexType, $transformer)";
        }

        return "\$this->resolveFromRow(\$row, $position, \$startcol, \$indexType)";
    }

    /**
     * Adds the function close for the hydrate method
     *
     * @see addHydrate()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHydrateClose(string &$script): void
    {
        $script .= "
    }
";
    }

    /**
     * Adds the buildPkeyCriteria method
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildPkeyCriteria(string &$script): void
    {
        $this->declareClass('Propel\\Runtime\\Exception\\LogicException');

        $this->addBuildPkeyCriteriaComment($script);
        $this->addBuildPkeyCriteriaOpen($script);
        $this->addBuildPkeyCriteriaBody($script);
        $this->addBuildPkeyCriteriaClose($script);
    }

    /**
     * Adds the comment for the buildPkeyCriteria method
     *
     * @see addBuildPkeyCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildPkeyCriteriaComment(string &$script): void
    {
        $script .= "
    /**
     * Builds a Criteria object containing the primary key for this object.
     *
     * Unlike buildCriteria() this method includes the primary key values regardless
     * of whether they have been modified.
     *
     * @throws LogicException if no primary key is defined
     *
     * @return \Propel\Runtime\ActiveQuery\Criteria The Criteria object containing value(s) for primary key(s).
     */";
    }

    /**
     * Adds the function declaration for the buildPkeyCriteria method
     *
     * @see addBuildPkeyCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildPkeyCriteriaOpen(string &$script): void
    {
        $script .= "
    public function buildPkeyCriteria(): Criteria
    {";
    }

    /**
     * Adds the function body for the buildPkeyCriteria method
     *
     * @see addBuildPkeyCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildPkeyCriteriaBody(string &$script): void
    {
        $table = $this->getTable();

        if (!$table->getPrimaryKey()) {
            $script .= "
        throw new LogicException('The {$this->getObjectName()} object has no primary key');";

            return;
        }

        $script .= "
        \$criteria = " . $this->getQueryClassName() . '::create();';
        foreach ($table->getPrimaryKey() as $col) {
            $clo = $col->getLowercasedName();
            $script .= "
        \$criteria->add(" . $this->getColumnConstant($col) . ", \$this->$clo);";
        }

        $this->addBuildPkeyCriteriaPartitionKey($script);
    }

    /**
     * Restricts the criteria to the partition that holds this row.
     *
     * On a partitioned table the model key stays single-column while the physical key is the pair
     * (model key, partition key). A criteria carrying only the model key gives the database
     * nothing to prune with, so every UPDATE, DELETE and reload opens, locks and probes every
     * partition — cost that grows with the partition count instead of staying constant.
     *
     * The partition key is only added when it holds a value and is not pending modification. A
     * modified partition key no longer identifies the stored row — and on PostgreSQL updating it
     * moves the row to another partition — so there the unpruned key is the correct criteria.
     *
     * @see addBuildPkeyCriteriaBody()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildPkeyCriteriaPartitionKey(string &$script): void
    {
        $table = $this->getTable();

        if (!$table->isPartitioned()) {
            return;
        }

        foreach ($table->getPartitionKeyColumns() as $col) {
            if ($col->isPrimaryKey()) {
                continue;
            }

            $clo = $col->getLowercasedName();
            $constant = $this->getColumnConstant($col);

            $script .= "

        // Partition pruning: the physical key of this table is (primary key, {$col->getName()}).
        if (\$this->$clo !== null && !\$this->isColumnModified($constant)) {
            \$criteria->add($constant, \$this->$clo);
        }";
        }
    }

    /**
     * Adds the function close for the buildPkeyCriteria method
     *
     * @see addBuildPkeyCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildPkeyCriteriaClose(string &$script): void
    {
        $script .= "

        return \$criteria;
    }
";
    }

    /**
     * Adds the buildCriteria method
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildCriteria(string &$script): void
    {
        $this->addBuildCriteriaComment($script);
        $this->addBuildCriteriaOpen($script);
        $this->addBuildCriteriaBody($script);
        $this->addBuildCriteriaClose($script);
    }

    /**
     * Adds comment for the buildCriteria method
     *
     * @see addBuildCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildCriteriaComment(string &$script): void
    {
        $script .= "
    /**
     * Build a Criteria object containing the values of all modified columns in this object.
     *
     * @return \Propel\Runtime\ActiveQuery\Criteria The Criteria object containing all modified values.
     */";
    }

    /**
     * Adds the function declaration of the buildCriteria method
     *
     * @see addBuildCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildCriteriaOpen(string &$script): void
    {
        $script .= "
    public function buildCriteria(): Criteria
    {";
    }

    /**
     * Adds the function body of the buildCriteria method
     *
     * @see addBuildCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildCriteriaBody(string &$script): void
    {
        $script .= "
        \$criteria = new Criteria(" . $this->getTableMapClass() . "::DATABASE_NAME);
        foreach (" . $this->getTableMapClass() . "::ALL_COLUMNS as \$columnConstant) {
            if (\$this->isColumnModified(\$columnConstant)) {
                \$propertyName = " . $this->getTableMapClass() . "::getPropertyName(\$columnConstant);
                \$criteria->add(\$columnConstant, \$this->normalizeValueForPersistence(\$this->{\$propertyName}));
            }
        }";
    }

    /**
     * Adds the function close of the buildCriteria method
     *
     * @see addBuildCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildCriteriaClose(string &$script): void
    {
        $script .= "

        return \$criteria;
    }
";
    }

    protected function addToArrayMutations(string &$script): void
    {
    }

    /**
     * Adds the toArray method
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addToArray(string &$script): void
    {
        $fks = $this->getTable()->getForeignKeys();
        $referrers = $this->getTable()->getReferrersForCodeGeneration();
        $hasFks = count($fks) > 0 || count($referrers) > 0;
        $objectClassName = $this->getUnqualifiedClassName();
        $defaultKeyType = $this->getDefaultKeyType();
        $script .= "
    /**
     * Exports the object as an array.
     *
     * You can specify the key type of the array by passing one of the class
     * type constants.
     *
     * @param string \$keyType (optional) One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME,
     *                    TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *                    Defaults to TableMap::$defaultKeyType.
     * @param bool \$includeLazyLoadColumns (optional) Whether to include lazy loaded columns. Defaults to TRUE.
     * @param array \$alreadyDumpedObjects List of objects to skip to avoid recursion";
        $script .= "
     * @param bool \$includeForeignObjects (optional) Whether to include hydrated related objects. Default to FALSE.";
        $script .= "
     *
     * @return array An associative array containing the field names (as keys) and field values
     */
    public function toArray(string \$keyType = TableMap::$defaultKeyType, bool \$includeLazyLoadColumns = true, array \$alreadyDumpedObjects = [], bool \$includeForeignObjects = false): array
    {
        if (isset(\$alreadyDumpedObjects['$objectClassName'][\$this->hashCode()])) {
            return ['*RECURSION*'];
        }
        \$alreadyDumpedObjects['$objectClassName'][\$this->hashCode()] = true;
        \$keys = " . $this->getTableMapClassName() . "::getFieldNames(\$keyType);
        \$result = [];";
        foreach ($this->getTable()->getColumns() as $num => $col) {
            $script .= "
        if (!\$this->isPartial() || \$this->isColumnLoaded('" . $col->getPhpName() . "')) {";
            if ($col->isLazyLoad()) {
                $script .= "
            \$result[\$keys[$num]] = (\$includeLazyLoadColumns) ? \$this->get" . $col->getPhpName() . '() : null;';
            } else {
                $script .= "
            \$result[\$keys[$num]] = \$this->get" . $col->getPhpName() . '();';
            }
            $script .= "
        }";
        }

        foreach ($this->getTable()->getColumns() as $num => $col) {
            if ($col->isTemporalType()) {
                $script .= "
        if (isset(\$result[\$keys[$num]]) && \$result[\$keys[$num]] instanceof \DateTimeInterface) {
            \$result[\$keys[$num]] = \$result[\$keys[$num]]->format('" . $this->getTemporalFormatter($col) . "');
        }
        ";
            }
        }

        $this->addToArrayMutations($script);

        $script .= "
        \$virtualColumns = \$this->virtualColumns;
        foreach (\$virtualColumns as \$key => \$virtualColumn) {
            \$result[\$key] = \$virtualColumn;
        }
        ";
        if ($hasFks) {
            $script .= "
        if (\$includeForeignObjects) {";
            foreach ($fks as $fk) {
                $script .= "
            if (null !== \$this->" . $this->getFKVarName($fk) . ") {
                {$this->addToArrayKeyLookUp($fk->getPhpName(), $fk->getForeignTable(), false)}
                \$result[\$key] = \$this->" . $this->getFKVarName($fk) . "->toArray(\$keyType, \$includeLazyLoadColumns,  \$alreadyDumpedObjects, true);
            }";
            }
            foreach ($referrers as $fk) {
                if ($fk->isLocalPrimaryKey()) {
                    $script .= "
            if (null !== \$this->" . $this->getPKRefFKVarName($fk) . ") {
                {$this->addToArrayKeyLookUp($fk->getRefPhpName(), $fk->getTable(), false)}
                \$result[\$key] = \$this->" . $this->getPKRefFKVarName($fk) . "->toArray(\$keyType, \$includeLazyLoadColumns, \$alreadyDumpedObjects, true);
            }";
                } else {
                    $script .= "
            if (null !== \$this->" . $this->getRefFKCollVarName($fk) . ") {
                {$this->addToArrayKeyLookUp($fk->getRefPhpName(), $fk->getTable(), true)}
                \$result[\$key] = \$this->" . $this->getRefFKCollVarName($fk) . "->toArray(null, false, \$keyType, \$includeLazyLoadColumns, \$alreadyDumpedObjects);
            }";
                }
            }
            $script .= "
        }";
        }
        $script .= "

        return \$result;
    }
";
    }

    // addToArray()

    /**
     * Adds the switch-statement for looking up the array-key name for toArray
     *
     * @see toArray
     *
     * @param string|null $phpName
     * @param Table $table
     * @param bool $plural
     *
     * @return string
     */
    protected function addToArrayKeyLookUp(?string $phpName, Table $table, bool $plural): string
    {
        if (!$phpName) {
            $phpName = $table->getPhpName();
        }

        $camelCaseName = $table->getCamelCaseName();
        $fieldName = $table->getName();

        if ($plural) {
            $phpName = $this->getPluralizer()->getPluralForm($phpName);
            $camelCaseName = $this->getPluralizer()->getPluralForm($camelCaseName);
            $fieldName = $this->getPluralizer()->getPluralForm($fieldName);
        }

        return "
                \$key = match (\$keyType) {
                    TableMap::TYPE_CAMELNAME => '$camelCaseName',
                    TableMap::TYPE_FIELDNAME => '$fieldName',
                    default => '$phpName',
                };
        ";
    }

    /**
     * Adds the getByName method
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetByName(string &$script): void
    {
        $this->addGetByNameComment($script);
        $this->addGetByNameOpen($script);
        $this->addGetByNameBody($script);
        $this->addGetByNameClose($script);
    }

    /**
     * Adds the comment for the getByName method
     *
     * @see addGetByName
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetByNameComment(string &$script): void
    {
        $defaultKeyType = $this->getDefaultKeyType();
        $script .= "
    /**
     * Retrieves a field from the object by name passed in as a string.
     *
     * @param string \$name name
     * @param string \$type The type of fieldname the \$name is of:
     *                     one of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                     TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *                     Defaults to TableMap::$defaultKeyType.
     * @return mixed Value of field.
     */";
    }

    /**
     * Adds the function declaration for the getByName method
     *
     * @see addGetByName
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetByNameOpen(string &$script): void
    {
        $defaultKeyType = $this->getDefaultKeyType();
        $script .= "
    public function getByName(string \$name, string \$type = TableMap::$defaultKeyType)
    {";
    }

    /**
     * Adds the function body for the getByName method
     *
     * @see addGetByName
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetByNameBody(string &$script): void
    {
        $script .= "
        \$pos = " . $this->getTableMapClassName() . "::translateFieldName(\$name, \$type, TableMap::TYPE_NUM);
        \$field = \$this->getByPosition(\$pos);";
    }

    /**
     * Adds the function close for the getByName method
     *
     * @see addGetByName
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetByNameClose(string &$script): void
    {
        $script .= "

        return \$field;
    }
";
    }

    /**
     * Adds the getByPosition method
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetByPosition(string &$script): void
    {
        $this->addGetByPositionComment($script);
        $this->addGetByPositionOpen($script);
        $this->addGetByPositionBody($script);
        $this->addGetByPositionClose($script);
    }

    /**
     * Adds comment for the getByPosition method
     *
     * @see addGetByPosition
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetByPositionComment(string &$script): void
    {
        $script .= "
    /**
     * Retrieves a field from the object by Position as specified in the xml schema.
     * Zero-based.
     *
     * @param int \$pos Position in XML schema
     * @return mixed Value of field at \$pos
     */";
    }

    /**
     * Adds the function declaration for the getByPosition method
     *
     * @see addGetByPosition
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetByPositionOpen(string &$script): void
    {
        $script .= "
    public function getByPosition(int \$pos)
    {";
    }

    /**
     * Adds the function body for the getByPosition method
     *
     * @see addGetByPosition
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetByPositionBody(string &$script): void
    {
        $table = $this->getTable();
        $script .= "
        return match (\$pos) {";
        $i = 0;
        foreach ($table->getColumns() as $col) {
            $cfc = $col->getPhpName();
            $script .= "
            $i => \$this->get$cfc(),";
            $i++;
        } /* foreach */
        $script .= "
            default => null
        };";
    }

    /**
     * Adds the function close for the getByPosition method
     *
     * @see addGetByPosition
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetByPositionClose(string &$script): void
    {
        $script .= "
    }
";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addSetByName(string &$script): void
    {
        $defaultKeyType = $this->getDefaultKeyType();
        $script .= "
    /**
     * Sets a field from the object by name passed in as a string.
     *
     * @param string \$name
     * @param mixed \$value field value
     * @param string \$type The type of fieldname the \$name is of:
     *                one of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *                Defaults to TableMap::$defaultKeyType.
     * @return \$this
     */
    public function setByName(string \$name, \$value, string \$type = TableMap::$defaultKeyType)
    {
        \$pos = " . $this->getTableMapClassName() . "::translateFieldName(\$name, \$type, TableMap::TYPE_NUM);

        \$this->setByPosition(\$pos, \$value);

        return \$this;
    }
";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addSetByPosition(string &$script): void
    {
        $table = $this->getTable();
        $script .= "
    /**
     * Sets a field from the object by Position as specified in the xml schema.
     * Zero-based.
     *
     * @param int \$pos position in xml schema
     * @param mixed \$value field value
     * @return \$this
     */
    public function setByPosition(int \$pos, \$value)
    {
        match (\$pos) {";
        $i = 0;
        foreach ($table->getColumns() as $col) {
            $cfc = $col->getPhpName();

            $hasValuePreparation = $col->getType() === PropelTypes::ENUM || $col->isSetType() || $col->getType() === PropelTypes::PHP_ARRAY;

            if ($hasValuePreparation) {
                $script .= "
            $i => (function () use (\$value) {";
            } else {
                $script .= "
            $i => \$this->set$cfc(\$value),";
                $i++;

                continue;
            }

            if ($col->getType() === PropelTypes::ENUM) {
                $script .= "
                \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->getColumnConstant($col) . ");
                if (isset(\$valueSet[\$value])) {
                    \$value = \$valueSet[\$value];
                }";
            } elseif ($col->isSetType()) {
                $this->declareClasses(
                    'Propel\Common\Util\SetColumnConverter',
                    'Propel\Common\Exception\SetColumnConverterException',
                );
                $script .= "
                \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->getColumnConstant($col) . ");
                try {
                    \$value = SetColumnConverter::convertIntToArray(\$value, \$valueSet);
                } catch (SetColumnConverterException \$e) {
                    throw new PropelException('Unknown stored set key: ' . \$e->getValue(), \$e->getCode(), \$e);
                }
                ";
            } elseif ($col->getType() === PropelTypes::PHP_ARRAY) {
                $script .= "
                if (!is_array(\$value)) {
                    \$v = trim(substr(\$value, 2, -2));
                    \$value = \$v ? explode(' | ', \$v) : [];
                }";
            }

            $script .= "
                return \$this->set$cfc(\$value);
            })(),";
            $i++;
        } /* foreach */
        $script .= "
            default => null
        };

        return \$this;
    }
";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addFromArray(string &$script): void
    {
        $defaultKeyType = $this->getDefaultKeyType();
        $table = $this->getTable();
        $script .= "
    /**
     * Populates the object using an array.
     *
     * This is particularly useful when populating an object from one of the
     * request arrays (e.g. \$_POST).  This method goes through the column
     * names, checking to see whether a matching key exists in populated
     * array. If so the setByName() method is called for that column.
     *
     * You can specify the key type of the array by additionally passing one
     * of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME,
     * TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     * The default key type is the column's TableMap::$defaultKeyType.
     *
     * @param array \$arr An array to populate the object from.
     * @param string \$keyType The type of keys the array uses.
     * @return \$this
     */
    public function fromArray(array \$arr, string \$keyType = TableMap::$defaultKeyType)
    {
        \$keys = " . $this->getTableMapClassName() . "::getFieldNames(\$keyType);
";
        foreach ($table->getColumns() as $num => $col) {
            $script .= "
        if (array_key_exists(\$keys[$num], \$arr)) {
            \$this->setByPosition($num, \$arr[\$keys[$num]]);
        }";
        } /* foreach */
        $script .= "

        return \$this;
    }
";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addImportFrom(string &$script): void
    {
        $defaultKeyType = $this->getDefaultKeyType();
        $script .= "
     /**
     * Populate the current object from a string, using a given parser format
     * <code>
     * \$book = new Book();
     * \$book->importFrom('JSON', '{\"Id\":9012,\"Title\":\"Don Juan\",\"ISBN\":\"0140422161\",\"Price\":12.99,\"PublisherId\":1234,\"AuthorId\":5678}');
     * </code>
     *
     * You can specify the key type of the array by additionally passing one
     * of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME,
     * TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     * The default key type is the column's TableMap::$defaultKeyType.
     *
     * @param mixed \$parser A AbstractParser instance,
     *                       or a format name ('XML', 'YAML', 'JSON', 'CSV')
     * @param string \$data The source data to import from
     * @param string \$keyType The type of keys the array uses.
     *
     * @return \$this The current object, for fluid interface
     */
    public function importFrom(\$parser, string \$data, string \$keyType = TableMap::$defaultKeyType)
    {
        if (!\$parser instanceof AbstractParser) {
            \$parser = AbstractParser::getParser(\$parser);
        }

        \$this->fromArray(\$parser->toArray(\$data), \$keyType);

        return \$this;
    }
";
    }

    /**
     * Adds a delete() method to remove the object form the datastore.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addDelete(string &$script): void
    {
        $this->addDeleteComment($script);
        $this->addDeleteOpen($script);
        $this->addDeleteBody($script);
        $this->addDeleteClose($script);
    }

    /**
     * Adds the comment for the delete function
     *
     * @see addDelete()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addDeleteComment(string &$script): void
    {
        $className = $this->getUnqualifiedClassName();
        $script .= "
    /**
     * Removes this object from datastore and sets delete attribute.
     *
     * @param ?ConnectionInterface \$con
     * @return void
     * @throws \Propel\Runtime\Exception\PropelException
     * @see $className::setDeleted()
     * @see $className::isDeleted()
     */";
    }

    /**
     * Adds the function declaration for the delete function
     *
     * @see addDelete()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addDeleteOpen(string &$script): void
    {
        $script .= "
    public function delete(?ConnectionInterface \$con = null): void
    {";
    }

    /**
     * Adds the function body for the delete function
     *
     * @see addDelete()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addDeleteBody(string &$script): void
    {
        $script .= "
        if (\$this->isDeleted()) {
            throw new PropelException(\"This object has already been deleted.\");
        }

        if (\$con === null) {
            \$con = Propel::getServiceContainer()->getWriteConnection(" . $this->getTableMapClass() . "::DATABASE_NAME);
        }

        \$con->transaction(function () use (\$con) {
            \$deleteQuery = " . $this->getQueryClassName() . "::create()
                ->filterByPrimaryKey(\$this->getPrimaryKey());";
        if ($this->getBuildProperty('generator.objectModel.addHooks')) {
            $script .= "
            \$ret = \$this->preDelete(\$con);";
            // apply behaviors
            $this->applyBehaviorModifier('preDelete', $script, '            ');
            $script .= "
            if (\$ret) {
                \$deleteQuery->delete(\$con);
                \$this->postDelete(\$con);";
            // apply behaviors
            $this->applyBehaviorModifier('postDelete', $script, '                ');
            $script .= "
                \$this->setDeleted(true);
            }";
        } else {
            // apply behaviors
            $this->applyBehaviorModifier('preDelete', $script, '            ');
            $script .= "
            \$deleteQuery->delete(\$con);";
            // apply behaviors
            $this->applyBehaviorModifier('postDelete', $script, '            ');
            $script .= "
            \$this->setDeleted(true);";
        }

        $script .= "
        });";
    }

    /**
     * Adds the function close for the delete function
     *
     * @see addDelete()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addDeleteClose(string &$script): void
    {
        $script .= "
    }
";
    }

    /**
     * Adds a reload() method to re-fetch the data for this object from the database.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addReload(string &$script): void
    {
        $table = $this->getTable();
        $script .= "
    /**
     * Reloads this object from datastore based on primary key and (optionally) resets all associated objects.
     *
     * This will only work if the object has been saved and has a valid primary key set.
     *
     * @param bool \$deep (optional) Whether to also de-associated any related objects.
     * @param ?ConnectionInterface \$con (optional) The ConnectionInterface connection to use.
     * @return void
     * @throws \Propel\Runtime\Exception\PropelException - if this object is deleted, unsaved or doesn't have pk match in db
     */
    public function reload(bool \$deep = false, ?ConnectionInterface \$con = null): void
    {
        if (\$this->isDeleted()) {
            throw new PropelException(\"Cannot reload a deleted object.\");
        }

        if (\$this->isNew()) {
            throw new PropelException(\"Cannot reload an unsaved object.\");
        }

        if (\$con === null) {
            \$con = Propel::getServiceContainer()->getReadConnection(" . $this->getTableMapClass() . "::DATABASE_NAME);
        }

        // We don't need to alter the object instance pool; we're just modifying this instance
        // already in the pool.

        /** @var \Propel\Runtime\DataFetcher\DataFetcherInterface */
        \$dataFetcher = " . $this->getQueryClassName() . "::create(null, \$this->buildPkeyCriteria())->setFormatter(ModelCriteria::FORMAT_STATEMENT)->find(\$con);

        \$row = \$dataFetcher->fetch();
        \$dataFetcher->close();

        if (!\$row || !is_array(\$row)) {
            throw new PropelException('Cannot find matching row in the database to reload object values.');
        }

        \$this->hydrate(\$row, 0, true, \$dataFetcher->getIndexType()); // rehydrate
";

        // support for lazy load columns
        foreach ($table->getColumns() as $col) {
            if ($col->isLazyLoad()) {
                $clo = $col->getLowercasedName();
                $script .= "
        // Reset the $clo lazy-load column
        \$this->" . $clo . " = null;
        \$this->" . $clo . "_isLoaded = false;
";
            }
        }

        $script .= "
        if (\$deep) {  // also de-associate any related objects?
";

        foreach ($table->getForeignKeys() as $fk) {
            $varName = $this->getFKVarName($fk);
            $script .= "
            \$this->" . $varName . ' = null;';
        }

        foreach ($table->getReferrersForCodeGeneration() as $refFK) {
            if ($refFK->isLocalPrimaryKey()) {
                $script .= "
            \$this->" . $this->getPKRefFKVarName($refFK) . " = null;
";
            } else {
                $script .= "
            \$this->" . $this->getRefFKCollVarName($refFK) . " = null;
";
            }
        }

        foreach ($table->getCrossFks() as $crossFKs) {
            $script .= "
            \$this->" . $this->getCrossFKsVarName($crossFKs) . ' = null;';
        }

        $script .= "
        } // if (deep)
    }
";
    }

    // addReload()

    /**
     * Adds the methods related to refreshing, saving and deleting the object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addManipulationMethods(string &$script): void
    {
        $this->addReload($script);
        $this->addDelete($script);
        $this->addSave($script);
        $this->addDoSave($script);
        $script .= $this->addDoInsert();
        $script .= $this->addDoUpdate();
    }

    protected function addHashCode(string &$script): void
    {
        $script .= "
    /**
     * If the primary key is not null, return the hashcode of the
     * primary key. Otherwise, return the hash code of the object.
     *
     * @return int|string Hashcode
     */
    public function hashCode()
    {";

        $pks = $this->getTable()->getPrimaryKey();
        if (count($pks) === 1) {
            $script .= "
        \$primaryKey = \$this->getPrimaryKey();
        if (\$this->validatePrimaryKey(\$primaryKey, " . $this->getPrimaryKeyUnsetValue($pks[0]) . ")) {
            return \$this->hashCodeFromValue(\$primaryKey);
        }";
        } elseif ($pks) {
            $unsetValues = [];
            foreach ($pks as $pk) {
                $unsetValues[] = $this->getPrimaryKeyUnsetValue($pk);
            }
            $script .= "
        \$primaryKey = \$this->getPrimaryKey();
        if (\$this->validatePrimaryKeys(\$primaryKey, [" . implode(', ', $unsetValues) . "])) {
            return \$this->hashCodeFromValue(\$primaryKey);
        }";
        }

        /** @var array<ForeignKey> $primaryKeyFKs */
        $primaryKeyFKs = [];
        $foreignKeyPKCount = 0;
        foreach ($this->getTable()->getForeignKeys() as $foreignKey) {
            $foreignKeyPKCount += count($foreignKey->getLocalPrimaryKeys());
            if ($foreignKey->getLocalPrimaryKeys()) {
                $primaryKeyFKs[] = $foreignKey;
            }
        }


        if ($foreignKeyPKCount) {
            $script .= "

        \$primaryKeyForeignHashes = [];
";
            foreach ($primaryKeyFKs as $foreignKey) {
                $name = '$this->a' . $this->getFKPhpNameAffix($foreignKey);
                $script .= "
        // relation {$foreignKey->getName()} to table {$foreignKey->getForeignTableName()}
        if (!$name) {
            return spl_object_hash(\$this);
        }

        \$primaryKeyForeignHashes[] = spl_object_hash($name);
";
            }
            $script .= "
        return \$this->hashCodeFromValue(\$primaryKeyForeignHashes);";
        } else {
            $script .= "

        return spl_object_hash(\$this);";
        }

        $script .= "    
    }
        ";
    }

    /**
     * Adds the correct getPrimaryKey() method for this object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetPrimaryKey(string &$script): void
    {
        $pkeys = $this->getTable()->getPrimaryKey();
        if (count($pkeys) == 1) {
            $this->addGetPrimaryKeySinglePK($script);
        } elseif (count($pkeys) > 1) {
            $this->addGetPrimaryKeyMultiPK($script);
        } else {
            $script .= "
    /**
     * Returns the primary key for this object (row).
     * @return null
     */
    public function getPrimaryKey()
    {
        return null;
    }
";
        }
    }

    /**
     * Adds the getPrimaryKey() method for tables that contain a single-column primary key.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetPrimaryKeySinglePK(string &$script): void
    {
        $table = $this->getTable();
        $pkeys = $table->getPrimaryKey();
        $cptype = $pkeys[0]->getPhpType();
        if ($this->isNullableInGeneratedObjectApi($pkeys[0])) {
            $cptype .= '|null';
        }

        $script .= "
    /**
     * Returns the primary key for this object (row).
     * @return $cptype
     */
    public function getPrimaryKey()
    {
        return \$this->get" . $pkeys[0]->getPhpName() . "();
    }
";
    }

    /**
     * Adds the setPrimaryKey() method for tables that contain a multi-column primary key.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetPrimaryKeyMultiPK(string &$script): void
    {
        $script .= "
    /**
     * Returns the composite primary key for this object.
     * The array elements will be in same order as specified in XML.
     * @return array
     */
    public function getPrimaryKey()
    {
        \$pks = [];";
        $i = 0;
        foreach ($this->getTable()->getPrimaryKey() as $pk) {
            $script .= "
        \$pks[$i] = \$this->get" . $pk->getPhpName() . '();';
            $i++;
        } /* foreach */
        $script .= "

        return \$pks;
    }
";
    }

    /**
     * Adds the getPrimaryKey() method for objects that have no primary key.
     * This "feature" is deprecated, since the getPrimaryKey() method is not required
     * by the Persistent interface (or used by the templates). Hence, this method is also
     * deprecated.
     *
     * @deprecated Not needed anymore.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetPrimaryKeyNoPK(string &$script): void
    {
        $script .= "
    /**
     * Returns NULL since this table doesn't have a primary key.
     * This method exists only for BC and is deprecated!
     * @return null
     */
    public function getPrimaryKey()
    {
        return null;
    }
";
    }

    /**
     * Adds the correct setPrimaryKey() method for this object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSetPrimaryKey(string &$script): void
    {
        $pkeys = $this->getTable()->getPrimaryKey();
        if (count($pkeys) == 1) {
            $this->addSetPrimaryKeySinglePK($script);
        } elseif (count($pkeys) > 1) {
            $this->addSetPrimaryKeyMultiPK($script);
        }
    }

    /**
     * Adds the setPrimaryKey() method for tables that contain a single-column primary key.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSetPrimaryKeySinglePK(string &$script): void
    {
        $pkeys = $this->getTable()->getPrimaryKey();
        $col = $pkeys[0];
        $clo = $col->getLowercasedName();
        $ctype = $col->getPhpType();
        $docType = $ctype;
        $unsetValue = $this->getPrimaryKeyUnsetValue($col);
        $defaultValue = ' = ' . $unsetValue;
        if ($unsetValue === 'null') {
            $docType .= '|null';
            $ctype = "?$ctype";
        }

        $script .= "
    /**
     * Generic method to set the primary key ($clo column).
     *
     * @param $docType \$key Primary key.
     * @return void
     */
    public function setPrimaryKey($ctype \$key$defaultValue): void
    {
        \$this->set" . $col->getPhpName() . "(\$key);
    }
";
    }

    /**
     * Adds the setPrimaryKey() method for tables that contain a multi-column primary key.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSetPrimaryKeyMultiPK(string &$script): void
    {
        $script .= "
    /**
     * Set the [composite] primary key.
     *
     * @param array \$keys The elements of the composite key (order must match the order in XML file).
     * @return void
     */
    public function setPrimaryKey(array \$keys): void
    {";
        $i = 0;
        foreach ($this->getTable()->getPrimaryKey() as $pk) {
            $script .= "
        \$this->set" . $pk->getPhpName() . "(\$keys[$i]);";
            $i++;
        }
        $script .= "
    }
";
    }

    /**
     * Adds the isPrimaryKeyNull() method
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addIsPrimaryKeyNull(string &$script): void
    {
        $table = $this->getTable();
        $pkeys = $table->getPrimaryKey();

        $script .= "
    /**
     * Returns true if the primary key for this object is null.
     *
     * @return bool
     */
    public function isPrimaryKeyNull(): bool
    {";
        if (count($pkeys) === 1) {
            $script .= "
        return {$this->getDefaultValueForColumn($pkeys[0])} === \$this->get" . $pkeys[0]->getPhpName() . '();';
        } elseif ($pkeys) {
            $tests = [];
            foreach ($pkeys as $ind => $pkey) {
                $tests[] = "({$this->getDefaultValueForColumn($pkeys[$ind])} === \$this->get" . $pkey->getPhpName() . '())';
            }
            $script .= "
        return " . implode(' && ', $tests) . ';';
        } else {
            $script .= "
        return false;";
        }
        $script .= "
    }
";
    }

    /**
     * Constructs variable name for fkey-related objects.
     *
     * @param ForeignKey $fk
     *
     * @return string
     */
    public function getFKVarName(ForeignKey $fk): string
    {
        return 'a' . $this->getFKPhpNameAffix($fk, false);
    }

    /**
     * Constructs variable name for objects which referencing current table by specified foreign key.
     *
     * @param ForeignKey $fk
     *
     * @return string
     */
    public function getRefFKCollVarName(ForeignKey $fk): string
    {
        return 'coll' . $this->getRefFKPhpNameAffix($fk, true);
    }

    /**
     * Constructs variable name for single object which references current table by specified foreign key
     * which is ALSO a primary key (hence one-to-one relationship).
     *
     * @param ForeignKey $fk
     *
     * @return string
     */
    public function getPKRefFKVarName(ForeignKey $fk): string
    {
        return 'single' . $this->getRefFKPhpNameAffix($fk, false);
    }

    /**
     * Adds the methods that get & set objects related by foreign key to the current object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addFKMethods(string &$script): void
    {
        foreach ($this->getTable()->getForeignKeys() as $fk) {
            $this->declareClassFromBuilder($this->getNewStubObjectBuilder($fk->getForeignTable()), 'Child');
            $this->declareClassFromBuilder($this->getNewStubQueryBuilder($fk->getForeignTable()));
            $this->addFKMutator($script, $fk);
            $this->addFKRemover($script, $fk);
            $this->addFKHasValue($script, $fk);
            $this->addFKAccessor($script, $fk);
        }
    }

    /**
     * Adds the class attributes that are needed to store fkey related objects.
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $fk
     *
     * @return void
     */
    protected function addFKAttributes(string &$script, ForeignKey $fk): void
    {
        $className = $this->getRelationGetterClassName($fk);
        $varName = $this->getFKVarName($fk);

        $script .= "
    /**
     * @var ?$className
     */
    protected $" . $varName . ";
";
    }

    /**
     * Adds the mutator (setter) method for setting an fkey related object.
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $fk
     *
     * @return void
     */
    protected function addFKMutator(string &$script, ForeignKey $fk): void
    {
        $className = $this->getRelationObjectClassName($fk);

        $varName = $this->getFKVarName($fk);
        $internalAssociationMethodName = $this->getInternalFKAssociationMethodName($fk);

        $orNull = $fk->getLocalColumn()->isNotNull() ? '' : '|null';
        $mod = $orNull ? '?' : '';

        $funcParams = $orNull ? "?$className \$v = null" : "$className \$v";

        $currentClassName = $this->getClassNameFromTable($this->getTable());

        if ($this->isForeignKeyPartOfGeneratedHashCode($fk)) {
            $script .= "
        /**
         * Updates this object's association state without synchronizing the inverse collection.
         *
         * @internal Generated for relation-management code paths that must finalize identity before appending to a collection.
         *
         * @param {$className}{$orNull} \$v
         * @return void
         */
        public function {$internalAssociationMethodName}($funcParams): void
        {";

            $this->addFKAssociationAssignments($script, $fk, $varName);

            $script .= "
        }
    ";
        }

        $script .= "
    /**
     * Declares an association between this object and a $className object.
     *
     * @param {$className}{$orNull} \$v
     * @return \$this The current object (for fluent API support)
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function set" . $this->getFKPhpNameAffix($fk, false) . "($funcParams)
    {";

        if ($this->isForeignKeyPartOfGeneratedHashCode($fk)) {
            $script .= "
        \$this->{$internalAssociationMethodName}(\$v);
";
        } else {
            $this->addFKAssociationAssignments($script, $fk, $varName);
        }

        // Now add bi-directional relationship binding, taking into account whether this is
        // a one-to-one relationship.
        if ($fk->isSkipRefCode()) {
            // do nothing
        } elseif ($fk->isLocalPrimaryKey()) {
            $script .= "
        // Add binding for other direction of this 1:1 relationship.
        " . $this->getAssertedCurrentChildObjectSnippet() . "
        \$v{$mod}->set" . $this->getRefFKPhpNameAffix($fk, false) . "(\$this);
";
        } else {
            $script .= "
        // Add binding for other direction of this n:n relationship.
        // If this object has already been added to the $className object, it will not be re-added.
        " . $this->getAssertedCurrentChildObjectSnippet() . "
            \$v{$mod}->add" . $this->getRefFKPhpNameAffix($fk, false) . "(\$this);
";
        }

        $script .= "
        return \$this;
    }
";
    }

    /**
     * Adds the unsetter method for setting an fkey related object.
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $fk
     *
     * @return void
     */
    protected function addFKRemover(string &$script, ForeignKey $fk): void
    {
        $fkTable = $fk->getForeignTable();
        $interface = $fk->getInterface();

        if ($interface) {
            $className = $this->declareClass($interface);
        } else {
            $className = $this->getClassNameFromTable($fkTable);
        }

        $varName = $this->getFKVarName($fk);

        $script .= "
    /**
     * Removes an association between this object and a $className object.
     *
     * @return \$this The current object (for fluent API support)
     */
    public function unset" . $this->getFKPhpNameAffix($fk, false) . "()
    {";

        foreach ($fk->getMapping() as $map) {
            [$column, $rightValueOrColumn] = $map;
            $clo = $column->getLowercasedName();

            if ($rightValueOrColumn instanceof Column) {
                $unsetValue = $column->hasDefaultValue() ? $this->getDefaultValueString($column, false) : 'null';
                $script .= "
        \$this->$clo = $unsetValue;
";
            } else {
                $script .= "
        \$this->$clo = null;
            ";
            }
        } /* foreach local col */

        $script .= "
        \$this->$varName = null;

        return \$this;
    }";
    }

    /**
     * Adds the has method for checking if an fkey related object is set.
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $fk
     *
     * @return void
     */
    protected function addFKHasValue(string &$script, ForeignKey $fk): void
    {
        $fkTable = $fk->getForeignTable();
        $interface = $fk->getInterface();

        if ($interface) {
            $className = $this->declareClass($interface);
        } else {
            $className = $this->getClassNameFromTable($fkTable);
        }

        $varName = $this->getFKVarName($fk);

        $script .= "

    /**
     * Checks if $className exists.
     *
     * @return bool
     */
    public function has" . $this->getFKPhpNameAffix($fk, false) . "()
    {";
        $script .= "
        return \$this->$varName !== null;
    }";
    }

    /**
     * Adds the accessor (getter) method for getting an fkey related object.
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $fk
     *
     * @return void
     */
    protected function addFKAccessor(string &$script, ForeignKey $fk): void
    {
        $varName = $this->getFKVarName($fk);
        $phpName = $this->getFKPhpNameAffix($fk, false);
        $fkQueryBuilder = $this->getNewStubQueryBuilder($fk->getForeignTable());
        $returnDesc = '';
        $className = $this->getRelationGetterClassName($fk);
        if (!$fk->getInterface()) {
            $returnDesc = "The associated $className object.";
        }

        $and = '';
        $conditional = '';
        $localColumns = []; // foreign key local attributes names

        // If the related columns are a primary key on the foreign table
        // then use findPk() instead of doSelect() to take advantage
        // of instance pooling
        $findPk = $fk->isForeignPrimaryKey();

        foreach ($fk->getMapping() as $mapping) {
            [$column, $rightValueOrColumn] = $mapping;

            $cptype = $column->getPhpType();
            $clo = $column->getLowercasedName();

            if ($rightValueOrColumn instanceof Column) {
                $localColumns[$rightValueOrColumn->getPosition()] = '$this->' . $clo;

                if ($cptype === 'int' || $cptype === 'float' || $cptype === 'double') {
                    $conditional .= $and . '$this->' . $clo . ' != 0';
                } elseif ($cptype === 'string') {
                    $conditional .= $and . '($this->' . $clo . ' !== "" && $this->' . $clo . ' !== null)';
                } else {
                    $conditional .= $and . '$this->' . $clo . ' !== null';
                }
            } else {
                $val = var_export($rightValueOrColumn, true);
                $conditional .= $and . '$this->' . $clo . ' === ' . $val;
            }

            $and = ' && ';
        }

        ksort($localColumns); // restoring the order of the foreign PK
        if ($localColumns === []) {
            throw new EngineException(sprintf('Foreign key %s must define at least one local column.', $fk->getName()));
        }

        $orderedLocalColumns = array_values($localColumns);
        $localColumns = count($orderedLocalColumns) > 1
            ? '[' . implode(', ', $orderedLocalColumns) . ']'
            : $orderedLocalColumns[0];

        $orNull = $fk->getLocalColumn()->isNotNull() ? '' : '|null';

        $currentClassName = $this->getClassNameFromTable($this->getTable());

        $script .= "

    /**
     * Get the associated $className object
     *
     * @param ?ConnectionInterface \$con Optional Connection object.
     * @return {$className}{$orNull} $returnDesc
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function get" . $phpName . "(?ConnectionInterface \$con = null)
    {";
        $script .= $this->getFKAccessorLoadSnippet($fk, $varName, $conditional, $findPk, $localColumns, $fkQueryBuilder);

        if (!$orNull) {
            $script .= "
        if (\$this->$varName === null) {
            throw new PropelException('Cannot return a null related object from get{$phpName}() because the relation is required.');
        }
        ";
        }

        $script .= "
        return \$this->$varName;
    }
";

        if (!$orNull) {
            $script .= "
    /**
     * Try to get the associated $className object
     *
     * @param ?ConnectionInterface \$con Optional Connection object.
     * @return {$className}|null $returnDesc
     */
    public function tryGet" . $phpName . "(?ConnectionInterface \$con = null)
    {";
            $script .= $this->getFKAccessorLoadSnippet($fk, $varName, $conditional, $findPk, $localColumns, $fkQueryBuilder);
            $script .= "
        return \$this->$varName;
    }
";
        }
    }

    protected function getFKAccessorLoadSnippet(
        ForeignKey $fk,
        string $varName,
        string $conditional,
        bool $findPk,
        string $localColumns,
        AbstractOMBuilder $fkQueryBuilder
    ): string {
        $script = "
        if (\$this->$varName === null && ($conditional)) {
            " . $this->getAssertedCurrentChildObjectSnippet();

        if ($findPk) {
            $script .= "
            \$this->$varName = " . $this->getClassNameFromBuilder($fkQueryBuilder) . "::create()->findPk($localColumns, \$con);";
        } else {
            $script .= "
            \$this->$varName = " . $this->getClassNameFromBuilder($fkQueryBuilder) . "::create()
                ->filterBy" . $this->getRefFKPhpNameAffix($fk, false) . "(\$this) // here
                ->findOne(\$con);";
        }

        if ($fk->isLocalPrimaryKey()) {
            $script .= "
            // Because this foreign key represents a one-to-one relationship, we will create a bi-directional association.
            \$this->{$varName}?->set" . $this->getRefFKPhpNameAffix($fk, false) . "(\$this);";
        } else {
            $script .= "
            /* The following can be used additionally to
                guarantee the related object contains a reference
                to this object.  This level of coupling may, however, be
                undesirable since it could result in an only partially populated collection
                in the referenced object.
                \$this->{$varName}->add" . $this->getRefFKPhpNameAffix($fk, true) . "(\$this);
             */";
        }

        return $script . "
        }
        ";
    }

    /**
     * Adds the method that fetches fkey-related (referencing) objects but also joins in data from another table.
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $refFK
     *
     * @return void
     */
    protected function addRefFKGetJoinMethods(string &$script, ForeignKey $refFK): void
    {
        $table = $this->getTable();
        $tblFK = $refFK->getTable();
        $joinBehavior = $this->getBuildProperty('generator.objectModel.useLeftJoinsInDoJoinMethods') ? 'Criteria::LEFT_JOIN' : 'Criteria::INNER_JOIN';

        $fkQueryClassName = $this->getClassNameFromBuilder($this->getNewStubQueryBuilder($refFK->getTable()));
        $relCol = $this->getRefFKPhpNameAffix($refFK, true);

        $className = $this->getClassNameFromTable($tblFK);

        foreach ($tblFK->getForeignKeys() as $fk2) {
            $tblFK2 = $fk2->getForeignTable();
            $doJoinGet = !$tblFK2->isForReferenceOnly();

            // it doesn't make sense to join in rows from the current table, since we are fetching
            // objects related to *this* table (i.e. the joined rows will all be the same row as current object)
            if ($this->getTable()->getPhpName() == $tblFK2->getPhpName()) {
                $doJoinGet = false;
            }

            $relCol2 = $this->getFKPhpNameAffix($fk2, false);

            if (
                $this->getRelatedBySuffix($refFK) != '' &&
                ($this->getRelatedBySuffix($refFK) == $this->getRelatedBySuffix($fk2))
            ) {
                $doJoinGet = false;
            }

            if ($doJoinGet) {
                $script .= "

    /**
     * If this collection has already been initialized with
     * an identical criteria, it returns the collection.
     * Otherwise if this " . $table->getPhpName() . " is new, it will return
     * an empty collection; or if this " . $table->getPhpName() . " has previously
     * been saved, it will retrieve related $relCol from storage.
     *
     * This method is protected by default in order to keep the public
     * api reasonable.  You can provide public methods for those you
     * actually need in " . $table->getPhpName() . ".
     *
     * @param ?Criteria \$criteria optional Criteria object to narrow the query
     * @param ?ConnectionInterface \$con optional connection object
     * @param string \$joinBehavior optional join type to use (defaults to $joinBehavior)
     * @return ObjectCollection&\Traversable<$className> List of $className objects
     */
    public function get" . $relCol . 'Join' . $relCol2 . "(?Criteria \$criteria = null, ?ConnectionInterface \$con = null, \$joinBehavior = $joinBehavior)
    {";
                $script .= "
        \$query = $fkQueryClassName::create(null, \$criteria);
        \$query->joinWith('" . $this->getFKPhpNameAffix($fk2, false) . "', \$joinBehavior);

        return \$this->get" . $relCol . "(\$query, \$con);
    }
";
            } /* end if ($doJoinGet) */
        } /* end foreach ($tblFK->getForeignKeys() as $fk2) { */
    }

    /**
     * Adds the attributes used to store objects that have referrer fkey relationships to this object.
     * <code>protected collVarName;</code>
     * <code>private lastVarNameCriteria = null;</code>
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $refFK
     *
     * @return void
     */
    protected function addRefFKAttributes(string &$script, ForeignKey $refFK): void
    {
        $className = $this->getClassNameFromTable($refFK->getTable());

        if ($refFK->isLocalPrimaryKey()) {
            $className = $this->getRefRelationGetterClassName($refFK);
            $script .= "
    /**
     * @var ?$className one-to-one related $className object
     */
    protected $" . $this->getPKRefFKVarName($refFK) . ";
";
        } else {
            $script .= "
    /**
     * @var (ObjectCollection&\Traversable<{$className}>)|null Collection to store aggregation of $className objects.
     */
    protected $" . $this->getRefFKCollVarName($refFK) . ";
    /**
     * @var bool|null
     */
    protected $" . $this->getRefFKCollVarName($refFK) . "Partial = null;
";
        }
    }

    /**
     * Adds the methods for retrieving, initializing, adding objects that are related to this one by foreign keys.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addRefFKMethods(string &$script): void
    {
        $referrers = $this->getTable()->getReferrersForCodeGeneration();
        if (!$referrers) {
            return;
        }

        $this->addInitRelations($script, $referrers);
        foreach ($referrers as $refFK) {
            $this->declareClassFromBuilder($this->getNewStubObjectBuilder($refFK->getTable()), 'Child');
            $this->declareClassFromBuilder($this->getNewStubQueryBuilder($refFK->getTable()));
            if ($refFK->isLocalPrimaryKey()) {
                $this->addPKRefFKGet($script, $refFK);
                $this->addPKRefFKSet($script, $refFK);
            } else {
                $this->addRefFKClear($script, $refFK);
                $this->addRefFKPartial($script, $refFK);
                $this->addRefFKInit($script, $refFK);
                $this->addRefFKGet($script, $refFK);
                $this->addRefFKSet($script, $refFK);
                $this->addRefFKCount($script, $refFK);
                $this->addRefFKAdd($script, $refFK);
                $this->addRefFKDoAdd($script, $refFK);
                $this->addRefFKRemove($script, $refFK);
                $this->addRefFKGetJoinMethods($script, $refFK);
            }
        }
    }

    /**
     * @param string $script
     * @param array<ForeignKey> $referrers
     *
     * @return void
     */
    protected function addInitRelations(string &$script, array $referrers): void
    {
        $script .= "

    /**
     * Initializes a collection based on the name of a relation.
     * Avoids crafting an 'init[\$relationName]s' method name
     * that wouldn't work when StandardEnglishPluralizer is used.
     *
     * @param string \$relationName The name of the relation to initialize
     * @return void
     */
    public function initRelation(\$relationName): void
    {";
        foreach ($referrers as $refFK) {
            if (!$refFK->isLocalPrimaryKey()) {
                $relationName = $this->getRefFKPhpNameAffix($refFK);
                $relCol = $this->getRefFKPhpNameAffix($refFK, true);
                $script .= "
        if ('$relationName' === \$relationName) {
            \$this->init$relCol();
            return;
        }";
            }
        }
        $script .= "
    }
";
    }

    /**
     * Adds the method that clears the referrer fkey collection.
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $refFK
     *
     * @return void
     */
    protected function addRefFKClear(string &$script, ForeignKey $refFK): void
    {
        $relCol = $this->getRefFKPhpNameAffix($refFK, true);
        $collName = $this->getRefFKCollVarName($refFK);

        $script .= "
    /**
     * Clears out the $collName collection
     *
     * This does not modify the database; however, it will remove any associated objects, causing
     * them to be refetched by subsequent calls to accessor method.
     *
     * @return \$this
     * @see add$relCol()
     */
    public function clear$relCol()
    {
        \$this->$collName = null; // important to set this to NULL since that means it is uninitialized

        return \$this;
    }
";
    }

    /**
     * Adds the method that initializes the referrer fkey collection.
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $refFK
     *
     * @return void
     */
    protected function addRefFKInit(string &$script, ForeignKey $refFK): void
    {
        $relCol = $this->getRefFKPhpNameAffix($refFK, true);
        $collName = $this->getRefFKCollVarName($refFK);

        $script .= "
    /**
     * Initializes the $collName collection.
     *
     * By default this just sets the $collName collection to an empty array (like clear$collName());
     * however, you may wish to override this method in your stub class to provide setting appropriate
     * to your application -- for example, setting the initial array to the values stored in database.
     *
     * @param bool \$overrideExisting If set to true, the method call initializes
     *                                        the collection even if it is not empty
     *
     * @return void
     */
    public function init$relCol(bool \$overrideExisting = true): void
    {
        if (null !== \$this->$collName && !\$overrideExisting) {
            return;
        }

        \$collectionClassName = " . $this->getClassNameFromBuilder($this->getNewTableMapBuilder($refFK->getTable())) . "::getTableMap()->getCollectionClassName();

        \$collection = new \$collectionClassName;
        assert(\$collection instanceof ObjectCollection);

        \$this->{$collName} = \$collection;
        \$this->{$collName}->setModel('" . $this->getClassNameFromBuilder($this->getNewStubObjectBuilder($refFK->getTable()), true) . "');
    }
";
    }

    /**
     * Adds the method that adds an object into the referrer fkey collection.
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $refFK
     *
     * @return void
     */
    protected function addRefFKAdd(string &$script, ForeignKey $refFK): void
    {
        $className = $this->getClassNameFromTable($refFK->getTable());

        $collName = $this->getRefFKCollVarName($refFK);

        $scheduledForDeletion = lcfirst($this->getRefFKPhpNameAffix($refFK, true)) . 'ScheduledForDeletion';

        $script .= "
    /**
     * Method called to associate a $className object to this object
     * through the $className foreign key attribute.
     *
     * @param $className \$l $className
     * @return \$this The current object (for fluent API support)
     */
    public function add" . $this->getRefFKPhpNameAffix($refFK, false) . "($className \$l)
    {
        if (\$this->$collName === null) {
            \$this->init" . $this->getRefFKPhpNameAffix($refFK, true) . "();
            \$this->{$collName}Partial = true;
        }

        if (!\$this->{$collName}?->contains(\$l)) {
            \$this->doAdd" . $this->getRefFKPhpNameAffix($refFK, false) . "(\$l);

            if (\$this->{$scheduledForDeletion} && \$this->{$scheduledForDeletion}->contains(\$l)) {
                \$this->{$scheduledForDeletion}->remove(\$this->{$scheduledForDeletion}->search(\$l));
            }
        }

        return \$this;
    }
";
    }

    /**
     * Adds the method that returns the size of the referrer fkey collection.
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $refFK
     *
     * @return void
     */
    protected function addRefFKCount(string &$script, ForeignKey $refFK): void
    {
        $fkQueryClassName = $this->getClassNameFromBuilder($this->getNewStubQueryBuilder($refFK->getTable()));
        $relCol = $this->getRefFKPhpNameAffix($refFK, true);
        $collName = $this->getRefFKCollVarName($refFK);

        $className = $this->getClassNameFromTable($refFK->getTable());
        $currentClassName = $this->getClassNameFromTable($this->getTable());

        $script .= "
    /**
     * Returns the number of related $className objects.
     *
     * @param ?Criteria \$criteria
     * @param bool \$distinct
     * @param ?ConnectionInterface \$con
     * @return int Count of related $className objects.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function count{$relCol}(?Criteria \$criteria = null, bool \$distinct = false, ?ConnectionInterface \$con = null): int
    {
        \$partial = \$this->{$collName}Partial && !\$this->isNew();
        if (null === \$this->$collName || null !== \$criteria || \$partial) {
            if (\$this->isNew() && null === \$this->$collName) {
                return 0;
            }

            if (\$partial && !\$criteria) {
                return count(\$this->get$relCol());
            }

            \$query = $fkQueryClassName::create(null, \$criteria);
            if (\$distinct) {
                \$query->distinct();
            }

            " . $this->getAssertedCurrentChildObjectSnippet() . "
            return \$query
                ->filterBy" . $this->getFKPhpNameAffix($refFK) . "(\$this)
                ->count(\$con);
        }

        return count(\$this->$collName);
    }
";
    }

    /**
     * Adds the method that returns the referrer fkey collection.
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $refFK
     * @return void
     */
    protected function addRefFKGet(string &$script, ForeignKey $refFK): void
    {
        $fkQueryClassName = $this->getClassNameFromBuilder($this->getNewStubQueryBuilder($refFK->getTable()));
        $relCol = $this->getRefFKPhpNameAffix($refFK, true);
        $collName = $this->getRefFKCollVarName($refFK);
        $className = $this->getClassNameFromTable($refFK->getTable());
        $currentClassName = $this->getClassNameFromTable($this->getTable());

        $script .= "
    /**
     * Gets an array of $className objects which contain a foreign key that references this object.
     *
     * If the \$criteria is not null, it is used to always fetch the results from the database.
     * Otherwise the results are fetched from the database the first time, then cached.
     * Next time the same method is called without \$criteria, the cached collection is returned.
     * If this " . $this->getObjectClassName() . " is new, it will return
     * an empty collection or the current collection; the criteria is ignored on a new object.
     *
     * @param ?Criteria \$criteria optional Criteria object to narrow the query
     * @param ?ConnectionInterface \$con optional connection object
     * @return ObjectCollection&\Traversable<{$className}> List of $className objects
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function get$relCol(?Criteria \$criteria = null, ?ConnectionInterface \$con = null)
    {
        \$partial = \$this->{$collName}Partial && !\$this->isNew();
        if (null === \$this->$collName || null !== \$criteria || \$partial) {
            if (\$this->isNew()) {
                // return empty collection
                if (null === \$this->$collName) {
                    \$this->init" . $this->getRefFKPhpNameAffix($refFK, true) . "();
                } else {
                    \$collectionClassName = " . $this->getClassNameFromBuilder($this->getNewTableMapBuilder($refFK->getTable())) . "::getTableMap()->getCollectionClassName();

                    /** @var ObjectCollection&\Traversable<{$className}>  */
                    \$$collName = new \$collectionClassName;
                    \${$collName}->setModel('" . $this->getClassNameFromBuilder($this->getNewStubObjectBuilder($refFK->getTable()), true) . "');

                    return \$$collName;
                }
            } else {
                " . $this->getAssertedCurrentChildObjectSnippet() . "
                \$$collName = $fkQueryClassName::create(null, \$criteria)
                    ->filterBy" . $this->getFKPhpNameAffix($refFK) . "(\$this)
                    ->find(\$con);

                if (null !== \$criteria) {
                    if (false !== \$this->{$collName}Partial && count(\$$collName)) {
                        \$this->init" . $this->getRefFKPhpNameAffix($refFK, true) . "(false);

                        foreach (\$$collName as \$obj) {
                            if (false == \$this->{$collName}?->contains(\$obj)) {
                                \$this->{$collName}?->append(\$obj);
                            }
                        }

                        \$this->{$collName}Partial = true;
                    }
                    assert(\$$collName instanceof ObjectCollection);
                    return \$$collName;
                }

                if (\$partial && \$this->$collName) {
                    foreach (\$this->$collName as \$obj) {
                        if (\$obj->isNew()) {
                            \${$collName}[] = \$obj;
                        }
                    }
                }
                
                assert(\$$collName instanceof ObjectCollection);
                \$this->$collName = \$$collName;
                \$this->{$collName}Partial = false;
            }
        }
     
        assert(\$this->$collName !== null);
        return \$this->$collName;
    }
";
    }

    /**
     * @param string $script
     * @param ForeignKey $refFK
     *
     * @return void
     */
    protected function addRefFKSet(string &$script, ForeignKey $refFK): void
    {
        $relatedName = $this->getRefFKPhpNameAffix($refFK, true);
        $relatedObjectClassName = $this->getRefFKPhpNameAffix($refFK, false);

        $className = $this->getClassNameFromTable($refFK->getTable());

        $inputCollection = lcfirst($relatedName);
        $inputCollectionEntry = lcfirst($this->getRefFKPhpNameAffix($refFK, false));

        $collName = $this->getRefFKCollVarName($refFK);
        $relCol = $this->getFKPhpNameAffix($refFK, false);

        $script .= "
    /**
     * Sets a collection of $className objects related by a one-to-many relationship
     * to the current object.
     * It will also schedule objects for deletion based on a diff between old objects (aka persisted)
     * and new objects from the given Propel collection.
     *
     * @param ObjectCollection \${$inputCollection} A Propel collection.
     * @param ?ConnectionInterface \$con Optional connection object
     * @return \$this The current object (for fluent API support)
     */
    public function set{$relatedName}(ObjectCollection \${$inputCollection}, ?ConnectionInterface \$con = null)
    {
        \${$inputCollection}ToDelete = \$this->get{$relatedName}(new Criteria(), \$con)->diff(\${$inputCollection});
        assert(\${$inputCollection}ToDelete instanceof ObjectCollection);
        ";

        if ($refFK->isAtLeastOneLocalPrimaryKey()) {
            $script .= "
        //since at least one column in the foreign key is at the same time a PK
        //we can not just set a PK to NULL in the lines below. We have to store
        //a backup of all values, so we are able to manipulate these items based on the onDelete value later.
        \$this->{$inputCollection}ScheduledForDeletion = clone \${$inputCollection}ToDelete;
";
        } else {
            $script .= "
        \$this->{$inputCollection}ScheduledForDeletion = \${$inputCollection}ToDelete;
";
        }

        $script .= "
        foreach (\${$inputCollection}ToDelete as \${$inputCollectionEntry}Removed) {
";

        if (!$refFK->isAtLeastOneLocalPrimaryKey()) {
            $script .= "            \${$inputCollectionEntry}Removed->unset{$relCol}();
    ";
        }

        $script .= "        }

        \$this->{$collName} = null;
        foreach (\${$inputCollection} as \${$inputCollectionEntry}) {
            \$this->add{$relatedObjectClassName}(\${$inputCollectionEntry});
        }

        \$this->{$collName} = \${$inputCollection};
        \$this->{$collName}Partial = false;

        return \$this;
    }
";
    }

    /**
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $refFK
     *
     * @return void
     */
    protected function addRefFKDoAdd(string &$script, ForeignKey $refFK): void
    {
        $className = $this->getClassNameFromTable($refFK->getTable());

        $relatedObjectClassName = $this->getRefFKPhpNameAffix($refFK, false);
        $lowerRelatedObjectClassName = lcfirst($relatedObjectClassName);
        $collName = $this->getRefFKCollVarName($refFK);
        $currentClassName = $this->getClassNameFromTable($this->getTable());
        $setterCall = $this->getForeignKeyAssociationCall($refFK, '$' . $lowerRelatedObjectClassName, '$this');

        $script .= "
    /**
     * @param {$className} \${$lowerRelatedObjectClassName} The $className object to add.
     */
    protected function doAdd{$relatedObjectClassName}($className \${$lowerRelatedObjectClassName}): void
    {
        " . $this->getAssertedCurrentChildObjectSnippet() . "
";

        if ($this->isForeignKeyPartOfGeneratedHashCode($refFK)) {
            $script .= "        {$setterCall}
        \$this->{$collName}?->append(\${$lowerRelatedObjectClassName});
";
        } else {
            $script .= "        \$this->{$collName}?->append(\${$lowerRelatedObjectClassName});
        {$setterCall}
";
        }

        $script .= "    }
";
    }

    /**
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $refFK
     *
     * @return void
     */
    protected function addRefFKRemove(string &$script, ForeignKey $refFK): void
    {
        $className = $this->getClassNameFromTable($refFK->getTable());

        $relatedName = $this->getRefFKPhpNameAffix($refFK, true);
        $relatedObjectClassName = $this->getRefFKPhpNameAffix($refFK, false);
        $inputCollection = lcfirst($relatedName . 'ScheduledForDeletion');
        $lowerRelatedObjectClassName = lcfirst($relatedObjectClassName);

        $collName = $this->getRefFKCollVarName($refFK);
        $relCol = $this->getFKPhpNameAffix($refFK, false);
        $localColumn = $refFK->getLocalColumn();

        $script .= "
    /**
     * @param {$className} \${$lowerRelatedObjectClassName} The $className object to remove.
     * @return \$this The current object (for fluent API support)
     */
    public function remove{$relatedObjectClassName}($className \${$lowerRelatedObjectClassName})
    {
        if (\$this->get{$relatedName}()->contains(\${$lowerRelatedObjectClassName})) {
            \$pos = \$this->{$collName}?->search(\${$lowerRelatedObjectClassName});
            \$this->{$collName}?->remove(\$pos);
            if (null === \$this->{$inputCollection}) {
                \$this->{$inputCollection} = \$this->{$collName} ? clone \$this->{$collName} : null;
                \$this->{$inputCollection}?->clear();
            }";

        if (!$refFK->isComposite() && !$localColumn->isNotNull()) {
            $script .= "
            \$this->{$inputCollection}?->append(\${$lowerRelatedObjectClassName});";
        } else {
            $script .= "
            \$this->{$inputCollection}?->append(clone \${$lowerRelatedObjectClassName});";
        }

        $script .= "
            \${$lowerRelatedObjectClassName}->unset{$relCol}();
        }

        return \$this;
    }
";
    }

    /**
     * Adds the method that gets a one-to-one related referrer fkey.
     * This is for one-to-one relationship special case.
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $refFK
     *
     * @return void
     */
    protected function addPKRefFKGet(string &$script, ForeignKey $refFK): void
    {
        $className = $this->getRefRelationGetterClassName($refFK);

        $queryClassName = $this->getClassNameFromBuilder($this->getNewStubQueryBuilder($refFK->getTable()));

        $varName = $this->getPKRefFKVarName($refFK);

        $script .= "
    /**
     * Gets a single $className object, which is related to this object by a one-to-one relationship.
     *
     * @param ?ConnectionInterface \$con optional connection object
     * @return $className|null
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function get" . $this->getRefFKPhpNameAffix($refFK, false) . "(?ConnectionInterface \$con = null)
    {
";
        $script .= "
        if (\$this->$varName === null && !\$this->isNew()) {
            \$this->$varName = $queryClassName::create()->findPk(\$this->getPrimaryKey(), \$con);
        }

        return \$this->$varName;
    }
";
    }

    /**
     * Adds the method that sets a one-to-one related referrer fkey.
     * This is for one-to-one relationships special case.
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $refFK The referencing foreign key.
     *
     * @return void
     */
    protected function addPKRefFKSet(string &$script, ForeignKey $refFK): void
    {
        $className = $this->getRefRelationGetterClassName($refFK);

        $varName = $this->getPKRefFKVarName($refFK);

        $script .= "
    /**
     * Sets a single $className object as related to this object by a one-to-one relationship.
     *
     * @return \$this The current object (for fluent API support)
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function set" . $this->getRefFKPhpNameAffix($refFK, false) . "(?$className \$v = null)
    {
        \$this->$varName = \$v;

        // Make sure that that the passed-in $className isn't already associated with this object
        if (\$v !== null && !\$v->has" . $this->getFKPhpNameAffix($refFK, false) . "()) {
            " . $this->getAssertedCurrentChildObjectSnippet() . "
            \$v->set" . $this->getFKPhpNameAffix($refFK, false) . "(\$this);
        }

        return \$this;
    }
";
    }

    /**
     * @param string $script
     * @param CrossForeignKeys $crossFKs
     *
     * @return void
     */
    protected function addCrossFKAttributes(string &$script, CrossForeignKeys $crossFKs): void
    {
        if (1 < count($crossFKs->getCrossForeignKeys()) || $crossFKs->getUnclassifiedPrimaryKeys()) {
            [$names] = $this->getCrossFKInformation($crossFKs);
            $script .= "
    /**
     * @var ObjectCombinationCollection Cross CombinationCollection to store aggregation of $names combinations.
     */
    protected \$combination" . ucfirst($this->getCrossFKsVarName($crossFKs)) . ";

    /**
     * @var bool
     */
    protected \$combination" . ucfirst($this->getCrossFKsVarName($crossFKs)) . "Partial;
";
        }

        foreach ($crossFKs->getCrossForeignKeys() as $fk) {
            $className = $this->getClassNameFromTable($fk->getForeignTable());

            $script .= "
    /**
     * @var ObjectCollection&\Traversable<{$className}> Cross Collection to store aggregation of $className objects.
     */
    protected \$coll" . $this->getFKPhpNameAffix($fk, true) . ";

    /**
     * @var bool
     */
    protected \$coll" . $this->getFKPhpNameAffix($fk, true) . "Partial;
";
        }
    }

    /**
     * @param string $script
     * @param CrossForeignKeys $crossFKs
     *
     * @return void
     */
    protected function addCrossScheduledForDeletionAttribute(string &$script, CrossForeignKeys $crossFKs): void
    {
        $name = $this->getCrossScheduledForDeletionVarName($crossFKs);
        if (1 < count($crossFKs->getCrossForeignKeys()) || $crossFKs->getUnclassifiedPrimaryKeys()) {
            [$names] = $this->getCrossFKInformation($crossFKs);
            $script .= "
    /**
     * @var ObjectCombinationCollection Cross CombinationCollection to store aggregation of $names combinations.
     */
    protected \$$name = null;
";
        } else {
            $refFK = $crossFKs->getIncomingForeignKey();
            if (!$refFK->isLocalPrimaryKey()) {
                $foreignTable = $crossFKs->getCrossForeignKeys()[0]->getForeignTable();
                $className = $this->getClassNameFromTable($foreignTable);
                $script .= "
    /**
     * An array of objects scheduled for deletion.
     * @var (ObjectCollection&\Traversable<{$className}>)|null
     */
    protected \$$name = null;
";
            }
        }
    }

    /**
     * @param CrossForeignKeys $crossFKs
     *
     * @return string
     */
    protected function getCrossScheduledForDeletionVarName(CrossForeignKeys $crossFKs): string
    {
        if (1 < count($crossFKs->getCrossForeignKeys()) || $crossFKs->getUnclassifiedPrimaryKeys()) {
            return 'combination' . ucfirst($this->getCrossFKsVarName($crossFKs)) . 'ScheduledForDeletion';
        } else {
            $fkName = lcfirst($this->getFKPhpNameAffix($crossFKs->getCrossForeignKeys()[0], true));

            return "{$fkName}ScheduledForDeletion";
        }
    }

    /**
     * @param string $script
     * @param ForeignKey $crossFK
     *
     * @return void
     */
    protected function addCrossFkScheduledForDeletionAttribute(string &$script, ForeignKey $crossFK): void
    {
        $className = $this->getClassNameFromTable($crossFK->getForeignTable());
        $fkName = lcfirst($this->getFKPhpNameAffix($crossFK, true));

        $script .= "
    /**
     * An array of objects scheduled for deletion.
     * @var (ObjectCollection&\Traversable<{$className}>)|null
     */
    protected \${$fkName}ScheduledForDeletion = null;
";
    }

    /**
     * @param string $script
     * @param ForeignKey $refFK
     *
     * @return void
     */
    protected function addRefFkScheduledForDeletionAttribute(string &$script, ForeignKey $refFK): void
    {
        $className = $this->getClassNameFromTable($refFK->getTable());
        $fkName = lcfirst($this->getRefFKPhpNameAffix($refFK, true));

        $script .= "
    /**
     * An array of objects scheduled for deletion.
     * @var (ObjectCollection&\Traversable<{$className}>)|null
     */
    protected \${$fkName}ScheduledForDeletion = null;
";
    }

    /**
     * @param string $script
     * @param CrossForeignKeys $crossFKs
     *
     * @return void
     */
    protected function addCrossFkScheduledForDeletion(string &$script, CrossForeignKeys $crossFKs): void
    {
        $multipleFks = 1 < count($crossFKs->getCrossForeignKeys()) || (bool)$crossFKs->getUnclassifiedPrimaryKeys();
        $scheduledForDeletionVarName = $this->getCrossScheduledForDeletionVarName($crossFKs);
        $queryClassName = $this->getNewStubQueryBuilder($crossFKs->getMiddleTable())->getClassname();

        $crossPks = $crossFKs->getMiddleTable()->getPrimaryKey();

        $script .= "
            if (\$this->$scheduledForDeletionVarName !== null) {
                if (!\$this->{$scheduledForDeletionVarName}->isEmpty()) {
                    \$pks = [];";
        if ($multipleFks) {
            $script .= "
                    foreach (\$this->{$scheduledForDeletionVarName} as \$combination) {
                        \$entryPk = [];
";
            foreach ($crossFKs->getIncomingForeignKey()->getColumnObjectsMapping() as $reference) {
                $local = $reference['local'];
                $foreign = $reference['foreign'];

                $idx = array_search($local, $crossPks, true);
                $script .= "
                        \$entryPk[$idx] = \$this->get{$foreign->getPhpName()}();";
            }

            $combinationIdx = 0;
            foreach ($crossFKs->getCrossForeignKeys() as $crossFK) {
                foreach ($crossFK->getColumnObjectsMapping() as $reference) {
                    $local = $reference['local'];
                    $foreign = $reference['foreign'];

                    $idx = array_search($local, $crossPks, true);
                    $script .= "
                        \$entryPk[$idx] = \$combination[$combinationIdx]->get{$foreign->getPhpName()}();";
                }
                $combinationIdx++;
            }

            foreach ($crossFKs->getUnclassifiedPrimaryKeys() as $pk) {
                $idx = array_search($pk, $crossPks, true);
                $script .= "
                        //\$combination[$combinationIdx] = {$pk->getPhpName()};
                        \$entryPk[$idx] = \$combination[$combinationIdx];";
                $combinationIdx++;
            }

            $script .= "

                        \$pks[] = \$entryPk;
                    }
";

            $script .= "
                    $queryClassName::create()
                        ->filterByPrimaryKeys(\$pks)
                        ->delete(\$con);
";
        } else {
            $script .= "
                    foreach (\$this->{$scheduledForDeletionVarName} as \$entry) {
                        \$entryPk = [];
";

            foreach ($crossFKs->getIncomingForeignKey()->getColumnObjectsMapping() as $reference) {
                $local = $reference['local'];
                $foreign = $reference['foreign'];

                $idx = array_search($local, $crossPks, true);
                $script .= "
                        \$entryPk[$idx] = \$this->get{$foreign->getPhpName()}();";
            }

            $crossFK = $crossFKs->getCrossForeignKeys()[0];
            foreach ($crossFK->getColumnObjectsMapping() as $reference) {
                $local = $reference['local'];
                $foreign = $reference['foreign'];

                $idx = array_search($local, $crossPks, true);
                $script .= "
                        \$entryPk[$idx] = \$entry->get{$foreign->getPhpName()}();";
            }

            $script .= "
                        \$pks[] = \$entryPk;
                    }

                    {$queryClassName}::create()
                        ->filterByPrimaryKeys(\$pks)
                        ->delete(\$con);
";
        }

        $script .= "
                    \$this->$scheduledForDeletionVarName = null;
                }
";

        $script .= "
            }
";

        if ($multipleFks) {
            $combineVarName = 'combination' . ucfirst($this->getCrossFKsVarName($crossFKs));
            $script .= "
            if (null !== \$this->$combineVarName) {
                foreach (\$this->$combineVarName as \$combination) {
";

            $combinationIdx = 0;
            foreach ($crossFKs->getCrossForeignKeys() as $crossFK) {
                $script .= "
                    //\$combination[$combinationIdx] = {$crossFK->getForeignTable()->getPhpName()} ({$crossFK->getName()})
                    if (!\$combination[$combinationIdx]->isDeleted() && (\$combination[$combinationIdx]->isNew() || \$combination[$combinationIdx]->isModified())) {
                        \$combination[$combinationIdx]->save(\$con);
                    }
                ";

                $combinationIdx++;
            }

            foreach ($crossFKs->getUnclassifiedPrimaryKeys() as $pk) {
                $script .= "
                    //\$combination[$combinationIdx] = {$pk->getPhpName()}; Nothing to save.";
                $combinationIdx++;
            }

            $script .= "
                }
            }
";
        } else {
            foreach ($crossFKs->getCrossForeignKeys() as $fk) {
                $relatedName = $this->getFKPhpNameAffix($fk, true);
                $lowerSingleRelatedName = lcfirst($this->getFKPhpNameAffix($fk, false));

                $script .= "
            if (\$this->coll{$relatedName}) {
                foreach (\$this->coll{$relatedName} as \${$lowerSingleRelatedName}) {
                    if (!\${$lowerSingleRelatedName}->isDeleted() && (\${$lowerSingleRelatedName}->isNew() || \${$lowerSingleRelatedName}->isModified())) {
                        \${$lowerSingleRelatedName}->save(\$con);
                    }
                }
            }
";
            }
        }

        $script .= "
";
    }

    /**
     * @param string $script
     * @param ForeignKey $refFK
     *
     * @return void
     */
    protected function addRefFkScheduledForDeletion(string &$script, ForeignKey $refFK): void
    {
        $relatedName = $this->getRefFKPhpNameAffix($refFK, true);
        $lowerRelatedName = lcfirst($relatedName);
        $lowerSingleRelatedName = lcfirst($this->getRefFKPhpNameAffix($refFK, false));
        $queryClassName = $this->getNewStubQueryBuilder($refFK->getTable())->getClassname();

        $script .= "
            if (\$this->{$lowerRelatedName}ScheduledForDeletion !== null) {
                if (!\$this->{$lowerRelatedName}ScheduledForDeletion->isEmpty()) {";

        if ($refFK->isLocalColumnsRequired() || $refFK->getOnDelete() === ForeignKey::CASCADE) {
            $script .= "
                    $queryClassName::create()
                        ->filterByPrimaryKeys(\$this->{$lowerRelatedName}ScheduledForDeletion->getPrimaryKeys(false))
                        ->delete(\$con);";
        } else {
            $script .= "
                    foreach (\$this->{$lowerRelatedName}ScheduledForDeletion as \${$lowerSingleRelatedName}) {
                        // need to save related object because we set the relation to null
                        \${$lowerSingleRelatedName}->save(\$con);
                    }";
        }

        $script .= "
                    \$this->{$lowerRelatedName}ScheduledForDeletion = null;
                }
            }
";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addCrossFKMethods(string &$script): void
    {
        foreach ($this->getTable()->getCrossFks() as $crossFKs) {
            foreach ($crossFKs->getCrossForeignKeys() as $fk) {
                $this->declareClassFromBuilder($this->getNewStubObjectBuilder($fk->getForeignTable()), 'Child');
                $this->declareClassFromBuilder($this->getNewStubQueryBuilder($fk->getForeignTable()));
            }

            $this->addCrossFKClear($script, $crossFKs);
            $this->addCrossFKInit($script, $crossFKs);
            $this->addCrossFKisLoaded($script, $crossFKs);
            $this->addCrossFKCreateQuery($script, $crossFKs);
            $this->addCrossFKGet($script, $crossFKs);
            $this->addCrossFKSet($script, $crossFKs);
            $this->addCrossFKCount($script, $crossFKs);
            $this->addCrossFKAdd($script, $crossFKs);
            $this->addCrossFKDoAdd($script, $crossFKs);
            $this->addCrossFKRemove($script, $crossFKs);
            //$this->addCrossFKRemoves($script, $crossFKs);
        }
    }

    /**
     * Adds the method that clears the referrer fkey collection.
     *
     * @param string $script The script will be modified in this method.
     * @param CrossForeignKeys $crossFKs
     *
     * @return void
     */
    protected function addCrossFKClear(string &$script, CrossForeignKeys $crossFKs): void
    {
        $relCol = $this->getCrossFKsPhpNameAffix($crossFKs);
        $collName = $this->getCrossFKsVarName($crossFKs);

        $script .= "
    /**
     * Clears out the {$collName} collection
     *
     * This does not modify the database; however, it will remove any associated objects, causing
     * them to be refetched by subsequent calls to accessor method.
     *
     * @return void
     * @see        add{$relCol}()
     */
    public function clear{$relCol}()
    {
        \$this->$collName = null; // important to set this to NULL since that means it is uninitialized
    }
";
    }

    /**
     * Adds the method that clears the referrer fkey collection.
     *
     * @param string $script The script will be modified in this method.
     * @param ForeignKey $refFK
     *
     * @return void
     */
    protected function addRefFKPartial(string &$script, ForeignKey $refFK): void
    {
        $relCol = $this->getRefFKPhpNameAffix($refFK, true);
        $collName = $this->getRefFKCollVarName($refFK);

        $script .= "
    /**
     * Reset is the $collName collection loaded partially.
     *
     * @param bool \$v
     *
     * @return void
     */
    public function resetPartial{$relCol}(\$v = true): void
    {
        \$this->{$collName}Partial = \$v;
    }
";
    }

    /**
     * Adds the method that initializes the referrer fkey collection.
     *
     * @param string $script The script will be modified in this method.
     * @param CrossForeignKeys $crossFKs
     *
     * @return void
     */
    protected function addCrossFKInit(string &$script, CrossForeignKeys $crossFKs): void
    {
        $inits = [];

        if (1 < count($crossFKs->getCrossForeignKeys()) || $crossFKs->getUnclassifiedPrimaryKeys()) {
            $inits[] = [
                'relCol' => $this->getCrossFKsPhpNameAffix($crossFKs, true),
                'collName' => 'combination' . ucfirst($this->getCrossFKsVarName($crossFKs)),
                'collectionClass' => 'ObjectCombinationCollection',
                'relatedObjectClassName' => false,
                'foreignTableMapName' => false,
            ];
        } else {
            foreach ($crossFKs->getCrossForeignKeys() as $crossFK) {
                $relCol = $this->getFKPhpNameAffix($crossFK, true);
                $collName = $this->getCrossFKVarName($crossFK);
                $relatedObjectClassName = $this->getClassNameFromBuilder(
                    $this->getNewStubObjectBuilder($crossFK->getForeignTable()),
                    true,
                );

                $foreignTableMapName = $this->getClassNameFromBuilder($this->getNewTableMapBuilder($crossFK->getTable()));

                $inits[] = [
                    'relCol' => $relCol,
                    'collName' => $collName,
                    'collectionClass' => false,
                    'relatedObjectClassName' => $relatedObjectClassName,
                    'foreignTableMapName' => $foreignTableMapName,
                ];
            }
        }

        foreach ($inits as $init) {
            $relCol = $init['relCol'];
            $collName = $init['collName'];
            $collectionClass = $init['collectionClass'];
            $relatedObjectClassName = $init['relatedObjectClassName'];
            $foreignTableMapName = $init['foreignTableMapName'];

            $script .= "
    /**
     * Initializes the $collName crossRef collection.
     *
     * By default this just sets the $collName collection to an empty collection (like clear$relCol());
     * however, you may wish to override this method in your stub class to provide setting appropriate
     * to your application -- for example, setting the initial array to the values stored in database.
     *
     * @return void
     */
    public function init$relCol()
    {";
            if ($collectionClass) {
                $script .= "
        \$this->$collName = new $collectionClass;";
            } else {
                $script .= "
        \$collectionClassName = " . $foreignTableMapName . "::getTableMap()->getCollectionClassName();

        \$this->$collName = new \$collectionClassName;";
            }

            $script .= "
        \$this->{$collName}Partial = true;";
            if ($relatedObjectClassName) {
                $script .= "
        \$this->{$collName}->setModel('$relatedObjectClassName');";
            }
            $script .= "
    }
";
        }
    }

    /**
     * Adds the method that check if the referrer fkey collection is initialized.
     *
     * @param string $script The script will be modified in this method.
     * @param CrossForeignKeys $crossFKs
     *
     * @return void
     */
    protected function addCrossFKIsLoaded(string &$script, CrossForeignKeys $crossFKs): void
    {
        $inits = [];

        if (1 < count($crossFKs->getCrossForeignKeys()) || $crossFKs->getUnclassifiedPrimaryKeys()) {
            $inits[] = [
                'relCol' => $this->getCrossFKsPhpNameAffix($crossFKs, true),
                'collName' => 'combination' . ucfirst($this->getCrossFKsVarName($crossFKs)),
            ];
        } else {
            foreach ($crossFKs->getCrossForeignKeys() as $crossFK) {
                $relCol = $this->getFKPhpNameAffix($crossFK, true);
                $collName = $this->getCrossFKVarName($crossFK);

                $inits[] = [
                    'relCol' => $relCol,
                    'collName' => $collName,
                ];
            }
        }

        foreach ($inits as $init) {
            $relCol = $init['relCol'];
            $collName = $init['collName'];

            $script .= "
    /**
     * Checks if the $collName collection is loaded.
     *
     * @return bool
     */
    public function is{$relCol}Loaded(): bool
    {
        return null !== \$this->$collName;
    }
";
        }
    }

    /**
     * @param string $script
     * @param CrossForeignKeys $crossFKs
     *
     * @return void
     */
    protected function addCrossFKCreateQuery(string &$script, CrossForeignKeys $crossFKs): void
    {
        if (1 <= count($crossFKs->getCrossForeignKeys()) && !$crossFKs->getUnclassifiedPrimaryKeys()) {
            return;
        }

        $refFK = $crossFKs->getIncomingForeignKey();
        $selfRelationName = $this->getFKPhpNameAffix($refFK, false);
        $firstFK = $crossFKs->getCrossForeignKeys()[0];
        $firstFkName = $this->getFKPhpNameAffix($firstFK, true);

        $relatedQueryClassName = $this->getClassNameFromBuilder($this->getNewStubQueryBuilder($firstFK->getForeignTable()));
        $signature = $shortSignature = $normalizedShortSignature = $phpDoc = [];
        $this->extractCrossInformation($crossFKs, [$firstFK], $signature, $shortSignature, $normalizedShortSignature, $phpDoc);

        $signature = array_map(function ($item) {
            return $item . ' = null';
        }, $signature);
        $signature = implode(', ', $signature);
        $phpDoc = implode(', ', $phpDoc);

        $relatedUseQueryClassName = $this->getNewStubQueryBuilder($crossFKs->getMiddleTable())->getUnqualifiedClassName();
        $relatedUseQueryGetter = 'use' . ucfirst($relatedUseQueryClassName);
        $relatedUseQueryVariableName = lcfirst($relatedUseQueryClassName);

        $script .= "
    /**
     * Returns a new query object pre configured with filters from current object and given arguments to query the database.
     * $phpDoc
     * @param Criteria \$criteria
     *
     * @return $relatedQueryClassName
     */
    public function create{$firstFkName}Query($signature, ?Criteria \$criteria = null)
    {
        " . $this->getAssertedCurrentChildObjectSnippet() . "
        \$criteria = $relatedQueryClassName::create(\$criteria)
            ->filterBy{$selfRelationName}(\$this);

        \$$relatedUseQueryVariableName = \$criteria->{$relatedUseQueryGetter}();
";

        foreach ($crossFKs->getCrossForeignKeys() as $fk) {
            if ($crossFKs->getIncomingForeignKey() === $fk || $firstFK === $fk) {
                continue;
            }

            $filterName = $fk->getPhpName();
            $name = lcfirst($fk->getPhpName());

            $script .= "
        if (null !== \$$name) {
            \${$relatedUseQueryVariableName}->filterBy{$filterName}(\$$name);
        }
            ";
        }
        foreach ($crossFKs->getUnclassifiedPrimaryKeys() as $pk) {
            $filterName = $pk->getPhpName();
            $name = lcfirst($pk->getPhpName());

            $script .= "
        if (null !== \$$name) {
            \${$relatedUseQueryVariableName}->filterBy{$filterName}(\$$name);
        }
            ";
        }

        $script .= "
        \${$relatedUseQueryVariableName}->endUse();

        return \$criteria;
    }
";
    }

    /**
     * @param string $script
     * @param CrossForeignKeys $crossFKs
     *
     * @return void
     */
    protected function addCrossFKGet(string &$script, CrossForeignKeys $crossFKs): void
    {
        $refFK = $crossFKs->getIncomingForeignKey();
        $selfRelationName = $this->getFKPhpNameAffix($refFK, false);
        $crossRefTableName = $crossFKs->getMiddleTable()->getName();

        if (1 < count($crossFKs->getCrossForeignKeys()) || $crossFKs->getUnclassifiedPrimaryKeys()) {
            $relatedName = $this->getCrossFKsPhpNameAffix($crossFKs, true);
            $collVarName = 'combination' . ucfirst($this->getCrossFKsVarName($crossFKs));

            $classNames = [];
            foreach ($crossFKs->getCrossForeignKeys() as $crossFK) {
                $classNames[] = $this->getClassNameFromBuilder($this->getNewStubObjectBuilder($crossFK->getForeignTable()));
            }
            $classNames = implode(', ', $classNames);
            $relatedQueryClassName = $this->getClassNameFromBuilder($this->getNewStubQueryBuilder($crossFKs->getMiddleTable()));

            $script .= "
    /**
     * Gets a combined collection of $classNames objects related by a many-to-many relationship
     * to the current object by way of the $crossRefTableName cross-reference table.
     *
     * If the \$criteria is not null, it is used to always fetch the results from the database.
     * Otherwise the results are fetched from the database the first time, then cached.
     * Next time the same method is called without \$criteria, the cached collection is returned.
     * If this " . $this->getObjectClassName() . " is new, it will return
     * an empty collection or the current collection; the criteria is ignored on a new object.
     *
     * @param ?Criteria \$criteria Optional query object to filter the query
     * @param ?ConnectionInterface \$con Optional connection object
     *
     * @return ObjectCombinationCollection Combination list of {$classNames} objects
     */
    public function get{$relatedName}(?Criteria \$criteria = null, ?ConnectionInterface \$con = null)
    {
        \$partial = \$this->{$collVarName}Partial && !\$this->isNew();
        if (null === \$this->$collVarName || null !== \$criteria || \$partial) {
            if (\$this->isNew()) {
                // return empty collection
                if (null === \$this->$collVarName) {
                    \$this->init{$relatedName}();
                }
            } else {
                " . $this->getAssertedCurrentChildObjectSnippet() . "

                \$query = $relatedQueryClassName::create(null, \$criteria)
                    ->filterBy{$selfRelationName}(\$this)";
            foreach ($crossFKs->getCrossForeignKeys() as $fk) {
                $varName = $this->getFKPhpNameAffix($fk, false);
                $script .= "
                    ->join{$varName}()";
            }

            $script .= "
                ;

                \$items = \$query->find(\$con);
                \$$collVarName = new ObjectCombinationCollection();
                foreach (\$items as \$item) {
                    \$combination = [];
";

            foreach ($crossFKs->getCrossForeignKeys() as $fk) {
                $varName = $this->getFKPhpNameAffix($fk, false);
                $script .= "
                    \$combination[] = \$item->get{$varName}();";
            }

            foreach ($crossFKs->getUnclassifiedPrimaryKeys() as $pk) {
                $varName = $pk->getPhpName();
                $script .= "
                    \$combination[] = \$item->get{$varName}();";
            }

            $script .= "
                    \${$collVarName}[] = \$combination;
                }

                if (null !== \$criteria) {
                    return \$$collVarName;
                }

                if (\$partial && \$this->{$collVarName}) {
                    //make sure that already added objects gets added to the list of the database.
                    foreach (\$this->{$collVarName} as \$obj) {
                        if (!\${$collVarName}->contains(...\$obj)) {
                            \${$collVarName}[] = \$obj;
                        }
                    }
                }

                \$this->$collVarName = \$$collVarName;
                \$this->{$collVarName}Partial = false;
            }
        }

        return \$this->$collVarName;
    }
";

            $relatedName = $this->getCrossFKsPhpNameAffix($crossFKs, true);
            $firstFK = $crossFKs->getCrossForeignKeys()[0];
            $firstFkName = $this->getFKPhpNameAffix($firstFK, true);

            $relatedObjectClassName = $this->getClassNameFromBuilder($this->getNewStubObjectBuilder($firstFK->getForeignTable()));
            $signature = $shortSignature = $normalizedShortSignature = $phpDoc = [];
            $this->extractCrossInformation($crossFKs, [$firstFK], $signature, $shortSignature, $normalizedShortSignature, $phpDoc);

            $signature = array_map(function ($item) {
                return $item . ' = null';
            }, $signature);
            $signature = implode(', ', $signature);
            $phpDoc = implode(', ', $phpDoc);
            $shortSignature = implode(', ', $shortSignature);

            $script .= "
    /**
     * Returns a not cached ObjectCollection of $relatedObjectClassName objects. This will hit always the databases.
     * If you have attached new $relatedObjectClassName object to this object you need to call `save` first to get
     * the correct return value. Use get$relatedName() to get the current internal state.
     * $phpDoc
     * @param ?Criteria \$criteria
     * @param ?ConnectionInterface \$con
     *
     * @return ObjectCollection&\Traversable<{$relatedObjectClassName}>
     */
    public function get{$firstFkName}($signature, ?Criteria \$criteria = null, ?ConnectionInterface \$con = null)
    {
        return \$this->create{$firstFkName}Query($shortSignature, \$criteria)->find(\$con);
    }
";

            return;
        }

        foreach ($crossFKs->getCrossForeignKeys() as $crossFK) {
            $relatedName = $this->getFKPhpNameAffix($crossFK, true);
            $relatedObjectClassName = $this->getClassNameFromBuilder($this->getNewStubObjectBuilder($crossFK->getForeignTable()));
            $relatedQueryClassName = $this->getClassNameFromBuilder($this->getNewStubQueryBuilder($crossFK->getForeignTable()));

            $collName = $this->getCrossFKVarName($crossFK);

            $script .= "
    /**
     * Gets a collection of $relatedObjectClassName objects related by a many-to-many relationship
     * to the current object by way of the $crossRefTableName cross-reference table.
     *
     * If the \$criteria is not null, it is used to always fetch the results from the database.
     * Otherwise the results are fetched from the database the first time, then cached.
     * Next time the same method is called without \$criteria, the cached collection is returned.
     * If this " . $this->getObjectClassName() . " is new, it will return
     * an empty collection or the current collection; the criteria is ignored on a new object.
     *
     * @param ?Criteria \$criteria Optional query object to filter the query
     * @param ?ConnectionInterface \$con Optional connection object
     *
     * @return ObjectCollection&\Traversable<{$relatedObjectClassName}> List of {$relatedObjectClassName} objects
     */
    public function get{$relatedName}(?Criteria \$criteria = null, ?ConnectionInterface \$con = null)
    {
        \$partial = \$this->{$collName}Partial && !\$this->isNew();
        if (null === \$this->$collName || null !== \$criteria || \$partial) {
            if (\$this->isNew()) {
                // return empty collection
                if (null === \$this->$collName) {
                    \$this->init{$relatedName}();
                }
            } else {
                " . $this->getAssertedCurrentChildObjectSnippet() . "

                \$query = $relatedQueryClassName::create(null, \$criteria)
                    ->filterBy{$selfRelationName}(\$this);
                \$$collName = \$query->find(\$con);
                if (null !== \$criteria) {
                    return \$$collName;
                }

                if (\$partial && \$this->{$collName}) {
                    //make sure that already added objects gets added to the list of the database.
                    foreach (\$this->{$collName} as \$obj) {
                        if (!\${$collName}->contains(\$obj)) {
                            \${$collName}[] = \$obj;
                        }
                    }
                }

                \$this->$collName = \$$collName;
                \$this->{$collName}Partial = false;
            }
        }

        return \$this->$collName;
    }
";
        }
    }

    /**
     * @param string $script
     * @param CrossForeignKeys $crossFKs
     *
     * @return void
     */
    protected function addCrossFKSet(string &$script, CrossForeignKeys $crossFKs): void
    {
        $scheduledForDeletionVarName = $this->getCrossScheduledForDeletionVarName($crossFKs);

        $multi = 1 < count($crossFKs->getCrossForeignKeys()) || (bool)$crossFKs->getUnclassifiedPrimaryKeys();

        $relatedNamePlural = $this->getCrossFKsPhpNameAffix($crossFKs, true);
        $relatedName = $this->getCrossFKsPhpNameAffix($crossFKs, false);
        $inputCollection = lcfirst($relatedNamePlural);
        $foreachItem = lcfirst($relatedName);
        $crossRefTableName = $crossFKs->getMiddleTable()->getName();

        if ($multi) {
            [$relatedObjectClassName] = $this->getCrossFKInformation($crossFKs);
            $collName = 'combination' . ucfirst($this->getCrossFKsVarName($crossFKs));
        } else {
            $crossFK = $crossFKs->getCrossForeignKeys()[0];
            $relatedObjectClassName = $this->getNewStubObjectBuilder($crossFK->getForeignTable())->getUnqualifiedClassName();
            $collName = $this->getCrossFKVarName($crossFK);
            $containsCurrentRelatedObject = "\$current{$relatedNamePlural} instanceof \\Propel\\Runtime\\Collection\\ObjectCollection\n"
                . "                ? \$current{$relatedNamePlural}->containsInstance(\${$foreachItem})\n"
                . "                : \$current{$relatedNamePlural}->contains(\${$foreachItem})";
        }

        $script .= "
    /**
     * Sets a collection of $relatedObjectClassName objects related by a many-to-many relationship
     * to the current object by way of the $crossRefTableName cross-reference table.
     * It will also schedule objects for deletion based on a diff between old objects (aka persisted)
     * and new objects from the given Propel collection.
     *
     * @param Collection \${$inputCollection} A Propel collection.
     * @param ?ConnectionInterface \$con Optional connection object
     * @return \$this The current object (for fluent API support)
     */
    public function set{$relatedNamePlural}(Collection \${$inputCollection}, ?ConnectionInterface \$con = null)
    {
        \$this->clear{$relatedNamePlural}();
        \$current{$relatedNamePlural} = \$this->get{$relatedNamePlural}();

        \${$scheduledForDeletionVarName} = \$current{$relatedNamePlural}->diff(\${$inputCollection});

        foreach (\${$scheduledForDeletionVarName} as \$toDelete) {";
        if ($multi) {
            $script .= "
            \$this->remove{$relatedName}(...\$toDelete);";
        } else {
            $script .= "
            \$this->remove{$relatedName}(\$toDelete);";
        }
        $script .= "
        }

        foreach (\${$inputCollection} as \${$foreachItem}) {";
        if ($multi) {
            $script .= "
            if (!\$current{$relatedNamePlural}->contains(...\${$foreachItem})) {
                \$this->doAdd{$relatedName}(...\${$foreachItem});
            }";
        } else {
            $script .= "
            if (!({$containsCurrentRelatedObject})) {
                \$this->doAdd{$relatedName}(\${$foreachItem});
            }";
        }
        $script .= "
        }

        \$this->{$collName}Partial = false;
        \$this->$collName = \${$inputCollection};

        return \$this;
    }
";
    }

    /**
     * @param string $script
     * @param CrossForeignKeys $crossFKs
     *
     * @return void
     */
    protected function addCrossFKCount(string &$script, CrossForeignKeys $crossFKs): void
    {
        $refFK = $crossFKs->getIncomingForeignKey();
        $selfRelationName = $this->getFKPhpNameAffix($refFK, false);

        $multi = 1 < count($crossFKs->getCrossForeignKeys()) || (bool)$crossFKs->getUnclassifiedPrimaryKeys();

        $relatedName = $this->getCrossFKsPhpNameAffix($crossFKs, true);
        $crossRefTableName = $crossFKs->getMiddleTable()->getName();

        if ($multi) {
            [$relatedObjectClassName] = $this->getCrossFKInformation($crossFKs);
            $collName = 'combination' . ucfirst($this->getCrossFKsVarName($crossFKs));
            $relatedQueryClassName = $this->getClassNameFromBuilder($this->getNewStubQueryBuilder($crossFKs->getMiddleTable()));
        } else {
            $crossFK = $crossFKs->getCrossForeignKeys()[0];
            $relatedObjectClassName = $this->getNewStubObjectBuilder($crossFK->getForeignTable())->getUnqualifiedClassName();
            $collName = $this->getCrossFKVarName($crossFK);
            $relatedQueryClassName = $this->getClassNameFromBuilder($this->getNewStubQueryBuilder($crossFK->getForeignTable()));
        }

        $script .= "
    /**
     * Gets the number of $relatedObjectClassName objects related by a many-to-many relationship
     * to the current object by way of the $crossRefTableName cross-reference table.
     *
     * @param ?Criteria \$criteria Optional query object to filter the query
     * @param bool \$distinct Set to true to force count distinct
     * @param ?ConnectionInterface \$con Optional connection object
     *
     * @return int The number of related $relatedObjectClassName objects
     */
    public function count{$relatedName}(?Criteria \$criteria = null, \$distinct = false, ?ConnectionInterface \$con = null): int
    {
        \$partial = \$this->{$collName}Partial && !\$this->isNew();
        if (null === \$this->$collName || null !== \$criteria || \$partial) {
            if (\$this->isNew() && null === \$this->$collName) {
                return 0;
            } else {

                if (\$partial && !\$criteria) {
                    return count(\$this->get$relatedName());
                }

                \$query = $relatedQueryClassName::create(null, \$criteria);
                if (\$distinct) {
                    \$query->distinct();
                }

                " . $this->getAssertedCurrentChildObjectSnippet() . "
                return \$query
                    ->filterBy{$selfRelationName}(\$this)
                    ->count(\$con);
            }
        } else {
            return count(\$this->$collName);
        }
    }
";

        if ($multi) {
            $relatedName = $this->getCrossFKsPhpNameAffix($crossFKs, true);
            $firstFK = $crossFKs->getCrossForeignKeys()[0];
            $firstFkName = $this->getFKPhpNameAffix($firstFK, true);

            $relatedObjectClassName = $this->getClassNameFromBuilder($this->getNewStubObjectBuilder($firstFK->getForeignTable()));
            $signature = $shortSignature = $normalizedShortSignature = $phpDoc = [];
            $this->extractCrossInformation($crossFKs, [$firstFK], $signature, $shortSignature, $normalizedShortSignature, $phpDoc);

            $signature = array_map(function ($item) {
                return $item . ' = null';
            }, $signature);
            $signature = implode(', ', $signature);
            $phpDoc = implode(', ', $phpDoc);
            $shortSignature = implode(', ', $shortSignature);

            $script .= "
    /**
     * Returns the not cached count of $relatedObjectClassName objects. This will hit always the databases.
     * If you have attached new $relatedObjectClassName object to this object you need to call `save` first to get
     * the correct return value. Use get$relatedName() to get the current internal state.
     * $phpDoc
     * @param ?Criteria \$criteria
     * @param ?ConnectionInterface \$con
     *
     * @return int
     */
    public function count{$firstFkName}($signature, ?Criteria \$criteria = null, ?ConnectionInterface \$con = null): int
    {
        return \$this->create{$firstFkName}Query($shortSignature, \$criteria)->count(\$con);
    }
";
        }
    }

    /**
     * Adds the method that adds an object into the referrer fkey collection.
     *
     * @param string $script The script will be modified in this method.
     * @param CrossForeignKeys $crossFKs
     *
     * @return void
     */
    protected function addCrossFKAdd(string &$script, CrossForeignKeys $crossFKs): void
    {
        $refFK = $crossFKs->getIncomingForeignKey();

        foreach ($crossFKs->getCrossForeignKeys() as $crossFK) {
            $relSingleNamePlural = $this->getFKPhpNameAffix($crossFK, true);
            $relSingleName = $this->getFKPhpNameAffix($crossFK, false);
            $collSingleName = $this->getCrossFKVarName($crossFK);

            $relCombineNamePlural = $this->getCrossFKsPhpNameAffix($crossFKs, true);
            $relCombineName = $this->getCrossFKsPhpNameAffix($crossFKs, false);
            $collCombinationVarName = 'combination' . ucfirst($this->getCrossFKsVarName($crossFKs));

            $collName = 1 < count($crossFKs->getCrossForeignKeys()) || $crossFKs->getUnclassifiedPrimaryKeys() ? $collCombinationVarName : $collSingleName;
            $relNamePlural = ucfirst(1 < count($crossFKs->getCrossForeignKeys()) || $crossFKs->getUnclassifiedPrimaryKeys() ? $relCombineNamePlural : $relSingleNamePlural);
            $relName = ucfirst(1 < count($crossFKs->getCrossForeignKeys()) || $crossFKs->getUnclassifiedPrimaryKeys() ? $relCombineName : $relSingleName);

            $tblFK = $refFK->getTable();
            $relatedObjectClassName = $this->getFKPhpNameAffix($crossFK, false);
            $crossObjectClassName = $this->getClassNameFromTable($crossFK->getForeignTable());
            [$signature, $shortSignature, $normalizedShortSignature, $phpDoc] = $this->getCrossFKAddMethodInformation($crossFKs, $crossFK);
            $containsRelatedObject = "\$this->get{$relNamePlural}() instanceof \\Propel\\Runtime\\Collection\\ObjectCollection\n"
                . "            ? \$this->get{$relNamePlural}()->containsInstance({$normalizedShortSignature})\n"
                . "            : \$this->get{$relNamePlural}()->contains({$normalizedShortSignature})";

            $script .= "
    /**
     * Associate a $crossObjectClassName to this object
     * through the " . $tblFK->getName() . " cross reference table.
     * $phpDoc
     * @return " . $this->getObjectClassname() . " The current object (for fluent API support)
     */
    public function add{$relatedObjectClassName}($signature)
    {
        if (\$this->" . $collName . " === null) {
            \$this->init" . $relNamePlural . "();
        }

        if (!({$containsRelatedObject})) {
            // only add it if the **same** object is not already associated
            \$this->" . $collName . '->push(' . $normalizedShortSignature . ");
            \$this->doAdd{$relName}($normalizedShortSignature);
        }

        return \$this;
    }
";
        }
    }

    /**
     * Returns a function signature comma separated.
     *
     * @param CrossForeignKeys $crossFKs
     * @param string $excludeSignatureItem Which variable to exclude.
     *
     * @return string
     */
    protected function getCrossFKGetterSignature(CrossForeignKeys $crossFKs, string $excludeSignatureItem): string
    {
        [, $getSignature] = $this->getCrossFKAddMethodInformation($crossFKs);
        $getSignature = explode(', ', $getSignature);

        $pos = array_search($excludeSignatureItem, $getSignature);
        if ($pos !== false) {
            unset($getSignature[$pos]);
        }

        return implode(', ', $getSignature);
    }

    /**
     * @param string $script The script will be modified in this method.
     * @param CrossForeignKeys $crossFKs
     *
     * @return void
     */
    protected function addCrossFKDoAdd(string &$script, CrossForeignKeys $crossFKs): void
    {
        $selfRelationNamePlural = $this->getFKPhpNameAffix($crossFKs->getIncomingForeignKey(), true);
        $relatedObjectClassName = $this->getCrossFKsPhpNameAffix($crossFKs, false);
        $className = $this->getClassNameFromTable($crossFKs->getIncomingForeignKey()->getTable());

        $refKObjectClassName = $this->getRefFKPhpNameAffix($crossFKs->getIncomingForeignKey(), false);
        $tblFK = $crossFKs->getIncomingForeignKey()->getTable();
        $foreignObjectName = '$' . $tblFK->getCamelCaseName();

        [$signature, $shortSignature, $normalizedShortSignature, $phpDoc] = $this->getCrossFKAddMethodInformation($crossFKs);

        $script .= "
    /**
     * {$phpDoc}
     */
    protected function doAdd{$relatedObjectClassName}($signature)
    {
        {$foreignObjectName} = new {$className}();
        " . $this->getAssertedCurrentChildObjectSnippet() . "
";
        if (1 < count($crossFKs->getCrossForeignKeys()) || $crossFKs->getUnclassifiedPrimaryKeys()) {
            foreach ($crossFKs->getCrossForeignKeys() as $crossFK) {
                $relatedObjectClassName = $this->getFKPhpNameAffix($crossFK, false);
                $lowerRelatedObjectClassName = lcfirst($relatedObjectClassName);
                $setterCall = $this->getForeignKeyAssociationCall($crossFK, $foreignObjectName, '$' . $lowerRelatedObjectClassName);
                $script .= "
        {$setterCall}";
            }

            foreach ($crossFKs->getUnclassifiedPrimaryKeys() as $primaryKey) {
                $paramName = lcfirst($primaryKey->getPhpName());
                $script .= "
        {$foreignObjectName}->set{$primaryKey->getPhpName()}(\$$paramName);
";
            }
        } else {
            $crossFK = $crossFKs->getCrossForeignKeys()[0];
            $relatedObjectClassName = $this->getFKPhpNameAffix($crossFK, false);
            $lowerRelatedObjectClassName = lcfirst($relatedObjectClassName);
            $setterCall = $this->getForeignKeyAssociationCall($crossFK, $foreignObjectName, '$' . $lowerRelatedObjectClassName);
            $script .= "
        {$setterCall}";
        }

        $refFK = $crossFKs->getIncomingForeignKey();
        $incomingSetterCall = $this->getForeignKeyAssociationCall($refFK, $foreignObjectName, '$this');
        $script .= "

        {$incomingSetterCall}

        \$this->add{$refKObjectClassName}({$foreignObjectName});\n";

        if (1 < count($crossFKs->getCrossForeignKeys()) || $crossFKs->getUnclassifiedPrimaryKeys()) {
            foreach ($crossFKs->getCrossForeignKeys() as $crossFK) {
                $relatedObjectClassName = $this->getFKPhpNameAffix($crossFK, false);
                $lowerRelatedObjectClassName = lcfirst($relatedObjectClassName);

                $getterName = $this->getCrossRefFKGetterName($crossFKs, $crossFK);
                $getterRemoveObjectName = $this->getCrossRefFKRemoveObjectNames($crossFKs, $crossFK);

                $script .= "
        // set the back reference to this object directly as using provided method either results
        // in endless loop or in multiple relations
        if (\${$lowerRelatedObjectClassName}->is{$getterName}Loaded()) {
            if (!\${$lowerRelatedObjectClassName}->get{$getterName}()->contains($getterRemoveObjectName)) {
                \${$lowerRelatedObjectClassName}->get{$getterName}()->push($getterRemoveObjectName);
            }
        } elseif (!\${$lowerRelatedObjectClassName}->get{$getterName}()->contains($getterRemoveObjectName)) {
            \${$lowerRelatedObjectClassName}->get{$getterName}()->push($getterRemoveObjectName);
        }\n";
            }
        } else {
            $relatedObjectClassName = $this->getFKPhpNameAffix($crossFK, false);
            $lowerRelatedObjectClassName = lcfirst($relatedObjectClassName);
            $getterSignature = $this->getCrossFKGetterSignature($crossFKs, '$' . $lowerRelatedObjectClassName);
            $containsBackReference = "\${$lowerRelatedObjectClassName}->get{$selfRelationNamePlural}($getterSignature) instanceof \\Propel\\Runtime\\Collection\\ObjectCollection\n"
                . "            ? \${$lowerRelatedObjectClassName}->get{$selfRelationNamePlural}($getterSignature)->containsInstance(\$this)\n"
                . "            : \${$lowerRelatedObjectClassName}->get{$selfRelationNamePlural}($getterSignature)->contains(\$this)";
            $script .= "
        // set the back reference to this object directly as using provided method either results
        // in endless loop or in multiple relations
        if (!\${$lowerRelatedObjectClassName}->is{$selfRelationNamePlural}Loaded()) {
            \${$lowerRelatedObjectClassName}->init{$selfRelationNamePlural}();
            \${$lowerRelatedObjectClassName}->get{$selfRelationNamePlural}($getterSignature)->push(\$this);
        } elseif (!({$containsBackReference})) {
            \${$lowerRelatedObjectClassName}->get{$selfRelationNamePlural}($getterSignature)->push(\$this);
        }\n";
        }

        $script .= "
    }
";
    }

    /**
     * @param CrossForeignKeys $crossFKs
     * @param ForeignKey $excludeFK
     *
     * @return string
     */
    protected function getCrossRefFKRemoveObjectNames(CrossForeignKeys $crossFKs, ForeignKey $excludeFK): string
    {
        $names = [];

        $fks = $crossFKs->getCrossForeignKeys();

        foreach ($crossFKs->getMiddleTable()->getForeignKeys() as $fk) {
            if ($fk !== $excludeFK && ($fk === $crossFKs->getIncomingForeignKey() || in_array($fk, $fks))) {
                if ($fk === $crossFKs->getIncomingForeignKey()) {
                    $names[] = '$this';
                } else {
                    $names[] = '$' . lcfirst($this->getFKPhpNameAffix($fk, false));
                }
            }
        }

        foreach ($crossFKs->getUnclassifiedPrimaryKeys() as $pk) {
            $names[] = '$' . lcfirst($pk->getPhpName());
        }

        return implode(', ', $names);
    }

    protected function isForeignKeyPartOfGeneratedHashCode(ForeignKey $fk): bool
    {
        return $fk->isAtLeastOneLocalPrimaryKey();
    }

    protected function getInternalFKAssociationMethodName(ForeignKey $fk): string
    {
        return 'associate' . $this->getFKPhpNameAffix($fk, false) . 'WithoutInverseSync';
    }

    protected function getForeignKeyAssociationCall(ForeignKey $fk, string $targetObjectName, string $relatedObjectName): string
    {
        if ($this->isForeignKeyPartOfGeneratedHashCode($fk)) {
            return $targetObjectName . '->' . $this->getInternalFKAssociationMethodName($fk) . '(' . $relatedObjectName . ');';
        }

        return $targetObjectName . '->set' . $this->getFKPhpNameAffix($fk, false) . '(' . $relatedObjectName . ');';
    }

    protected function addFKAssociationAssignments(string &$script, ForeignKey $fk, string $varName): void
    {
        $isNullableRelation = !$fk->getLocalColumn()->isNotNull();

        foreach ($fk->getMapping() as $map) {
            [$column, $rightValueOrColumn] = $map;

            if ($rightValueOrColumn instanceof Column) {
                $suffix = $rightValueOrColumn->isNotNull() ? '' : ' ?? ' . $this->getDefaultValueForColumn($column, false);
                if ($isNullableRelation) {
                    $nullValue = $column->hasDefaultValue() ? $this->getDefaultValueString($column, false) : 'null';
                    $script .= "
        \$this->set" . $column->getPhpName() . "(\$v === null ? $nullValue : (\$v->isNew() ? null : \$v->get" . $rightValueOrColumn->getPhpName() . "()$suffix));
    ";
                } else {
                    $script .= "
        \$this->set" . $column->getPhpName() . "(\$v->get" . $rightValueOrColumn->getPhpName() . "()$suffix);
";
                }
            } else {
                $val = var_export($rightValueOrColumn, true);
                $script .= "
        if (\$v === null) {
            \$this->set" . $column->getPhpName() . "(null);
        } else {
            \$this->set" . $column->getPhpName() . "($val);
        }
                ";
            }
        }

        $script .= "
        \$this->$varName = \$v;
";
    }

    /**
     * Adds the method that remove an object from the referrer fkey collection.
     *
     * @param string $script The script will be modified in this method.
     * @param CrossForeignKeys $crossFKs
     *
     * @return void
     */
    protected function addCrossFKRemove(string &$script, CrossForeignKeys $crossFKs): void
    {
        $relCol = $this->getCrossFKsPhpNameAffix($crossFKs, true);
        if (1 < count($crossFKs->getCrossForeignKeys()) || $crossFKs->getUnclassifiedPrimaryKeys()) {
            $collName = 'combination' . ucfirst($this->getCrossFKsVarName($crossFKs));
        } else {
            $collName = $this->getCrossFKsVarName($crossFKs);
        }

        $tblFK = $crossFKs->getIncomingForeignKey()->getTable();

        $M2MScheduledForDeletion = $this->getCrossScheduledForDeletionVarName($crossFKs);
        $relatedObjectClassName = $this->getCrossFKsPhpNameAffix($crossFKs, false);

        [$signature, $shortSignature, $normalizedShortSignature, $phpDoc] = $this->getCrossFKAddMethodInformation($crossFKs);
        $names = str_replace('$', '', $normalizedShortSignature);

        $className = $this->getClassNameFromTable($crossFKs->getIncomingForeignKey()->getTable());
        $refKObjectClassName = $this->getRefFKPhpNameAffix($crossFKs->getIncomingForeignKey(), false);
        $foreignObjectName = '$' . $tblFK->getCamelCaseName();

        $script .= "
    /**
     * Remove $names of this object
     * through the {$tblFK->getName()} cross reference table.
     * $phpDoc
     * @return " . $this->getObjectClassname() . " The current object (for fluent API support)
     */
    public function remove{$relatedObjectClassName}($signature)
    {
        if (\$this->get{$relCol}()->contains({$shortSignature})) {
            {$foreignObjectName} = new {$className}();
            " . $this->getAssertedCurrentChildObjectSnippet() . "
";
        foreach ($crossFKs->getCrossForeignKeys() as $crossFK) {
            $relatedObjectClassName = $this->getFKPhpNameAffix($crossFK, false);
            $lowerRelatedObjectClassName = lcfirst($relatedObjectClassName);

            $relatedObjectClassName = $this->getFKPhpNameAffix($crossFK, false);
            $script .= "
            {$foreignObjectName}->set{$relatedObjectClassName}(\${$lowerRelatedObjectClassName});";

            $lowerRelatedObjectClassName = lcfirst($relatedObjectClassName);

            $getterName = $this->getCrossRefFKGetterName($crossFKs, $crossFK);
            $getterRemoveObjectName = $this->getCrossRefFKRemoveObjectNames($crossFKs, $crossFK);

            $script .= "
            if (\${$lowerRelatedObjectClassName}->is{$getterName}Loaded()) {
                //remove the back reference if available
                \${$lowerRelatedObjectClassName}->get$getterName()->removeObject($getterRemoveObjectName);
            }\n";
        }

        foreach ($crossFKs->getUnclassifiedPrimaryKeys() as $primaryKey) {
            $paramName = lcfirst($primaryKey->getPhpName());
            $script .= "
            {$foreignObjectName}->set{$primaryKey->getPhpName()}(\$$paramName);";
        }
        $script .= "
            {$foreignObjectName}->set{$this->getFKPhpNameAffix($crossFKs->getIncomingForeignKey())}(\$this);";

        $script .= "
            \$this->remove{$refKObjectClassName}(clone {$foreignObjectName});
            {$foreignObjectName}->clear();

            \$this->{$collName}->remove(\$this->{$collName}->search({$shortSignature}));
            ";
        $script .= "
            if (null === \$this->{$M2MScheduledForDeletion}) {
                \$this->{$M2MScheduledForDeletion} = clone \$this->{$collName};
                \$this->{$M2MScheduledForDeletion}->clear();
            }

            \$this->{$M2MScheduledForDeletion}->push({$shortSignature});
        }
";

        $script .= "

        return \$this;
    }
";
    }

    /**
     * Returns the timestamp source expression for generated UID primary keys.
     *
     * @param Column $column
     *
     * @return string
     */
    protected function getUidGenerationArgument(Column $column): string
    {
        $timestampable = $this->getTable()->getBehavior('timestampable');
        if ($timestampable === null) {
            return 'null';
        }

        $disableCreatedAt = $timestampable->getParameter('disable_created_at');
        if ($disableCreatedAt === 'true') {
            return 'null';
        }

        $createColumnName = (string)($timestampable->getParameter('create_column') ?? 'created_at');
        if (!$this->getTable()->hasColumn($createColumnName)) {
            return 'null';
        }

        $createColumn = $this->getTable()->getColumn($createColumnName);
        $createColumnAccessor = $createColumn->getLowercasedName();

        return '$this->' . $createColumnAccessor;
    }

    /**
     * Adds the workhourse doSave() method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addDoSave(string &$script): void
    {
        $table = $this->getTable();

        $reloadOnUpdate = $table->isReloadOnUpdate();
        $reloadOnInsert = $table->isReloadOnInsert();

        $script .= "
    /**
     * Performs the work of inserting or updating the row in the database.
     *
     * If the object is new, it inserts it; otherwise an update is performed.
     * All related objects are also updated in this method.
     *
     * @param ConnectionInterface \$con";
        if ($reloadOnUpdate || $reloadOnInsert) {
            $script .= "
     * @param bool \$skipReload Whether to skip the reload for this object from database.";
        }
        $script .= "
     * @return int The number of rows affected by this insert/update and any referring fk objects' save() operations.
     * @throws \Propel\Runtime\Exception\PropelException
     * @see save()
     */
    protected function doSave(ConnectionInterface \$con" . ($reloadOnUpdate || $reloadOnInsert ? ', bool $skipReload = false' : '') . "): int
    {
        \$affectedRows = 0; // initialize var to track total num of affected rows
        if (!\$this->alreadyInSave) {
            \$this->alreadyInSave = true;
";
        if ($reloadOnInsert || $reloadOnUpdate) {
            $script .= "
            \$reloadObject = false;
";
        }

        foreach ($table->getPrimaryKey() as $column) {
            if ($this->fixPrimaryKeyDefaultValues ?? true) {
                $defaultValue = $this->getDefaultValueForColumn($column);
                $script .= "
            if (\$this->{$column->getLowercasedName()} === $defaultValue) {
                \$this->{$column->getLowercasedName()} = null;
            }
";
            }
        }

        if ($this->getPlatform() instanceof MysqlPlatform) {
            foreach ($table->getPrimaryKey() as $column) {
                if (!$column->isUidType()) {
                    continue;
                }

                $columnName = $column->getLowercasedName();
                $uidGenerationArgument = $this->getUidGenerationArgument($column);
                $script .= "
            if (\$this->isNew() && \$this->$columnName === null) {
                \$this->$columnName = UuidConverter::generateV7Uid($uidGenerationArgument);
                \$this->modifiedColumns[" . $this->getColumnConstant($column) . "] = true;
            }
";
            }
        }

        if (count($table->getForeignKeys())) {
            $script .= "
            // We call the save method on the following object(s) if they
            // were passed to this object by their corresponding set
            // method.  This object relates to these object(s) by a
            // foreign key reference.
";

            foreach ($table->getForeignKeys() as $fk) {
                $aVarName = $this->getFKVarName($fk);
                $script .= "
            if (\$this->$aVarName !== null) {
                if (\$this->" . $aVarName . '->isModified() || $this->' . $aVarName . "->isNew()) {
                    \$affectedRows += \$this->" . $aVarName . "->save(\$con);
                }
                \$this->set" . $this->getFKPhpNameAffix($fk, false) . "(\$this->$aVarName);
            }
";
            }
        }

        $script .= "
            if (\$this->isNew() || \$this->isModified()) {
                // persist changes
                if (\$this->isNew()) {
                    \$this->doInsert(\$con);
                    \$affectedRows += 1;";
        if ($reloadOnInsert) {
            $script .= "
                    if (!\$skipReload) {
                        \$reloadObject = true;
                    }";
        }
        $script .= "
                } else {
                    \$affectedRows += \$this->doUpdate(\$con);";
        if ($reloadOnUpdate) {
            $script .= "
                    if (!\$skipReload) {
                        \$reloadObject = true;
                    }";
        }
        $script .= "
                }";

        // We need to rewind any LOB columns
        foreach ($table->getColumns() as $col) {
            $clo = $col->getLowercasedName();
            if ($col->isLobType()) {
                $script .= "
                // Rewind the $clo LOB column, since PDO does not rewind after inserting value.
                if (\$this->$clo !== null && is_resource(\$this->$clo)) {
                    rewind(\$this->$clo);
                }
";
            }
        }

        $script .= "
                \$this->resetModified();
            }
";

        if ($table->hasCrossForeignKeys()) {
            foreach ($table->getCrossFks() as $crossFKs) {
                $this->addCrossFkScheduledForDeletion($script, $crossFKs);
            }
        }

        foreach ($table->getReferrersForCodeGeneration() as $refFK) {
            if ($refFK->isLocalPrimaryKey()) {
                $varName = $this->getPKRefFKVarName($refFK);
                $script .= "
            if (\$this->$varName !== null) {
                if (!\$this->{$varName}->isDeleted() && (\$this->{$varName}->isNew() || \$this->{$varName}->isModified())) {
                    \$affectedRows += \$this->{$varName}->save(\$con);
                }
            }
";
            } else {
                $this->addRefFkScheduledForDeletion($script, $refFK);

                $collName = $this->getRefFKCollVarName($refFK);
                $script .= "
            if (\$this->$collName !== null) {
                foreach (\$this->$collName as \$referrerFK) {
                    if (!\$referrerFK->isDeleted() && (\$referrerFK->isNew() || \$referrerFK->isModified())) {
                        \$affectedRows += \$referrerFK->save(\$con);
                    }
                }
            }
";
            }
        }

        $script .= "
            \$this->alreadyInSave = false;
";
        if ($reloadOnInsert || $reloadOnUpdate) {
            $script .= "
            if (\$reloadObject) {
                \$this->reload((bool)\$con);
            }
";
        }
        $script .= "
        }

        return \$affectedRows;
    }
";
    }

    /**
     * get the doInsert() method code
     *
     * @return string the doInsert() method code
     */
    protected function addDoInsert(): string
    {
        return "
    /**
     * Insert the row in the database.
     *
     * @param ConnectionInterface \$con
     *
     * @throws \Propel\Runtime\Exception\PropelException
     * @see doSave()
     */
    protected function doInsert(ConnectionInterface \$con): void
    {" . $this->addDoInsertBodyRaw() . "
        \$this->setNew(false);
    }
";
    }

    /**
     * @return string
     */
    protected function addDoInsertBodyStandard(): string
    {
        return "
        \$pk = \$criteria->doInsert(\$con);";
    }

    /**
     * @return string
     */
    protected function addDoInsertBodyWithIdMethod(): string
    {
        $table = $this->getTable();
        $script = '';
        foreach ($table->getPrimaryKey() as $col) {
            if (!$col->isAutoIncrement()) {
                continue;
            }
            $colConst = $this->getColumnConstant($col);
            if (!$table->isAllowPkInsert()) {
                $script .= "
        if (\$criteria->keyContainsValue($colConst) ) {
            throw new PropelException('Cannot insert a value for auto-increment primary key (' . $colConst . ')');
        }";
                if (!$this->getPlatform()->supportsInsertNullPk()) {
                    $script .= "
        // remove pkey col since this table uses auto-increment and passing a null value for it is not valid
        \$criteria->remove($colConst);";
                }
            } elseif (!$this->getPlatform()->supportsInsertNullPk()) {
                $script .= "
        // remove pkey col if it is null since this table does not accept that
        if (\$criteria->containsKey($colConst) && !\$criteria->keyContainsValue($colConst) ) {
            \$criteria->remove($colConst);
        }";
            }
        }

        $script .= $this->addDoInsertBodyStandard();

        foreach ($table->getPrimaryKey() as $col) {
            if (!$col->isAutoIncrement()) {
                continue;
            }
            if ($table->isAllowPkInsert()) {
                $script .= "
        if (\$pk !== false) {
            \$this->set" . $col->getPhpName() . "(\$pk);  //[IMV] update autoincrement primary key
        }";
            } else {
                $script .= "
        \$this->set" . $col->getPhpName() . '($pk);  //[IMV] update autoincrement primary key';
            }
        }

        return $script;
    }

    /**
     * Boosts ActiveRecord::doInsert() by doing more calculations at buildtime.
     *
     * @throws PropelException
     *
     * @return string
     */
    protected function addDoInsertBodyRaw(): string
    {
        $this->declareClasses(
            '\Propel\Runtime\Propel',
            '\PDO',
            '\Propel\Runtime\ActiveRecord\Persistence\InsertColumnBindingDto',
        );
        $table = $this->getTable();
        /** @var \Propel\Generator\Platform\DefaultPlatform $platform */
        $platform = $this->getPlatform();
        $primaryKeyMethodInfo = '';
        if ($table->getIdMethodParameters()) {
            $params = $table->getIdMethodParameters();
            $imp = $params[0];
            $primaryKeyMethodInfo = $imp->getValue();
        } elseif ($table->getIdMethod() == IdMethod::NATIVE && ($platform->getNativeIdMethod() == PlatformInterface::SEQUENCE || $platform->getNativeIdMethod() == PlatformInterface::SERIAL)) {
            $primaryKeyMethodInfo = $platform->getSequenceName($table);
        }
        $query = 'INSERT INTO ' . $this->quoteIdentifier($table->getName()) . ' (%s) VALUES (%s)';
        $script = "
        /** @var list<InsertColumnBindingDto> \$columnBindings */
        \$columnBindings = [];
        \$index = 0;
";

        foreach ($table->getPrimaryKey() as $column) {
            if (!$column->isAutoIncrement()) {
                continue;
            }
            $constantName = $this->getColumnConstant($column);
            if ($platform->supportsInsertNullPk()) {
                $script .= "
        \$this->modifiedColumns[$constantName] = true;";
            }
            $columnProperty = $column->getLowercasedName();
            if (!$table->isAllowPkInsert()) {
                $script .= "
        if (null !== \$this->{$columnProperty} && \$this->disableAutoIncrementPrimaryKeyInsert) {
            throw new PropelException('Cannot insert a value for auto-increment primary key (' . $constantName . ')');
        }";
            } elseif (!$platform->supportsInsertNullPk()) {
                $script .= "
        // add primary key column only if it is not null since this database does not accept that
        if (null !== \$this->{$columnProperty}) {
            \$this->modifiedColumns[$constantName] = true;
        }";
            }
        }

        // if non auto-increment but using sequence, get the id first
        if (!$platform->isNativeIdMethodAutoIncrement() && $table->getIdMethod() === 'native') {
            $column = $table->getFirstPrimaryKeyColumn();
            if (!$column) {
                throw new PropelException('Cannot find primary key column in table `' . $table->getName() . '`.');
            }
            $constantName = $this->getColumnConstant($column);
            $columnProperty = $column->getLowercasedName();
            $script .= "
        if (null === \$this->{$columnProperty}) {
            try {";
            $script .= $platform->getIdentifierPhp('$this->' . $columnProperty, '$con', $primaryKeyMethodInfo, '                ', $column->getPhpType());
            $script .= "
                \$this->modifiedColumns[$constantName] = true;
            } catch (Exception \$e) {
                throw new PropelException('Unable to get sequence id.', 0, \$e);
            }
        }
";
        }

        $script .= "

         // check the columns in natural order for more readable SQL queries";
        foreach ($table->getColumns() as $column) {
            $constantName = $this->getColumnConstant($column);
            $quotedColumnName = var_export($this->quoteIdentifier($column->getName()), true);
            $valueStatement = $this->getInsertColumnValueStatement($column);
            $pdoType = PropelTypes::getPdoTypeName($column->getPDOType());
            $isRequired = $column->isNotNull() && $column->isPhpObjectType();
            if ($isRequired) {
                $script .= "
        \$columnBindings[] = new InsertColumnBindingDto(':p'.\$index++, $quotedColumnName, $valueStatement, $pdoType);";
            } else {
                $script .= "
        if (\$this->isColumnModified($constantName)) {
            \$columnBindings[] = new InsertColumnBindingDto(':p'.\$index++, $quotedColumnName, $valueStatement, $pdoType);
        }";
            }
        }

        $script .= "

        \$sql = sprintf(
            '$query',
            implode(', ', array_map(static fn (InsertColumnBindingDto \$binding): string => \$binding->quotedColumnName, \$columnBindings)),
            implode(', ', array_map(static fn (InsertColumnBindingDto \$binding): string => \$binding->identifier, \$columnBindings))
        );

        try {
            \$stmt = \$con->prepare(\$sql) ?: throw new Exception(sprintf('Unable to prepare SELECT statement [%s]', \$sql));
            foreach (\$columnBindings as \$binding) {
                \$binding->bind(\$stmt);
            }
            \$stmt->execute();
        } catch (Exception \$e) {
            Propel::log(\$e->getMessage(), Propel::LOG_ERR);
            throw new PropelException(sprintf('Unable to execute INSERT statement [%s]', \$sql), 0, \$e);
        }
";

        // if auto-increment, get the id after
        $column = $table->getFirstPrimaryKeyColumn();
        $shouldRetrieveAutoIncrementPk = $platform->isNativeIdMethodAutoIncrement()
            && $table->getIdMethod() === 'native'
            && $column !== null
            && $column->isAutoIncrement();
        if ($shouldRetrieveAutoIncrementPk) {
            $constantName = $this->getColumnConstant($column);
            $pkType = match ($column->getPhpType()) {
                'int', 'integer' => 'int',
                default => 'string',
            };
            $columnProperty = $column->getLowercasedName();
            $script .= "
        \$insertedExplicitPrimaryKey = \$this->isColumnModified($constantName) && null !== \$this->{$columnProperty};
        if (!\$insertedExplicitPrimaryKey) {";
            $script .= "
        try {";
            $script .= $platform->getIdentifierPhp('$pk', '$con', $primaryKeyMethodInfo);
            $script .= "
        } catch (Exception \$e) {
            throw new PropelException('Unable to get autoincrement id.', 0, \$e);
        }";
            if ($table->isAllowPkInsert()) {
                $script .= "
        if (\$pk !== false) {
            \$this->set" . $column->getPhpName() . "(($pkType) \$pk);
        }";
            } else {
                $script .= "
        \$this->set" . $column->getPhpName() . "(($pkType) \$pk);";
            }
            $script .= "
        }";
            $script .= "
";
        }

        return $script;
    }

    /**
     * Get the statement how a column value is accessed in the script.
     *
     * Note that this is not necessarily just the getter. If the value is
     * stored on the model in an encoded format, the statement returned by
     * this method includes the statement to decode the value.
     *
     * @param Column $column
     *
     * @return string
     */
    protected function getAccessValueStatement(Column $column): string
    {
        $columnName = $column->getLowercasedName();

        if ($column->requiresUidBinaryConversion()) {
            return "(\$this->$columnName) ? UuidConverter::uidToBin(\$this->$columnName) : null";
        }

        if ($column->requiresUidStringConversion()) {
            return "(\$this->$columnName) ? UuidConverter::uidToString(\$this->$columnName) : null";
        }

        if ($column->isUuidBinaryType() || $column->requiresMysqlUuidBinaryConversion()) {
            $uuidSwapFlag = $this->getUuidSwapFlagLiteral($column);

            return "(\$this->$columnName) ? UuidConverter::uuidToBin(\$this->$columnName, $uuidSwapFlag) : null";
        }

        return "\$this->$columnName";
    }

    /**
     * Returns the value expression used when constructing insert column bindings.
     *
     * @param Column $column
     *
     * @return string
     */
    protected function getInsertColumnValueStatement(Column $column): string
    {
        $accessValueStatement = $this->getAccessValueStatement($column);

        if ($column->isNativeArrayType()) {
            $this->declareClass('\Propel\Common\Util\PgsqlArrayCodec');

            return $accessValueStatement . ' !== null ? PgsqlArrayCodec::encode(' . $accessValueStatement . ') : null';
        }

        if ($column->getType() === PropelTypes::DATE) {
            return $accessValueStatement . " ? " . $accessValueStatement . "->format('" . $this->getPlatform()->getDateFormatter() . "') : null";
        }

        if ($column->getType() === PropelTypes::TIME) {
            return $accessValueStatement . " ? " . $accessValueStatement . "->format('" . $this->getPlatform()->getTimeFormatter() . "') : null";
        }

        if ($column->isTemporalType()) {
            return $accessValueStatement . " ? " . $accessValueStatement . "->format('" . $this->getPlatform()->getTimeStampFormatter() . "') : null";
        }

        return $accessValueStatement;
    }

    /**
     * get the doUpdate() method code
     *
     * @return string the doUpdate() method code
     */
    protected function addDoUpdate(): string
    {
        return "
    /**
     * Update the row in the database.
     *
     * @param ConnectionInterface \$con
     *
     * @return int Number of updated rows
     * @see doSave()
     */
    protected function doUpdate(ConnectionInterface \$con): int
    {
        \$selectCriteria = \$this->buildPkeyCriteria();
        \$valuesCriteria = \$this->buildCriteria();

        return \$selectCriteria->doUpdate(\$valuesCriteria, \$con);
    }
";
    }

    /**
     * Adds the $alreadyInSave attribute, which prevents attempting to re-save the same object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addAlreadyInSaveAttribute(string &$script): void
    {
        $script .= "
    /**
     * Flag to prevent endless save loop, if this object is referenced
     * by another object which falls in this transaction.
     *
     * @var bool
     */
    protected \$alreadyInSave = false;
";
    }

    /**
     * Adds the save() method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSave(string &$script): void
    {
        $this->addSaveComment($script);
        $this->addSaveOpen($script);
        $this->addSaveBody($script);
        $this->addSaveClose($script);
    }

    /**
     * Adds the comment for the save method
     *
     * @see addSave()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSaveComment(string &$script): void
    {
        $table = $this->getTable();
        $reloadOnUpdate = $table->isReloadOnUpdate();
        $reloadOnInsert = $table->isReloadOnInsert();

        $script .= "
    /**
     * Persists this object to the database.
     *
     * If the object is new, it inserts it; otherwise an update is performed.
     * All modified related objects will also be persisted in the doSave()
     * method.  This method wraps all precipitate database operations in a
     * single transaction.";
        if ($reloadOnUpdate) {
            $script .= "
     *
     * Since this table was configured to reload rows on update, the object will
     * be reloaded from the database if an UPDATE operation is performed (unless
     * the \$skipReload parameter is TRUE).";
        }
        if ($reloadOnInsert) {
            $script .= "
     *
     * Since this table was configured to reload rows on insert, the object will
     * be reloaded from the database if an INSERT operation is performed (unless
     * the \$skipReload parameter is TRUE).";
        }
        $script .= "
     *
     * @param ?ConnectionInterface \$con";
        if ($reloadOnUpdate || $reloadOnInsert) {
            $script .= "
     * @param bool \$skipReload Whether to skip the reload for this object from database.";
        }
        $script .= "
     * @return int The number of rows affected by this insert/update and any referring fk objects' save() operations.
     * @throws \Propel\Runtime\Exception\PropelException
     * @see doSave()
     */";
    }

    /**
     * Adds the function declaration for the save method
     *
     * @see addSave()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSaveOpen(string &$script): void
    {
        $table = $this->getTable();
        $reloadOnUpdate = $table->isReloadOnUpdate();
        $reloadOnInsert = $table->isReloadOnInsert();
        $script .= "
    public function save(?ConnectionInterface \$con = null" . ($reloadOnUpdate || $reloadOnInsert ? ', $skipReload = false' : '') . "): int
    {";
    }

    /**
     * Adds the function body for the save method
     *
     * @see addSave()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSaveBody(string &$script): void
    {
        $table = $this->getTable();
        $reloadOnUpdate = $table->isReloadOnUpdate();
        $reloadOnInsert = $table->isReloadOnInsert();

        $script .= "
        if (\$this->isDeleted()) {
            throw new PropelException(\"You cannot save an object that has been deleted.\");
        }

        if (\$this->isPartial()) {
            throw new PropelException(\"Cannot save a partial object. Reload it before making changes.\");
        }

        if (\$this->alreadyInSave) {
            return 0;
        }

        if (\$con === null) {
            \$con = Propel::getServiceContainer()->getWriteConnection(" . $this->getTableMapClass() . "::DATABASE_NAME);
        }

        return \$con->transaction(function () use (\$con" . ($reloadOnUpdate || $reloadOnInsert ? ', $skipReload' : '') . ') {';

        if ($this->getBuildProperty('generator.objectModel.addHooks')) {
            // save with runtime hooks
            $script .= "
            \$ret = \$this->preSave(\$con);
            \$isInsert = \$this->isNew();";
            $this->applyBehaviorModifier('preSave', $script, '            ');
            $script .= "
            if (\$isInsert) {
                \$ret = \$ret && \$this->preInsert(\$con);";
            $this->applyBehaviorModifier('preInsert', $script, '                ');
            $script .= "
            } else {
                \$ret = \$ret && \$this->preUpdate(\$con);";
            $this->applyBehaviorModifier('preUpdate', $script, '                ');
            $script .= "
            }
            if (\$ret) {
                \$affectedRows = \$this->doSave(\$con" . ($reloadOnUpdate || $reloadOnInsert ? ', $skipReload' : '') . ");
                if (\$isInsert) {
                    \$this->postInsert(\$con);";
            $this->applyBehaviorModifier('postInsert', $script, '                    ');
            $script .= "
                } else {
                    \$this->postUpdate(\$con);";
            $this->applyBehaviorModifier('postUpdate', $script, '                    ');
            $script .= "
                }
                \$this->postSave(\$con);";
            $this->applyBehaviorModifier('postSave', $script, '                ');
            $script .= "
                " . $this->getAssertedCurrentChildObjectSnippet();
            $script .= "
                " . $this->getTableMapClassName() . "::addInstanceToPool(\$this);
            } else {
                \$affectedRows = 0;
            }

            return \$affectedRows;";
        } else {
            // save without runtime hooks
            $script .= "
            \$isInsert = \$this->isNew();";
            $this->applyBehaviorModifier('preSave', $script, '            ');
            if ($this->hasBehaviorModifier('preUpdate')) {
                $script .= "
            if (!\$isInsert) {";
                $this->applyBehaviorModifier('preUpdate', $script, '                ');
                $script .= "
            }";
            }
            if ($this->hasBehaviorModifier('preInsert')) {
                $script .= "
            if (\$isInsert) {";
                $this->applyBehaviorModifier('preInsert', $script, '                ');
                $script .= "
            }";
            }
            $script .= "
            \$affectedRows = \$this->doSave(\$con" . ($reloadOnUpdate || $reloadOnInsert ? ', $skipReload' : '') . ');';
            $this->applyBehaviorModifier('postSave', $script, '            ');
            if ($this->hasBehaviorModifier('postUpdate')) {
                $script .= "
            if (!\$isInsert) {";
                $this->applyBehaviorModifier('postUpdate', $script, '                ');
                $script .= "
            }";
            }
            if ($this->hasBehaviorModifier('postInsert')) {
                $script .= "
            if (\$isInsert) {";
                $this->applyBehaviorModifier('postInsert', $script, '                ');
                $script .= "
            }";
            }
            $script .= "
            " . $this->getTableMapClassName() . "::addInstanceToPool(\$this);

            return \$affectedRows;";
        }

        $script .= "
        });";
    }

    /**
     * Adds the function close for the save method
     *
     * @see addSave()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSaveClose(string &$script): void
    {
        $script .= "
    }
";
    }

    /**
     * Adds the ensureConsistency() method to ensure that internal state is correct.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addEnsureConsistency(string &$script): void
    {
        $table = $this->getTable();

        $script .= "
    /**
     * Checks and repairs the internal consistency of the object.
     *
     * This method is executed after an already-instantiated object is re-hydrated
     * from the database.  It exists to check any foreign keys to make sure that
     * the objects related to the current object are correct based on foreign key.
     *
     * You can override this method in the stub class, but you should always invoke
     * the base method from the overridden method (i.e. parent::ensureConsistency()),
     * in case your model changes.
     *
     * @throws \Propel\Runtime\Exception\PropelException
     * @return void
     */
    public function ensureConsistency(): void
    {";
        foreach ($table->getColumns() as $col) {
            $clo = $col->getLowercasedName();

            if ($col->isForeignKey()) {
                foreach ($col->getForeignKeys() as $fk) {
                    $tblFK = $table->getDatabase()->getTable($fk->getForeignTableName());
                    $colFK = $tblFK->getColumn($fk->getMappedForeignColumn($col->getName()));
                    $varName = $this->getFKVarName($fk);

                    if (!$colFK) {
                        continue;
                    }

                    $script .= "
        if (\$this->" . $varName . " !== null && \$this->$clo !== \$this->" . $varName . '->get' . $colFK->getPhpName() . "()) {
            \$this->$varName = null;
        }";
                }
            }
        }

        $script .= "
    }
";
    }

    /**
     * Adds the copy() method, which (in complex OM) includes the $deepCopy param for making copies of related objects.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addCopy(string &$script): void
    {
        $this->addCopyInto($script);

        $script .= "
    /**
     * Makes a copy of this object that will be inserted as a new row in table when saved.
     * It creates a new object filling in the simple attributes, but skipping any primary
     * keys that are defined for the table.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param bool \$deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @return static Clone of current object (an instance of " . $this->getObjectClassName(true) . ").
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function copy(bool \$deepCopy = false)
    {
        // we use get_class(), because this might be a subclass
        \$clazz = get_class(\$this);

        " . $this->buildObjectInstanceCreationCode('$copyObj', '$clazz') . "
        \$this->copyInto(\$copyObj, \$deepCopy);

        return \$copyObj;
    }
";
    }

    /**
     * Adds the copyInto() method, which takes an object and sets contents to match current object.
     * In complex OM this method includes the $deepCopy param for making copies of related objects.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addCopyInto(string &$script): void
    {
        $table = $this->getTable();

        $script .= "
    /**
     * Sets contents of passed object to values from current object.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param static \$copyObj An object of " . $this->getObjectClassName(true) . " (or compatible) type.
     * @param bool \$deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @param bool \$makeNew Whether to reset autoincrement PKs and make the object new.
     * @throws \Propel\Runtime\Exception\PropelException
     * @return void
     */
    public function copyInto(object \$copyObj, bool \$deepCopy = false, bool \$makeNew = true): void
    {";

        $autoIncCols = [];
        foreach ($table->getColumns() as $col) {
            /** @var Column $col */
            if ($col->isAutoIncrement()) {
                $autoIncCols[] = $col;
            }
        }

        foreach ($table->getColumns() as $col) {
            if (!in_array($col, $autoIncCols, true)) {
                $script .= "
        \$copyObj->setByName('{$col->getPhpName()}', \$this->getByName('{$col->getPhpName()}'));";
            }
        }

        // Avoid useless code by checking to see if there are any referrers
        // to this table:
        $referrers = $table->getReferrersForCodeGeneration();
        if (count($referrers) > 0) {
            $script .= "

        if (\$deepCopy) {
            // important: temporarily setNew(false) because this affects the behavior of
            // the getter/setter methods for fkey referrer objects.
            \$copyObj->setNew(false);
";
            foreach ($referrers as $fk) {
                //HL: commenting out self-referential check below
                //        it seems to work as expected and is probably desirable to have those referrers from same table deep-copied.
                //if ( $fk->getTable()->getName() != $table->getName() ) {

                if ($fk->isLocalPrimaryKey()) {
                    $afx = $this->getRefFKPhpNameAffix($fk, false);
                    $script .= "
            \$relObj = \$this->get$afx();
            if (\$relObj) {
                \$copyObj->set$afx(\$relObj->copy(\$deepCopy));
            }
";
                } else {
                    $script .= "
            foreach (\$this->get" . $this->getRefFKPhpNameAffix($fk, true) . "() as \$rObj) {
                if (\$rObj !== \$this) {  // ensure that we don't try to copy a reference to ourselves
                    \$copyObj->add" . $this->getRefFKPhpNameAffix($fk) . "(\$rObj->copy(\$deepCopy));
                }
            }
";
                }
                // HL: commenting out close of self-referential check
                // } /* if tblFK != table */
            }
            $script .= "
        } // if (\$deepCopy)
";
        } /* if (count referrers > 0 ) */

        $script .= "
        if (\$makeNew) {
            \$copyObj->setNew(true);";

        // Note: we're no longer resetting non-autoincrement primary keys to default values
        // due to: http://propel.phpdb.org/trac/ticket/618
        foreach ($autoIncCols as $col) {
            $coldefval = $col->getPhpDefaultValue();
            $coldefval = var_export($coldefval, true);
            $script .= "
            \$copyObj->setByName('{$col->getPhpName()}', null); // this is an auto-increment column, so set to default value";
        }
        $script .= "
        }
    }
";
    }

    /**
     * Adds clear method
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addClear(string &$script): void
    {
        $table = $this->getTable();
        $currentClassName = $this->getClassNameFromTable($this->getTable());

        $script .= "
    /**
     * Clears the current object, sets all attributes to their default values and removes
     * outgoing references as well as back-references (from other objects to this one. Results probably in a database
     * change of those foreign objects when you call `save` there).
     *
     * @return \$this
     */
    public function clear()
    {";

        foreach ($table->getForeignKeys() as $fk) {
            if ($fk->isLocalPrimaryKey()) {
                continue;
            }
            if ($fk->isSkipRefCode()) {
                continue;
            }
            $varName = $this->getFKVarName($fk);
            $removeMethod = 'remove' . $this->getRefFKPhpNameAffix($fk, false);
            $script .= "
        if (null !== \$this->$varName) {
            " . $this->getAssertedCurrentChildObjectSnippet() . "
            \$this->$varName->$removeMethod(\$this);
        }";
        }

        foreach ($table->getColumns() as $col) {
            $clo = $col->getLowercasedName();
            $script .= "
        \$this->" . $clo . ' = null;';
            if ($col->isLazyLoad()) {
                $script .= "
        \$this->" . $clo . '_isLoaded = false;';
            }
            if ($col->getType() == PropelTypes::OBJECT || $col->getType() == PropelTypes::PHP_ARRAY) {
                $cloUnserialized = $clo . '_unserialized';

                $script .= "
        \$this->$cloUnserialized = null;";
            }
            if ($col->isSetType()) {
                $cloConverted = $clo . '_converted';

                $script .= "
        \$this->$cloConverted = null;";
            }
        }

        $script .= "
        \$this->alreadyInSave = false;
        \$this->clearAllReferences();";

        if ($this->hasDefaultValues()) {
            $script .= "
        \$this->applyDefaultValues();";
        }

        $script .= "
        \$this->resetModified();
        \$this->setNew(true);
        \$this->setDeleted(false);

        return \$this;
    }
";
    }

    /**
     * Adds clearAllReferences() method which resets all the collections of referencing
     * fk objects.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addClearAllReferences(string &$script): void
    {
        $table = $this->getTable();
        $script .= "
    /**
     * Resets all references and back-references to other model objects or collections of model objects.
     *
     * This method is used to reset all php object references (not the actual reference in the database).
     * Necessary for object serialisation.
     *
     * @param bool \$deep Whether to also clear the references on all referrer objects.
     * @return \$this
     */
    public function clearAllReferences(bool \$deep = false)
    {
        if (\$deep) {";
        $vars = [];
        foreach ($this->getTable()->getReferrersForCodeGeneration() as $refFK) {
            if ($refFK->isLocalPrimaryKey()) {
                $varName = $this->getPKRefFKVarName($refFK);
                $script .= "
            if (\$this->$varName) {
                \$this->{$varName}->clearAllReferences(\$deep);
            }";
            } else {
                $varName = $this->getRefFKCollVarName($refFK);
                $script .= "
            if (\$this->$varName) {
                foreach (\$this->$varName as \$o) {
                    \$o->clearAllReferences(\$deep);
                }
            }";
            }
            $vars[] = $varName;
        }
        foreach ($this->getTable()->getCrossFks() as $crossFKs) {
            $varName = $this->getCrossFKsVarName($crossFKs);
            if (1 < count($crossFKs->getCrossForeignKeys()) || $crossFKs->getUnclassifiedPrimaryKeys()) {
                $varName = 'combination' . ucfirst($varName);
            }
            $script .= "
            if (\$this->$varName) {
                foreach (\$this->$varName as \$o) {
                    \$o->clearAllReferences(\$deep);
                }
            }";
            $vars[] = $varName;
        }

        $script .= "
        } // if (\$deep)
";

        $this->applyBehaviorModifier('objectClearReferences', $script, '        ');

        foreach ($vars as $varName) {
            $script .= "
        \$this->$varName = null;";
        }

        foreach ($table->getForeignKeys() as $fk) {
            $varName = $this->getFKVarName($fk);
            $script .= "
        \$this->$varName = null;";
        }

        $script .= "
        return \$this;
    }
";
    }

    /**
     * Adds a magic __toString() method if a string column was defined as primary string
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addPrimaryString(string &$script): void
    {
        foreach ($this->getTable()->getColumns() as $column) {
            if ($column->isPrimaryString()) {
                $script .= "
    /**
     * Return the string representation of this object
     *
     * @return string The value of the '{$column->getName()}' column
     */
    public function __toString(): string
    {
        return (string)\$this->get{$column->getPhpName()}();
    }
";

                return;
            }
        }
        // no primary string column, falling back to default string format
        $script .= "
    /**
     * Return the string representation of this object
     *
     * @return string
     */
    public function __toString()
    {
        return \$this->exportTo(" . $this->getTableMapClassName() . "::DEFAULT_STRING_FORMAT);
    }
";
    }

    /**
     * Adds a magic __call() method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addMagicCall(string &$script): void
    {
        $behaviorCallScript = '';
        $this->applyBehaviorModifier('objectCall', $behaviorCallScript, '    ');

        $script .= $this->renderTemplate('baseObjectMethodMagicCall', [
            'behaviorCallScript' => $behaviorCallScript,
            'supportsMagicImportFrom' => !$this->getTable()->isReadOnly() && $this->isAddGenericMutators(),
            'supportsMagicExportTo' => $this->isAddGenericAccessors(),
        ]);
    }

    /**
     * @param Column $column
     *
     * @return string
     */
    protected function getDateTimeClass(Column $column): string
    {
        if (PropelTypes::isPhpObjectType($column->getPhpType())) {
            return $column->getPhpType();
        }

        $dateTimeClass = $this->getBuildProperty('generator.dateTime.dateTimeClass');
        if (!$dateTimeClass) {
            $dateTimeClass = '\DateTime';
        }

        return $dateTimeClass;
    }
}
