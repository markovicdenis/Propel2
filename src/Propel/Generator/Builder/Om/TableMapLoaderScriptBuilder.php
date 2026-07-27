<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om;

use Propel\Common\Util\PathTrait;
use Propel\Generator\Builder\Util\PropelTemplate;
use Propel\Generator\Config\GeneratorConfigInterface;
use Propel\Generator\Exception\BuildException;
use Propel\Generator\Model\Database;
use Propel\Generator\Model\Table;
use Propel\Generator\Util\PhpValueExporter;
use Propel\Runtime\Map\DatabaseMap;
use SplFileInfo;

use function is_array;

/**
 * Generates a database loader file, which is used to register all table maps with the DatabaseMap.
 */
class TableMapLoaderScriptBuilder
{
    use PathTrait;

    /**
     * @var string
     */
    public const FILENAME = 'loadDatabase.php';

    /**
     * @var GeneratorConfigInterface
     */
    protected $generatorConfig;

    public function __construct(GeneratorConfigInterface $generatorConfig)
    {
        $this->generatorConfig = $generatorConfig;
    }

    /**
     * @param array $schemas
     *
     * @return string
     */
    public function build(array $schemas): string
    {
        $templatePath = $this->getTemplatePath(__DIR__);

        $filePath = $templatePath . 'tableMapLoaderScript.php';
        $template = new PropelTemplate();
        $template->setTemplateFile($filePath);

        $databaseNameToTableMapDumps = $this->buildDatabaseNameToTableMapDumps($schemas);
        $vars = [
            'databaseNameToTableMapDumpsExport' => PhpValueExporter::export($databaseNameToTableMapDumps),
        ];

        return $template->render($vars);
    }

    /**
     * @param array<\Propel\Generator\Model\Schema> $schemas
     *
     * @throws BuildException
     */
    protected function buildDatabaseNameToTableMapDumps(array $schemas): array
    {
        $entries = [];
        foreach ($schemas as $schema) {
            foreach ($schema->getDatabases(false) as $database) {
                $databaseName = $database->getName();
                if (!$databaseName) {
                    throw new BuildException('Cannot build table map of unnamed database');
                }
                $tableMapDumps = $this->buildDatabaseMap($database)->dumpMaps();
                $entries[] = [$databaseName => $tableMapDumps];
            }
        }
        $databaseNameToTableMapDumps = array_merge_recursive(...$entries);
        $this->sortRecursive($databaseNameToTableMapDumps);

        return $databaseNameToTableMapDumps;
    }

    protected function buildDatabaseMap(Database $database): DatabaseMap
    {
        $databaseName = $database->getName();
        $databaseMap = new DatabaseMap($databaseName);
        foreach ($database->getTables() as $table) {
            if ($table->isSkipPhp()) {
                continue;
            }

            $tableName = $table->getName();
            $phpName = $table->getPhpName();
            $tableMapClass = $this->getFullyQualifiedTableMapClassName($table);
            $databaseMap->registerTableMapClassByName($tableName, $phpName, $tableMapClass);
        }

        return $databaseMap;
    }

    /**
     * @param array $array
     *
     * @return void
     */
    protected function sortRecursive(array &$array): void
    {
        ksort($array, SORT_STRING);
        array_walk($array, fn (&$value) => is_array($value) && $this->sortRecursive($value));
    }

    /**
     * @return class-string<\Propel\Runtime\Map\TableMap>
     */
    protected function getFullyQualifiedTableMapClassName(Table $table): string
    {
        $builder = new TableMapBuilder($table);
        $builder->setGeneratorConfig($this->generatorConfig);

        /** @var class-string<\Propel\Runtime\Map\TableMap> */
        return $builder->getFullyQualifiedClassName();
    }

    /**
     * Return file info object pointing to the designated script location. The file does not necessarily exist.
     *
     * @return SplFileInfo
     */
    public function getFile(): SplFileInfo
    {
        $configDir = $this->generatorConfig->getConfigProperty('paths.loaderScriptDir') ?? $this->generatorConfig->getConfigProperty('paths.phpConfDir');

        return new SplFileInfo($configDir . DIRECTORY_SEPARATOR . self::FILENAME);
    }
}
