<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Platform;

use PDO;
use Propel\Generator\Config\GeneratorConfigInterface;
use Propel\Generator\Exception\EngineException;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\Database;
use Propel\Generator\Model\Diff\ColumnDiff;
use Propel\Generator\Model\Diff\DatabaseDiff;
use Propel\Generator\Model\Domain;
use Propel\Generator\Model\ForeignKey;
use Propel\Generator\Model\Index;
use Propel\Generator\Model\PropelTypes;
use Propel\Generator\Model\Table;
use Propel\Generator\Model\Unique;
use Propel\Generator\Platform\Util\MysqlUuidMigrationBuilder;

use function stripos;
use function in_array;
use function sprintf;

/**
 * MySql PlatformInterface implementation.
 *
 * @author Hans Lellelid <hans@xmpl.org> (Propel)
 * @author Martin Poeschl <mpoeschl@marmot.at> (Torque)
 */
class MysqlPlatform extends DefaultPlatform
{
    /**
     * @var string
     */
    protected $tableEngineKeyword = 'ENGINE';

    /**
     * @var string
     */
    protected $defaultTableEngine = 'InnoDB';

    /**
     * @var string|null
     */
    protected $serverVersion;

    /**
     * @var bool
     */
    protected $useUuidNativeType = false;

    /**
     * Initializes db specific domain mapping.
     *
     * @return void
     */
    protected function initializeTypeMap(): void
    {
        parent::initializeTypeMap();
        $this->setSchemaDomainMapping(new Domain(PropelTypes::BOOLEAN, 'TINYINT', 1));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::NUMERIC, 'DECIMAL'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::LONGVARCHAR, 'TEXT'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::BINARY, 'BINARY'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::VARBINARY, 'MEDIUMBLOB'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::LONGVARBINARY, 'LONGBLOB'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::CLOB, 'LONGTEXT'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::OBJECT, 'MEDIUMBLOB'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::PHP_ARRAY, 'TEXT'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::ENUM, 'TINYINT'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::SET, 'INT'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::REAL, 'DOUBLE'));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::UUID_BINARY, 'BINARY', 16));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::UID_BINARY, 'BINARY', 16));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::UID, 'CHAR', 36));

        $this->setUuidTypeMapping();
    }

    public function setGeneratorConfig(GeneratorConfigInterface $generatorConfig): void
    {
        parent::setGeneratorConfig($generatorConfig);

        $mysqlConfig = $generatorConfig->get()['database']['adapters']['mysql'];

        $defaultTableEngine = $mysqlConfig['tableType'];
        if ($defaultTableEngine) {
            $this->defaultTableEngine = $defaultTableEngine;
        }

        $tableEngineKeyword = $mysqlConfig['tableEngineKeyword'];
        if ($tableEngineKeyword) {
            $this->tableEngineKeyword = $tableEngineKeyword;
        }

        $uuidColumnType = $mysqlConfig['uuidColumnType'];
        if ($uuidColumnType) {
            $enable = strtolower($uuidColumnType) === 'native';
            $this->setUuidNativeType($enable);
        }
    }

    /**
     * @param bool $enable
     *
     * @return void
     */
    public function setUuidNativeType(bool $enable): void
    {
        $this->useUuidNativeType = $enable;
        $this->setUuidTypeMapping();
    }

    /**
     * Set column type for UUIDs according to MysqlPlatform::useUuidNativeType.
     *
     * Currently, only MariaDB has a native UUID type.
     *
     * @return void
     */
    protected function setUuidTypeMapping(): void
    {
        $domain = ($this->useUuidNativeType)
            ? new Domain(PropelTypes::UUID, 'UUID')
            : $this->schemaDomainMap[PropelTypes::UUID_BINARY];

        $this->schemaDomainMap[PropelTypes::UUID] = $domain;
    }

    /**
     * @param string $tableEngineKeyword
     *
     * @return void
     */
    public function setTableEngineKeyword(string $tableEngineKeyword): void
    {
        $this->tableEngineKeyword = $tableEngineKeyword;
    }

    /**
     * @return string
     */
    public function getTableEngineKeyword(): string
    {
        return $this->tableEngineKeyword;
    }

    /**
     * @param string $defaultTableEngine
     *
     * @return void
     */
    public function setDefaultTableEngine(string $defaultTableEngine): void
    {
        $this->defaultTableEngine = $defaultTableEngine;
    }

    /**
     * @return string
     */
    public function getDefaultTableEngine(): string
    {
        return $this->defaultTableEngine;
    }

    /**
     * @return string
     */
    public function getAutoIncrement(): string
    {
        return 'AUTO_INCREMENT';
    }

    /**
     * @return int
     */
    public function getMaxColumnNameLength(): int
    {
        return 64;
    }

    /**
     * @return bool
     */
    public function supportsNativeDeleteTrigger(): bool
    {
        return strtolower($this->getDefaultTableEngine()) === 'innodb';
    }

    /**
     * @return bool
     */
    public function supportsIndexSize(): bool
    {
        return true;
    }

    public function supportsForeignKeys(Table $table): bool
    {
        $vendorSpecific = $table->getVendorInfoForType('mysql');
        if ($vendorSpecific->hasParameter('Type')) {
            $mysqlTableType = $vendorSpecific->getParameter('Type');
        } elseif ($vendorSpecific->hasParameter('Engine')) {
            $mysqlTableType = $vendorSpecific->getParameter('Engine');
        } else {
            $mysqlTableType = $this->getDefaultTableEngine();
        }

        return strtolower($mysqlTableType) === 'innodb';
    }

    public function getAddTablesDDL(Database $database): string
    {
        $ret = '';
        foreach ($database->getTablesForSql() as $table) {
            $ret .= $this->getCommentBlockDDL($table->getName());
            $ret .= $this->getDropTableDDL($table);
            $ret .= $this->getAddTableDDL($table);
        }
        if ($ret) {
            $ret = $this->getBeginDDL() . $ret . $this->getEndDDL();
        }

        return $ret;
    }

    /**
     * @return string
     */
    public function getBeginDDL(): string
    {
        return "
# This is a fix for InnoDB in MySQL >= 4.1.x
# It \"suspends judgement\" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;
";
    }

    /**
     * @return string
     */
    public function getEndDDL(): string
    {
        return "
# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
";
    }

    /**
     * Returns the SQL for the primary key of a Table object
     */
    public function getPrimaryKeyDDL(Table $table): string
    {
        if ($table->hasPrimaryKey()) {
            $keys = $table->getPrimaryKey();

            //MySQL throws an 'Incorrect table definition; there can be only one auto column and it must be defined as a key'
            //if the primary key consists of multiple columns and if the first is not the autoIncrement one. So
            //this push the autoIncrement column to the first position if its not already.
            $autoIncrement = $table->getAutoIncrementPrimaryKey();
            if ($autoIncrement && $keys[0] != $autoIncrement) {
                $idx = array_search($autoIncrement, $keys);
                if ($idx !== false) {
                    unset($keys[$idx]);
                    array_unshift($keys, $autoIncrement);
                }
            }

            return 'PRIMARY KEY (' . $this->getColumnListDDL($keys) . ')';
        }

        return '';
    }

    public function getAddTableDDL(Table $table): string
    {
        $lines = [];

        foreach ($table->getColumns() as $column) {
            $lines[] = $this->getColumnDDL($column);
        }

        if ($table->hasPrimaryKey()) {
            $lines[] = $this->getPrimaryKeyDDL($table);
        }

        foreach ($table->getUnices() as $unique) {
            $lines[] = $this->getUniqueDDL($unique);
        }

        foreach ($table->getIndices() as $index) {
            $lines[] = $this->getIndexDDL($index);
        }

        if ($this->supportsForeignKeys($table)) {
            foreach ($table->getForeignKeys() as $foreignKey) {
                if ($foreignKey->isSkipSql() || $foreignKey->isPolymorphic()) {
                    continue;
                }
                $lines[] = str_replace("
    ", "
        ", $this->getForeignKeyDDL($foreignKey));
            }
        }

        $vendorSpecific = $table->getVendorInfoForType('mysql');
        if ($vendorSpecific->hasParameter('Type')) {
            $mysqlTableType = $vendorSpecific->getParameter('Type');
        } elseif ($vendorSpecific->hasParameter('Engine')) {
            $mysqlTableType = $vendorSpecific->getParameter('Engine');
        } else {
            $mysqlTableType = $this->getDefaultTableEngine();
        }

        $tableOptions = $this->getTableOptions($table);

        if ($table->getDescription()) {
            $tableOptions[] = 'COMMENT=' . $this->quote($table->getDescription());
        }

        $tableOptions = $tableOptions ? ' ' . implode(' ', $tableOptions) : '';
        $sep = ",
    ";

        $pattern = "
CREATE TABLE %s
(
    %s
) %s=%s%s;
";

        return sprintf(
            $pattern,
            $this->quoteIdentifier($table->getName()),
            implode($sep, $lines),
            $this->getTableEngineKeyword(),
            $mysqlTableType,
            $tableOptions,
        );
    }

    /**
     * @return array<string>
     */
    protected function getTableOptions(Table $table): array
    {
        $vi = $table->getVendorInfoForType('mysql');
        $tableOptions = [];
        // List of supported table options
        // see http://dev.mysql.com/doc/refman/5.5/en/create-table.html
        $supportedOptions = [
            'AutoIncrement' => 'AUTO_INCREMENT',
            'AvgRowLength' => 'AVG_ROW_LENGTH',
            'Charset' => 'CHARACTER SET',
            'Checksum' => 'CHECKSUM',
            'Collate' => 'COLLATE',
            'Connection' => 'CONNECTION',
            'DataDirectory' => 'DATA DIRECTORY',
            'Delay_key_write' => 'DELAY_KEY_WRITE',
            'DelayKeyWrite' => 'DELAY_KEY_WRITE',
            'IndexDirectory' => 'INDEX DIRECTORY',
            'InsertMethod' => 'INSERT_METHOD',
            'KeyBlockSize' => 'KEY_BLOCK_SIZE',
            'MaxRows' => 'MAX_ROWS',
            'MinRows' => 'MIN_ROWS',
            'Pack_Keys' => 'PACK_KEYS',
            'PackKeys' => 'PACK_KEYS',
            'RowFormat' => 'ROW_FORMAT',
            'Union' => 'UNION',
        ];

        $noQuotedValue = array_flip([
            'InsertMethod',
            'Pack_Keys',
            'PackKeys',
            'RowFormat',
        ]);

        foreach ($supportedOptions as $name => $sqlName) {
            $parameterValue = null;

            if ($vi->hasParameter($name)) {
                $parameterValue = $vi->getParameter($name);
            } elseif ($vi->hasParameter($sqlName)) {
                $parameterValue = $vi->getParameter($sqlName);
            }

            // if we have a param value, then parse it out
            if ($parameterValue !== null) {
                // if the value is numeric or is parameter is in $noQuotedValue, then there is no need for quotes
                if (!is_numeric($parameterValue) && !isset($noQuotedValue[$name])) {
                    $parameterValue = $this->quote($parameterValue);
                }

                $tableOptions[] = sprintf('%s=%s', $sqlName, $parameterValue);
            }
        }

        return $tableOptions;
    }

    public function getDropTableDDL(Table $table): string
    {
        return "
DROP TABLE IF EXISTS " . $this->quoteIdentifier($table->getName()) . ";
";
    }

    /**
     * @throws EngineException
     */
    public function getColumnDDL(Column $col): string
    {
        if ($col->isNativeArrayType()) {
            throw new EngineException(sprintf(
                'NATIVE_ARRAY column "%s" is only supported by PostgreSQL.',
                $col->getFullyQualifiedName(),
            ));
        }

        $domain = $col->getDomain();
        $sqlType = $domain->getSqlType();
        $notNullString = $this->getNullString($col->isNotNull());
        $defaultSetting = $this->getColumnDefaultValueDDL($col);

        // Special handling of TIMESTAMP/DATETIME types ...
        // See: http://propel.phpdb.org/trac/ticket/538
        if ($sqlType === 'DATETIME') {
            $def = $domain->getDefaultValue();
            if ($def && $def->isExpression()) {
                // DATETIME values can only have constant expressions
                $sqlType = 'TIMESTAMP';
            }
        } elseif ($sqlType === 'DATE') {
            $def = $domain->getDefaultValue();
            if ($def && $def->isExpression()) {
                throw new EngineException('DATE columns cannot have default *expressions* in MySQL.');
            }
        } elseif ($sqlType === 'TEXT' || $sqlType === 'BLOB') {
            if ($domain->getDefaultValue()) {
                throw new EngineException('BLOB and TEXT columns cannot have DEFAULT values. in MySQL.');
            }
        }

        $ddl = [$this->quoteIdentifier($col->getName())];
        $ddl[] = $this->getSqlTypeExpression($col);

        $colinfo = $col->getVendorInfoForType($this->getDatabaseType());
        if ($colinfo->hasParameter('Unsigned')) {
            $unsigned = $colinfo->getParameter('Unsigned');
            switch (strtoupper($unsigned)) {
                case 'FALSE':
                    break;
                case 'TRUE':
                    $ddl[] = 'UNSIGNED';

                    break;
                default:
                    throw new EngineException('Unexpected value "' . $unsigned . '" for MySQL vendor column parameter "Unsigned", expecting "true" or "false".');
            }
        }

        if ($colinfo->hasParameter('Charset')) {
            $ddl[] = 'CHARACTER SET ' . $this->quote($colinfo->getParameter('Charset'));
        }
        if ($colinfo->hasParameter('Collation')) {
            $ddl[] = 'COLLATE ' . $this->quote($colinfo->getParameter('Collation'));
        } elseif ($colinfo->hasParameter('Collate')) {
            $ddl[] = 'COLLATE ' . $this->quote($colinfo->getParameter('Collate'));
        }

        if ($sqlType === 'TIMESTAMP') {
            if ($notNullString === '') {
                $notNullString = 'NULL';
            }
            if ($defaultSetting === '' && $notNullString === 'NOT NULL') {
                $defaultSetting = 'DEFAULT CURRENT_TIMESTAMP';
            }
            $ddl[] = $notNullString;
            if ($defaultSetting) {
                $ddl[] = $defaultSetting;
            }
        } else {
            if ($defaultSetting) {
                $ddl[] = $defaultSetting;
            }
            if ($notNullString) {
                $ddl[] = $notNullString;
            }
        }

        $autoIncrement = $col->getAutoIncrementString();
        if ($autoIncrement) {
            $ddl[] = $autoIncrement;
        }

        if ($col->getDescription()) {
            $ddl[] = 'COMMENT ' . $this->quote($col->getDescription());
        }

        return implode(' ', $ddl);
    }

    /**
     * Returns the DDL SQL of a Column object, normalized for comparing two columns.
     *
     * The display width of an integer column (the `11` in `int(11)`) is deprecated as of MySQL
     * 8.0.19 and is no longer reported by `SHOW COLUMNS`, so a reverse-engineered column reads
     * back as plain `int` however the schema spelled it. Comparing the widths would then report
     * a change on every diff, for every integer column a schema declares a width for, and the
     * resulting `CHANGE` would not alter anything. Strip the width so the comparison only sees
     * differences the server would actually act on.
     */
    public function getComparableColumnDDL(Column $col): string
    {
        return $this->stripDeprecatedIntegerDisplayWidth($this->getColumnDDL($col));
    }

    /**
     * Removes integer display widths that MySQL >= 8.0.19 ignores.
     *
     * Two spellings keep their width, because the server keeps reporting it and stripping it
     * here would hide a real difference:
     *
     * - a *signed* `tinyint(1)`, the conventional boolean column, which Propel reads back as
     *   BOOLEAN. `tinyint(1) unsigned` is not exempt and does come back as plain `tinyint`.
     * - ZEROFILL columns, where the width still pads the displayed value.
     *
     * @param string $ddl
     *
     * @return string
     */
    protected function stripDeprecatedIntegerDisplayWidth(string $ddl): string
    {
        $pattern = '/\b(tinyint|smallint|mediumint|int|integer|bigint)\((\d+)\)((?:\s+unsigned)?(?:\s+zerofill)?)/i';

        return (string)preg_replace_callback($pattern, function (array $match): string {
            $isZerofill = stripos($match[3], 'zerofill') !== false;
            $isBooleanTinyint = strtolower($match[1]) === 'tinyint'
                && $match[2] === '1'
                && stripos($match[3], 'unsigned') === false;

            return $isZerofill || $isBooleanTinyint ? $match[0] : $match[1] . $match[3];
        }, $ddl);
    }

    /**
     * Normalizes a SQL type before two of them are compared.
     *
     * Foreign keys are compared by the SQL type of the columns they join, which runs into the
     * same deprecated display width as the column comparison does: the schema says
     * `int(11) unsigned` where the server reports `int unsigned`, and the key would be dropped
     * and re-added on every diff without ever converging.
     */
    public function fixSqlType(?string $sqlType): ?string
    {
        if ($sqlType === null) {
            return null;
        }

        return $this->stripDeprecatedIntegerDisplayWidth($sqlType);
    }

    /**
     * Returns the SQL type as a string.
     *
     * @see Domain::getSqlType()
     *
     */
    public function getSqlTypeExpression(Column $column): string
    {
        $sqlType = $column->getSqlType();
        $hasSize = $this->hasSize($sqlType) && $column->isDefaultSqlType($this);

        return (!$hasSize) ? $sqlType : $sqlType . $column->getSizeDefinition();
    }

    protected function getChangeColumnToUuidBinaryType(Column $fromColumn, Column $toColumn): string
    {
        return MysqlUuidMigrationBuilder::create($this)->buildMigration($fromColumn, $toColumn, true);
    }

    protected function getChangeColumnFromUuidBinaryType(Column $fromColumn, Column $toColumn): string
    {
        return MysqlUuidMigrationBuilder::create($this)->buildMigration($fromColumn, $toColumn, false);
    }

    /**
     * Creates a comma-separated list of column names for the index.
     * For MySQL unique indexes there is the option of specifying size, so we cannot simply use
     * the getColumnsList() method.
     */
    protected function getIndexColumnListDDL(Index $index): string
    {
        $list = [];
        foreach ($index->getColumns() as $col) {
            $size = $index->hasColumnSize($col) ? '(' . $index->getColumnSize($col) . ')' : '';
            $list[] = $this->quoteIdentifier($col) . $size;
        }
        if ($index->hasWhere()) {
            $list[] = $this->getPartialUniqueIndexConditionDDL($index);
        }

        return implode(', ', $list);
    }

    /**
     * MySQL has no native partial indexes. For unique indexes, emulate the
     * predicate by adding a functional key part that is NULL for excluded rows.
     *
     * @throws EngineException
     */
    protected function getPartialUniqueIndexConditionDDL(Index $index): string
    {
        if (!$index->isUnique()) {
            throw new EngineException('MySQL does not support non-unique partial indexes.');
        }

        return '((CASE WHEN ' . $index->getWhere() . ' THEN 1 ELSE NULL END))';
    }

    /**
     * Builds the DDL SQL to drop the primary key of a table.
     */
    public function getDropPrimaryKeyDDL(Table $table): string
    {
        if (!$table->hasPrimaryKey()) {
            return '';
        }

        $tableName = $this->quoteIdentifier($table->getName());

        return "\nALTER TABLE $tableName DROP PRIMARY KEY;\n";
    }

    /**
     * Builds the DDL SQL to add an Index.
     */
    public function getAddIndexDDL(Index $index): string
    {
        $pattern = "
CREATE %sINDEX %s ON %s (%s);
";

        return sprintf(
            $pattern,
            $this->getIndexType($index),
            $this->quoteIdentifier($index->getName()),
            $this->quoteIdentifier($index->getTable()->getName()),
            $this->getIndexColumnListDDL($index),
        );
    }

    /**
     * Builds the DDL SQL to drop an Index.
     */
    public function getDropIndexDDL(Index $index): string
    {
        $pattern = "
DROP INDEX %s ON %s;
";

        return sprintf(
            $pattern,
            $this->quoteIdentifier($index->getName()),
            $this->quoteIdentifier($index->getTable()->getName()),
        );
    }

    /**
     * Builds the DDL SQL for an Index object.
     */
    public function getIndexDDL(Index $index): string
    {
        return sprintf(
            '%sINDEX %s (%s)',
            $this->getIndexType($index),
            $this->quoteIdentifier($index->getName()),
            $this->getIndexColumnListDDL($index),
        );
    }

    protected function getIndexType(Index $index): string
    {
        $type = '';
        $vendorInfo = $index->getVendorInfoForType($this->getDatabaseType());
        if ($vendorInfo->getParameter('Index_type')) {
            $type = $vendorInfo->getParameter('Index_type') . ' ';
        } elseif ($index->isUnique()) {
            $type = 'UNIQUE ';
        }

        return $type;
    }

    public function getUniqueDDL(Unique $unique): string
    {
        return sprintf(
            'UNIQUE INDEX %s (%s)',
            $this->quoteIdentifier($unique->getName()),
            $this->getIndexColumnListDDL($unique),
        );
    }

    public function getAddForeignKeyDDL(ForeignKey $fk): string
    {
        if ($this->supportsForeignKeys($fk->getTable())) {
            return parent::getAddForeignKeyDDL($fk);
        }

        return '';
    }

    /**
     * Builds the DDL SQL for a ForeignKey object.
     */
    public function getForeignKeyDDL(ForeignKey $fk): string
    {
        if ($this->supportsForeignKeys($fk->getTable())) {
            return parent::getForeignKeyDDL($fk);
        }

        return '';
    }

    public function getDropForeignKeyDDL(ForeignKey $fk): ?string
    {
        if (!$this->supportsForeignKeys($fk->getTable())) {
            return '';
        }
        if ($fk->isSkipSql() || $fk->isPolymorphic()) {
            return null;
        }
        $pattern = "
ALTER TABLE %s DROP FOREIGN KEY %s;
";

        return sprintf(
            $pattern,
            $this->quoteIdentifier($fk->getTable()->getName()),
            $this->quoteIdentifier($fk->getName()),
        );
    }

    /**
     * @param string $comment
     *
     * @return string
     */
    public function getCommentBlockDDL(string $comment): string
    {
        $pattern = "
-- ---------------------------------------------------------------------
-- %s
-- ---------------------------------------------------------------------
";

        return sprintf($pattern, $comment);
    }

    /**
     * Builds the DDL SQL to modify a database
     * based on a DatabaseDiff instance
     */
    public function getModifyDatabaseDDL(DatabaseDiff $databaseDiff): string
    {
        $ret = '';

        foreach ($databaseDiff->getRemovedTables() as $table) {
            $ret .= $this->getDropTableDDL($table);
        }

        foreach ($databaseDiff->getRenamedTables() as $fromTableName => $toTableName) {
            $ret .= $this->getRenameTableDDL($fromTableName, $toTableName);
        }

        foreach ($databaseDiff->getModifiedTables() as $tableDiff) {
            $ret .= $this->getModifyTableDDL($tableDiff);
        }

        foreach ($databaseDiff->getAddedTables() as $table) {
            $ret .= $this->getAddTableDDL($table);
        }

        if ($ret) {
            $ret = $this->getBeginDDL() . $ret . $this->getEndDDL();
        }

        return $ret;
    }

    /**
     * Builds the DDL SQL to rename a table
     *
     * @param string $fromTableName
     * @param string $toTableName
     *
     * @return string
     */
    public function getRenameTableDDL(string $fromTableName, string $toTableName): string
    {
        $pattern = "
RENAME TABLE %s TO %s;
";

        return sprintf(
            $pattern,
            $this->quoteIdentifier($fromTableName),
            $this->quoteIdentifier($toTableName),
        );
    }

    /**
     * Builds the DDL SQL to remove a column
     */
    public function getRemoveColumnDDL(Column $column): string
    {
        $pattern = "
ALTER TABLE %s DROP %s;
";

        return sprintf(
            $pattern,
            $this->quoteIdentifier($column->getTable()->getName()),
            $this->quoteIdentifier($column->getName()),
        );
    }

    /**
     * Builds the DDL SQL to rename a column
     */
    public function getRenameColumnDDL(Column $fromColumn, Column $toColumn): string
    {
        return $this->getChangeColumnDDL($fromColumn, $toColumn);
    }

    /**
     * Builds the DDL SQL to modify a column
     */
    public function getModifyColumnDDL(ColumnDiff $columnDiff): string
    {
        $fromColumn = $columnDiff->getFromColumn();
        $toColumn = $columnDiff->getToColumn();

        if ($fromColumn->isTextType() && $toColumn->isUuidBinaryType()) {
            return $this->getChangeColumnToUuidBinaryType($fromColumn, $toColumn);
        }

        // binary column from database does not know it is a UUID column
        $fromBinaryColumn = in_array($fromColumn->getType(), [PropelTypes::BINARY, PropelTypes::UUID_BINARY], true);
        if ($fromBinaryColumn && $toColumn->isTextType() && $toColumn->isContent('UUID')) {
            return $this->getChangeColumnFromUuidBinaryType($fromColumn, $toColumn);
        }

        return $this->getChangeColumnDDL($fromColumn, $toColumn);
    }

    /**
     * Builds the DDL SQL to change a column
     */
    public function getChangeColumnDDL(Column $fromColumn, Column $toColumn): string
    {
        $tableName = $this->quoteIdentifier($fromColumn->getTable()->getName());
        $columnName = $this->quoteIdentifier($fromColumn->getName());
        $columnDefinition = $this->getColumnDDL($toColumn);
        $pattern = "\nALTER TABLE %s CHANGE %s %s;\n";

        return sprintf($pattern, $tableName, $columnName, $columnDefinition);
    }

    /**
     * Builds the DDL SQL to modify a list of columns
     *
     * @param array<ColumnDiff> $columnDiffs
     */
    public function getModifyColumnsDDL(array $columnDiffs): string
    {
        $modifyColumnStatements = array_map([$this, 'getModifyColumnDDL'], $columnDiffs);

        return implode('', $modifyColumnStatements);
    }

    /**
     * Builds the DDL SQL to add a column
     */
    public function getAddColumnDDL(Column $column): string
    {
        $pattern = "
ALTER TABLE %s ADD %s %s;
";
        $tableColumns = $column->getTable()->getColumns();

        // Default to add first if no column is found before the current one
        $insertPositionDDL = 'FIRST';
        foreach ($tableColumns as $i => $tableColumn) {
            // We found the column, use the one before it if it's not the first
            if ($tableColumn->getName() == $column->getName()) {
                // We have a column that is not the first one
                if ($i > 0) {
                    $insertPositionDDL = 'AFTER ' . $this->quoteIdentifier($tableColumns[$i - 1]->getName());
                }

                break;
            }
        }

        return sprintf(
            $pattern,
            $this->quoteIdentifier($column->getTable()->getName()),
            $this->getColumnDDL($column),
            $insertPositionDDL,
        );
    }

    /**
     * Builds the DDL SQL to add a list of columns
     *
     * @param array<Column> $columns
     */
    public function getAddColumnsDDL(array $columns): string
    {
        $lines = '';
        foreach ($columns as $column) {
            $lines .= $this->getAddColumnDDL($column);
        }

        return $lines;
    }

    /**
     * @see Platform::supportsSchemas()
     *
     * @return bool
     */
    public function supportsSchemas(): bool
    {
        return true;
    }

    /**
     * @param string $sqlType
     *
     * @return bool
     */
    public function hasSize(string $sqlType): bool
    {
        return !in_array($sqlType, [
            'MEDIUMTEXT',
            'LONGTEXT',
            'BLOB',
            'MEDIUMBLOB',
            'LONGBLOB',
        ], true);
    }

    /**
     * @return array<int>
     */
    public function getDefaultTypeSizes(): array
    {
        return [
            'char' => 1,
            'tinyint' => 4,
            'smallint' => 6,
            'int' => 11,
            'bigint' => 20,
            'decimal' => 10,
        ];
    }

    /**
     * Escape the string for RDBMS.
     *
     * @param string $text
     *
     * @return string
     */
    public function disconnectedEscapeText(string $text): string
    {
        return addslashes($text);
    }

    /**
     * {@inheritDoc}
     *
     * MySQL documentation says that identifiers cannot contain '.'. Thus it
     * should be safe to split the string by '.' and quote each part individually
     * to allow for a <schema>.<table> or <table>.<column> syntax.
     *
     * @param string $text the identifier
     *
     * @return string the quoted identifier
     */
    public function doQuoting(string $text): string
    {
        return '`' . strtr($text, ['.' => '`.`']) . '`';
    }

    public function getColumnBindingPHP(Column $column, string $identifier, string $columnValueAccessor, string $tab = '            '): string
    {
        // FIXME - This is a temporary hack to get around apparent bugs w/ PDO+MYSQL
        // See http://pecl.php.net/bugs/bug.php?id=9919
        if ($column->getPDOType() === PDO::PARAM_BOOL) {
            return sprintf(
                "
%s\$stmt->bindValue(%s, (int) %s, PDO::PARAM_INT);",
                $tab,
                $identifier,
                $columnValueAccessor,
            );
        }

        return parent::getColumnBindingPHP($column, $identifier, $columnValueAccessor, $tab);
    }

    /**
     * Get the default On Delete behavior for foreign keys when not explicity set.
     */
    public function getDefaultForeignKeyOnDeleteBehavior(): string
    {
        $majorVersion = $this->getMajorServerVersionNumber();

        return ($majorVersion && $majorVersion >= 8 && !$this->isMariaDB()) ? ForeignKey::NOACTION : ForeignKey::RESTRICT;
    }

    /**
     * Get the default On Update behavior for foreign keys when not explicity set.
     */
    public function getDefaultForeignKeyOnUpdateBehavior(): string
    {
        $majorVersion = $this->getMajorServerVersionNumber();

        return ($majorVersion && $majorVersion >= 8 && !$this->isMariaDB()) ? ForeignKey::NOACTION : ForeignKey::RESTRICT;
    }

    /**
     * Get the server version of the platform
     */
    protected function getServerVersion(): ?string
    {
        if (!$this->serverVersion && $this->con) {
            $this->serverVersion = $this->con->getAttribute(PDO::ATTR_SERVER_VERSION);
        }

        return $this->serverVersion;
    }

    /**
     * Get the extracted major server version number
     *
     * @return int|null
     */
    protected function getMajorServerVersionNumber(): ?int
    {
        $serverVersion = $this->getServerVersion();
        if (!$serverVersion) {
            return null;
        }
        $dotPos = strpos($serverVersion, '.');
        if ($dotPos === false) {
            return null;
        }

        return (int)substr($serverVersion, 0, $dotPos - 1);
    }

    /**
     * Whether the platform is running on a MariaDB server
     *
     * @return bool
     */
    protected function isMariaDB(): bool
    {
        $serverVersion = $this->getServerVersion() ?? '';

        return stripos($serverVersion, 'mariadb') !== false;
    }

    /**
     * Whether RDBMS supports INSERT null values in autoincremented primary keys
     *
     * @return bool
     */
    public function supportsInsertNullPk(): bool
    {
        return false;
    }
}
