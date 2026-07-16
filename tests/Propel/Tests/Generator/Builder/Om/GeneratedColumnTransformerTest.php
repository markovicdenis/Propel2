<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Builder\Om;

use GeneratedColumnTransformerEntity;
use GeneratedColumnTransformerEntityQuery;
use PHPUnit\Framework\TestCase;
use Propel\Generator\Util\QuickBuilder;

class GeneratedColumnTransformerTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        if (!class_exists('GeneratedColumnTransformerEntity')) {
            QuickBuilder::buildSchema(<<<'XML'
<database name="generated_column_transformer_test">
    <table name="generated_column_transformer_entity">
        <column name="currency_code" primaryKey="true" required="true" type="VARCHAR" transformer="uppercase"/>
        <column name="locale" type="VARCHAR" transformer="strtolower"/>
        <column name="legacy_currency_codes" type="ARRAY" transformer="lowercase"/>
    </table>
</database>
XML);
        }
    }

    /**
     * @return void
     */
    public function testSettersNormalizeValues(): void
    {
        $entity = new GeneratedColumnTransformerEntity();

        $entity->setCurrencyCode('usd');
        $entity->setLocale('EN_us');
        $entity->setLegacyCurrencyCodes(['USD', null, 'EUR']);

        $this->assertSame('USD', $entity->getCurrencyCode());
        $this->assertSame('en_us', $entity->getLocale());
        $this->assertSame(['usd', null, 'eur'], $entity->getLegacyCurrencyCodes());
    }

    /**
     * @return void
     */
    public function testColumnFiltersNormalizeScalarAndArrayValues(): void
    {
        $params = [];
        $query = GeneratedColumnTransformerEntityQuery::create()
            ->filterByCurrencyCode(['usd', 'eur']);

        $query->createSelectSql($params);

        $this->assertSame('USD', $params[0]['value']);
        $this->assertSame('EUR', $params[1]['value']);
    }

    /**
     * @return void
     */
    public function testPrimaryKeyFiltersNormalizeValues(): void
    {
        $params = [];
        GeneratedColumnTransformerEntityQuery::create()
            ->filterByPrimaryKey('gbp')
            ->createSelectSql($params);

        $this->assertSame('GBP', $params[0]['value']);

        $params = [];
        GeneratedColumnTransformerEntityQuery::create()
            ->filterByPrimaryKeys(['cad', 'aud'])
            ->createSelectSql($params);

        $this->assertSame('CAD', $params[0]['value']);
        $this->assertSame('AUD', $params[1]['value']);
    }
}
