<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Schema\Dumper;

use PHPUnit\Framework\TestCase;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\Database;
use Propel\Generator\Model\Index;
use Propel\Generator\Model\Table;
use Propel\Generator\Model\Unique;
use Propel\Generator\Platform\MysqlPlatform;
use Propel\Generator\Schema\Dumper\XmlDumper;

class XmlDumperTest extends TestCase
{
    /**
     * The XmlDumper instance.
     *
     * @var \Propel\Generator\Schema\Dumper\XmlDumper
     */
    private $dumper;

    /**
     * @return void
     */
    public function testDumpDatabaseSchema()
    {
        $database = include realpath(__DIR__ . '/../../../Resources/blog-database.php');

        $this->assertSame($this->getExpectedXml('blog-database.xml'), $this->dumper->dump($database));
    }

    /**
     * @return void
     */
    public function testDumpSchema()
    {
        $schema = include realpath(__DIR__ . '/../../../Resources/blog-schema.php');

        $this->assertSame($this->getExpectedXml('blog-schema.xml'), $this->dumper->dumpSchema($schema, true));
    }

    /**
     * @return void
     */
    public function testDumpDatabaseSchemaWithTableShortName()
    {
        $database = new Database('bookstore');
        $table = new Table('sportsbook_transactions');
        $table->setShortName('sbtx');
        $database->addTable($table);

        $xml = $this->dumper->dump($database);

        $this->assertStringContainsString('name="sportsbook_transactions"', $xml);
        $this->assertStringContainsString('shortName="sbtx"', $xml);
    }

    /**
     * @return void
     */
    public function testDumpPartialUniqueIndex()
    {
        $database = new Database('bookstore');
        $database->setPlatform(new MysqlPlatform());
        $table = new Table('postback_user_unlinks');
        $database->addTable($table);

        $userIdColumn = new Column('user_id');
        $table->addColumn($userIdColumn);
        $configIdColumn = new Column('postback_config_id');
        $table->addColumn($configIdColumn);

        $unique = new Unique('postback_user_unlinks_active_unique');
        $unique->addColumn($userIdColumn);
        $unique->addColumn($configIdColumn);
        $unique->setWhere('revoked_at IS NULL');
        $table->addUnique($unique);

        $xml = $this->dumper->dump($database);

        $this->assertStringContainsString(
            '<unique name="postback_user_unlinks_active_unique" where="revoked_at IS NULL">',
            $xml,
        );
    }

    /**
     * @return void
     */
    public function testDumpColumnTransformer(): void
    {
        $database = new Database('bookstore');
        $table = new Table('currencies');
        $database->addTable($table);
        $column = new Column('currency_code');
        $column->setTransformer('uppercase');
        $table->addColumn($column);

        $xml = $this->dumper->dump($database);

        $this->assertStringContainsString('name="currency_code"', $xml);
        $this->assertStringContainsString('transformer="strtoupper"', $xml);
    }

    /**
     * @return void
     */
    public function testDumpPostgresqlIndexAttributes(): void
    {
        $database = new Database('bookstore');
        $database->setPlatform(new MysqlPlatform());
        $table = new Table('games');
        $database->addTable($table);
        $column = new Column('name');
        $table->addColumn($column);
        $index = new Index('idx_games_name_trgm');
        $index->setUsing('gin');
        $index->addColumn($column);
        $index->setColumnOperatorClass('name', 'gin_trgm_ops');
        $table->addIndex($index);

        $xml = $this->dumper->dump($database);

        $this->assertStringContainsString('<index name="idx_games_name_trgm" using="gin">', $xml);
        $this->assertStringContainsString('<index-column name="name" operatorClass="gin_trgm_ops"/>', $xml);
    }

    /**
     * @param string $filename
     *
     * @return string
     */
    protected function getExpectedXml($filename)
    {
        return trim(file_get_contents(realpath(__DIR__ . '/../../../Resources/' . $filename)));
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->dumper = new XmlDumper();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->dumper = null;
    }
}
