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

class QueryBuilderOutputTestBuilder extends QueryBuilder
{
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

    public function getClassBodyDefinition(): string
    {
        $script = '';
        $this->addClassBody($script);

        return $script;
    }
}

class QueryBuilderOutputTest extends TestCase
{
    private function createBuilder(string $tableName = 'book', bool $skipRefCode = false): QueryBuilderOutputTestBuilder
    {
        $skipRefCodeAttribute = $skipRefCode ? ' skipRefCode="true"' : '';
        $databaseXml = <<<XML
<database name="default" namespace="Example\Books" package="Books">
    <table name="author">
        <column name="id" type="integer" primaryKey="true"/>
    </table>
    <table name="book">
        <column name="id" type="integer" primaryKey="true"/>
        <column name="author_id" type="integer"/>
        <foreign-key foreignTable="author"$skipRefCodeAttribute>
            <reference local="author_id" foreign="id"/>
        </foreign-key>
    </table>
</database>
XML;
        $reader = new SchemaReader();
        $schema = $reader->parseString($databaseXml);
        $table = $schema->getDatabase()->getTable($tableName);

        $builder = new QueryBuilderOutputTestBuilder($table);
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
    public function testConstructorAppliesCaseSensitivityDefaultsFromPropelVendorInfo()
    {
        $databaseXml = <<<'XML'
<database name="default" namespace="Example\Books" package="Books">
    <table name="inherited">
        <column name="id" type="integer" primaryKey="true"/>
    </table>
    <table name="overridden">
        <column name="id" type="integer" primaryKey="true"/>
        <vendor type="propel">
            <parameter name="defaultIgnoreCase" value="false"/>
        </vendor>
    </table>
    <vendor type="propel">
        <parameter name="defaultIgnoreCase" value="true"/>
        <parameter name="defaultLikeIgnoreCase" value="true"/>
    </vendor>
</database>
XML;

        $inheritedConstructor = TestableQueryBuilder::forTableFromXml($databaseXml, 'inherited')->buildScript('addConstructor');
        $this->assertStringContainsString('$this->setIgnoreCase(true);', $inheritedConstructor);
        $this->assertStringContainsString('$this->setLikeIgnoreCase(true);', $inheritedConstructor);

        $overriddenConstructor = TestableQueryBuilder::forTableFromXml($databaseXml, 'overridden')->buildScript('addConstructor');
        $this->assertStringNotContainsString('$this->setIgnoreCase(true);', $overriddenConstructor);
        $this->assertStringContainsString('$this->setLikeIgnoreCase(true);', $overriddenConstructor);
    }

    /**
     * @return void
     */
    public function testFactoryUsesLegacyCriteriaReturnType()
    {
        $builder = $this->createBuilder();
        $factoryDefinition = $builder->getFactoryDefinition();

        $this->assertStringContainsString('@param ?string $modelAlias The alias of a model in the query', $factoryDefinition);
        $this->assertStringContainsString('@param ?Criteria $criteria Optional Criteria to build the query from', $factoryDefinition);
        $this->assertStringContainsString('@return ChildBookQuery', $factoryDefinition);
        $this->assertStringContainsString(
            'public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria',
            $factoryDefinition
        );
        $this->assertStringContainsString('if ($criteria instanceof ChildBookQuery) {', $factoryDefinition);
        $this->assertStringContainsString('$query = new ChildBookQuery();', $factoryDefinition);
        $this->assertStringNotContainsString('static::class', $factoryDefinition);
        $this->assertStringNotContainsString('new static();', $factoryDefinition);
        $this->assertStringNotContainsString('new $queryClass();', $factoryDefinition);
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
        $this->assertStringContainsString(
            "return \$this",
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            "->joinAuthor(\$relationAlias, \$joinType)",
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            "->useQuery(\$relationAlias ?: 'Author', '\\Example\\Books\\AuthorQuery');",
            $relatedQueryDefinition
        );
        $this->assertStringContainsString('@return static', $relatedQueryDefinition);
        $this->assertStringContainsString(
            "\$relatedQuery = \$this->useAuthorQuery(",
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            "\$joinType ?? Criteria::LEFT_JOIN",
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            "\$callable(\$relatedQuery);",
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            "\$relatedQuery->endUse();",
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            '@return \Example\Books\AuthorQuery|ModelCriteria The inner query object of the EXISTS statement',
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            '@psalm-return ($queryClass is null ? \Example\Books\AuthorQuery : TQuery)',
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            'public function useAuthorExistsQuery(?string $modelAlias = null, ?string $queryClass = null, string $typeOfExists = \'EXISTS\'): ModelCriteria',
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            "\$q = \$this->useExistsQuery('Author', \$modelAlias, \$queryClass, \$typeOfExists);",
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            'public function useAuthorNotExistsQuery(?string $modelAlias = null, ?string $queryClass = null): ModelCriteria',
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            '@return \Example\Books\AuthorQuery|ModelCriteria The inner query object of the IN statement',
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            'public function useInAuthorQuery(?string $modelAlias = null, ?string $queryClass = null, string $typeOfIn = \' IN \'): ModelCriteria',
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            "\$q = \$this->useInQuery('Author', \$modelAlias, \$queryClass, \$typeOfIn);",
            $relatedQueryDefinition
        );
        $this->assertStringContainsString(
            'public function useNotInAuthorQuery(?string $modelAlias = null, ?string $queryClass = null): ModelCriteria',
            $relatedQueryDefinition
        );
    }

    /**
     * @return void
     */
    public function testHeaderKeepsPsalmMagicMethodsForCollections()
    {
        $builder = $this->createBuilder();
        $headerDefinition = $builder->getHeaderDefinition();

        $this->assertStringContainsString(
            '@psalm-method Collection&\Traversable<ChildBook> find(?ConnectionInterface $con = null)',
            $headerDefinition
        );
        $this->assertStringContainsString(
            '@psalm-method Collection&\Traversable<ChildBook> findById(int|array<int> $id)',
            $headerDefinition
        );
        $this->assertStringContainsString(
            '@psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildBook> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null)',
            $headerDefinition
        );
        $this->assertStringNotContainsString('@phpstan-method', $headerDefinition);
    }

    /**
     * @return void
     */
    public function testClassBodyInlinesIntervalsAndRelations()
    {
        $builder = $this->createBuilder();
        $classBodyDefinition = $builder->getClassBodyDefinition();

        $this->assertStringNotContainsString('private function applyIntervalFilter(', $classBodyDefinition);
        $this->assertStringNotContainsString('private function addRelationJoin(', $classBodyDefinition);
        $this->assertStringNotContainsString('private function useRelatedQueryObject(', $classBodyDefinition);
        $this->assertStringNotContainsString('private function withRelatedQueryObject(', $classBodyDefinition);
        $this->assertStringNotContainsString('private function useRelatedExistsQueryObject(', $classBodyDefinition);
        $this->assertStringNotContainsString('private function useRelatedInQueryObject(', $classBodyDefinition);
        $this->assertStringContainsString('if (is_array($id)) {', $classBodyDefinition);
        $this->assertStringContainsString('$useMinMax = false;', $classBodyDefinition);
        $this->assertStringContainsString('$tableMap = $this->getTableMap();', $classBodyDefinition);
        $this->assertStringContainsString('assert($tableMap instanceof BookTableMap);', $classBodyDefinition);
        $this->assertStringContainsString("\$relationMap = \$tableMap->getRelation('Author');", $classBodyDefinition);
    }

    /**
     * @return void
     */
    public function testSkipRefCodeSuppressesReverseRelationQueryMethods()
    {
        $bookBuilder = $this->createBuilder('book', true);
        $bookClassBodyDefinition = $bookBuilder->getClassBodyDefinition();

        $this->assertStringContainsString('public function filterByAuthor(', $bookClassBodyDefinition);
        $this->assertStringContainsString('public function joinAuthor(', $bookClassBodyDefinition);
        $this->assertStringContainsString('public function useAuthorQuery(', $bookClassBodyDefinition);

        $authorBuilder = $this->createBuilder('author', true);
        $authorClassBodyDefinition = $authorBuilder->getClassBodyDefinition();

        $this->assertStringNotContainsString('public function filterByBook(', $authorClassBodyDefinition);
        $this->assertStringNotContainsString('public function joinBook(', $authorClassBodyDefinition);
        $this->assertStringNotContainsString('public function useBookQuery(', $authorClassBodyDefinition);
    }

    /**
     * The query is the criteria it used to alias, so the closure can use $this directly.
     *
     * @return void
     */
    public function testDeleteUsesThisInsteadOfACriteriaAlias()
    {
        $deleteDefinition = TestableQueryBuilder::forTableFromXml(self::CASCADE_SCHEMA_XML, 'author')->buildScript('addDelete');

        $this->assertStringNotContainsString('$criteria', $deleteDefinition);
        $this->assertStringContainsString('return $con->transaction(function () use ($con) {', $deleteDefinition);
        $this->assertStringContainsString('$this->setDbName(AuthorTableMap::DATABASE_NAME);', $deleteDefinition);
        $this->assertStringContainsString('AuthorTableMap::removeInstanceFromPool($this);', $deleteDefinition);
    }

    /**
     * @return void
     */
    public function testDeleteClonesThisForDeleteCascadeEmulation()
    {
        $deleteDefinition = TestableQueryBuilder::forTableFromXml(self::CASCADE_SCHEMA_XML, 'author')->buildScript('addDelete');

        $this->assertStringContainsString('$c = clone $this;', $deleteDefinition);
        $this->assertStringContainsString('$affectedRows += $c->doOnDeleteCascade($con);', $deleteDefinition);
    }

    /**
     * Object columns have no PHP type, which used to end up in the magic method as `(|array<> $obj)`.
     *
     * @return void
     */
    public function testHeaderUsesValidTypeInMagicMethodsOfObjectColumns()
    {
        $schemaXml = <<<'XML'
<database name="default" namespace="Example\Books" package="Books">
    <table name="book">
        <column name="id" type="integer" primaryKey="true"/>
        <column name="payload" type="OBJECT"/>
    </table>
</database>
XML;

        $headerDefinition = TestableQueryBuilder::forTableFromXml($schemaXml, 'book')->buildScript('addClassOpen');

        $this->assertStringContainsString('findByPayload(mixed|array<mixed> $payload)', $headerDefinition);
        $this->assertStringNotContainsString('array<>', $headerDefinition);
    }

    /**
     * The doc type of a text column does not allow null, so the null check of the transformer
     * reads as redundant, while the filter can be built programmatically.
     *
     * @return void
     */
    public function testTransformerOfTextColumnIgnoresTheRedundantNullCheck()
    {
        $filterDefinition = $this->buildFilterByCol('label');

        $this->assertStringContainsString(
            "\$label = array_map(\n                // @phpstan-ignore identical.alwaysFalse",
            $filterDefinition,
        );
        $this->assertStringContainsString('static fn ($value) => $value === null ? null : strtoupper($value),', $filterDefinition);
    }

    /**
     * The null check is not redundant in the doc type of an array column, an ignore would be
     * reported as unused there.
     *
     * @return void
     */
    public function testTransformerOfArrayColumnKeepsTheNullCheckUnignored()
    {
        $filterDefinition = $this->buildFilterByCol('tags');

        $this->assertStringContainsString('static fn ($value) => $value === null ? null : strtolower($value),', $filterDefinition);
        $this->assertStringNotContainsString('@phpstan-ignore', $filterDefinition);
    }

    /**
     * The doc type of a native array filter does not allow anything but an array, so its type
     * guard reads as redundant, while the filter can be built programmatically.
     *
     * @return void
     */
    public function testNativeArrayFilterIgnoresTheRedundantTypeGuard()
    {
        $filterDefinition = $this->buildFilterByCol('codes');

        $this->assertStringContainsString(
            "// @phpstan-ignore function.alreadyNarrowedType"
                . " (the doc type is not enforced when the query is built programmatically)\n"
                . '        if (!is_array($codes)) {',
            $filterDefinition,
        );
        $this->assertStringContainsString("throw new PropelException('NATIVE_ARRAY filters require a PHP array.');", $filterDefinition);
    }

    /**
     * The key of a composite primary key is documented as an array, which narrows the mixed key
     * of the parent method.
     *
     * @return void
     */
    public function testFindPkOfCompositeKeyIgnoresTheNarrowedKeyType()
    {
        $findPkDefinition = TestableQueryBuilder::forTableFromXml(self::COMPOSITE_KEY_SCHEMA_XML, 'composite')
            ->buildScript('addFindPk');

        // the ignore has to be the last tag, it is parsed to the end of the comment
        $this->assertStringContainsString(
            '     * @phpstan-ignore method.childParameterType'
                . " (the array type of a composite key is kept for ease of use)\n"
                . "     */\n"
                . '    public function findPk($key, ?ConnectionInterface $con = null)',
            $findPkDefinition,
        );
        $this->assertStringContainsString('@param array $key Primary key to use for the query array[$a_id, $b_id]', $findPkDefinition);
    }

    /**
     * A single primary key keeps the mixed key of the parent method, an ignore would be reported
     * as unused there.
     *
     * @return void
     */
    public function testFindPkOfSingleKeyKeepsTheKeyTypeUnignored()
    {
        $findPkDefinition = TestableQueryBuilder::forTableFromXml(self::COMPOSITE_KEY_SCHEMA_XML, 'single')
            ->buildScript('addFindPk');

        $this->assertStringContainsString('@param mixed $key Primary key to use for the query', $findPkDefinition);
        $this->assertStringNotContainsString('@phpstan-ignore', $findPkDefinition);
    }

    /**
     * @var string
     */
    private const COMPOSITE_KEY_SCHEMA_XML = <<<'XML'
<database name="default" namespace="Example\Books" package="Books">
    <table name="composite">
        <column name="a_id" type="integer" primaryKey="true" required="true"/>
        <column name="b_id" type="integer" primaryKey="true" required="true"/>
    </table>
    <table name="single">
        <column name="id" type="integer" primaryKey="true"/>
    </table>
</database>
XML;

    /**
     * @param string $columnName
     *
     * @return string
     */
    private function buildFilterByCol(string $columnName): string
    {
        $builder = TestableQueryBuilder::forTableFromXml(self::FILTER_SCHEMA_XML, 'item');

        return $builder->buildScript('addFilterByCol', $builder->getTable()->getColumn($columnName));
    }

    /**
     * @var string
     */
    private const FILTER_SCHEMA_XML = <<<'XML'
<database name="default" namespace="Example\Books" package="Books">
    <table name="item">
        <column name="id" type="integer" primaryKey="true"/>
        <column name="label" type="VARCHAR" size="10" transformer="uppercase"/>
        <column name="tags" type="ARRAY" transformer="lowercase"/>
        <column name="codes" type="NATIVE_ARRAY" sqlType="TEXT[]"/>
    </table>
</database>
XML;

    /**
     * @var string
     */
    private const CASCADE_SCHEMA_XML = <<<'XML'
<database name="default" namespace="Example\Books" package="Books">
    <table name="author">
        <column name="id" type="integer" primaryKey="true"/>
    </table>
    <table name="book">
        <column name="id" type="integer" primaryKey="true"/>
        <column name="author_id" type="integer"/>
        <foreign-key foreignTable="author" onDelete="cascade">
            <reference local="author_id" foreign="id"/>
        </foreign-key>
    </table>
</database>
XML;
}
