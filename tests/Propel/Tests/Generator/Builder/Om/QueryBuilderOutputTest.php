<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Builder\Om;

use Propel\Generator\Builder\Om\QueryBuilder;
use Propel\Generator\Builder\Util\SchemaReader;
use Propel\Generator\Config\QuickGeneratorConfig;
use Propel\Generator\Platform\DefaultPlatform;
use Propel\Tests\TestCase;

class QueryBuilderOutputTest extends TestCase
{
    private function createBuilder(string $tableName = 'book'): QueryBuilder
    {
        $databaseXml = <<<XML
<database name="default" namespace="Example\Books" package="Books">
    <table name="author">
        <column name="id" type="integer" primaryKey="true"/>
    </table>
    <table name="book">
        <column name="id" type="integer" primaryKey="true"/>
        <column name="author_id" type="integer"/>
        <foreign-key foreignTable="author">
            <reference local="author_id" foreign="id"/>
        </foreign-key>
    </table>
</database>
XML;
        $reader = new SchemaReader();
        $schema = $reader->parseString($databaseXml);
        $table = $schema->getDatabase()->getTable($tableName);

        $builder = new class ($table) extends QueryBuilder {
            public function getConstructorDefinition(): string
            {
                $script = '';
                $this->addConstructor($script);

                return $script;
            }

            public function getFactoryDefinition(): string
            {
                $script = '';
                $this->addFactory($script);

                return $script;
            }

            public function getHeaderDefinition(): string
            {
                $script = '';
                $this->addClassOpen($script);

                return $script;
            }

            public function getUseForeignKeyQueryDefinition(): string
            {
                $script = '';
                $foreignKey = $this->getTable()->getForeignKeys()[0];
                $this->addUseFKQuery($script, $foreignKey);

                return $script;
            }
        };
        $builder->setGeneratorConfig(new QuickGeneratorConfig());
        $builder->setPlatform(new DefaultPlatform());

        return $builder;
    }

    /**
     * @return void
     */
    public function testConstructorUsesNullableStringTypes()
    {
        $builder = $this->createBuilder();
        $constructorDefinition = $builder->getConstructorDefinition();

        $this->assertStringContainsString('@param string|null $dbName The database name', $constructorDefinition);
        $this->assertStringContainsString('@param string|null $modelName The phpName of a model, e.g. \'Book\'', $constructorDefinition);
        $this->assertStringContainsString('@param string|null $modelAlias The alias for the model in this query, e.g. \'b\'', $constructorDefinition);
        $this->assertStringContainsString(
            "public function __construct(?string \$dbName = 'default', ?string \$modelName = '\\\\Example\\\\Books\\\\Book', ?string \$modelAlias = null)",
            $constructorDefinition
        );
    }

    /**
     * @return void
     */
    public function testFactoryUsesConcreteQueryReturnType()
    {
        $builder = $this->createBuilder();
        $factoryDefinition = $builder->getFactoryDefinition();

        $this->assertStringContainsString('@param string|null $modelAlias The alias of a model in the query', $factoryDefinition);
        $this->assertStringContainsString('@param Criteria|null $criteria Optional Criteria to build the query from', $factoryDefinition);
        $this->assertStringContainsString('@return ChildBookQuery', $factoryDefinition);
        $this->assertStringContainsString(
            'public static function create(?string $modelAlias = null, ?Criteria $criteria = null): ChildBookQuery',
            $factoryDefinition
        );
    }

    /**
     * @return void
     */
    public function testRelatedQueryHelpersUseNativeTypes()
    {
        $builder = $this->createBuilder();
        $relatedQueryDefinition = $builder->getUseForeignKeyQueryDefinition();

        $this->assertStringContainsString('@param string|null $relationAlias optional alias for the relation,', $relatedQueryDefinition);
        $this->assertStringContainsString('@param string|null $joinType Accepted values are null, \'left join\', \'right join\', \'inner join\'', $relatedQueryDefinition);
        $this->assertStringContainsString(
            'public function useAuthorQuery(?string $relationAlias = null, ?string $joinType = Criteria::LEFT_JOIN): \Example\Books\AuthorQuery',
            $relatedQueryDefinition
        );
        $this->assertStringContainsString('@return static', $relatedQueryDefinition);
        $this->assertStringContainsString('public function withAuthorQuery(', $relatedQueryDefinition);
        $this->assertStringContainsString('    ): static {', $relatedQueryDefinition);
        $this->assertStringContainsString(
            'public function useAuthorExistsQuery(?string $modelAlias = null, ?string $queryClass = null, string $typeOfExists = \'EXISTS\'): \Example\Books\AuthorQuery',
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            'public function useAuthorNotExistsQuery(?string $modelAlias = null, ?string $queryClass = null): \Example\Books\AuthorQuery',
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            'public function useInAuthorQuery(?string $modelAlias = null, ?string $queryClass = null, string $typeOfIn = \' IN \'): \Example\Books\AuthorQuery',
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            'public function useNotInAuthorQuery(?string $modelAlias = null, ?string $queryClass = null): \Example\Books\AuthorQuery',
            $relatedQueryDefinition
        );
    }

    /**
     * @return void
     */
    public function testHeaderAddsPhpstanMagicMethodsForCollections()
    {
        $builder = $this->createBuilder();
        $headerDefinition = $builder->getHeaderDefinition();

        $this->assertStringContainsString(
            '@phpstan-method Collection&\Traversable<ChildBook> find(?ConnectionInterface $con = null)',
            $headerDefinition
        );
        $this->assertStringContainsString(
            '@phpstan-method Collection&\Traversable<ChildBook> findById(int|array<int> $id)',
            $headerDefinition
        );
        $this->assertStringContainsString(
            '@phpstan-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildBook> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null)',
            $headerDefinition
        );
    }
}
