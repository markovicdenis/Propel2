<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Common\Util;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Propel\Common\Util\PgsqlArrayCodec;

class PgsqlArrayCodecTest extends TestCase
{
    /**
     * @return void
     */
    public function testEncodeEscapesSpecialValuesAndPreservesNull(): void
    {
        $this->assertSame(
            '{"plain","with,comma","with\\\\slash","with\\"quote","",NULL,"NULL"}',
            PgsqlArrayCodec::encode(['plain', 'with,comma', 'with\\slash', 'with"quote', '', null, 'NULL']),
        );
    }

    /**
     * @return void
     */
    public function testDecodeTextArray(): void
    {
        $this->assertSame(
            ['plain', 'with,comma', 'with\\slash', 'with"quote', '', null, 'NULL'],
            PgsqlArrayCodec::decode('{plain,"with,comma","with\\\\slash","with\\"quote","",NULL,"NULL"}', 'TEXT'),
        );
    }

    /**
     * @return void
     */
    public function testDecodeCastsSupportedScalarTypes(): void
    {
        $this->assertSame([1, 2, null], PgsqlArrayCodec::decode('{1,2,NULL}', 'INTEGER'));
        $this->assertSame([true, false], PgsqlArrayCodec::decode('{t,false}', 'BOOLEAN'));
        $this->assertSame([1.5, 2.0], PgsqlArrayCodec::decode('{1.5,2}', 'DOUBLE PRECISION'));
        $this->assertSame(['9223372036854775807'], PgsqlArrayCodec::decode('{9223372036854775807}', 'BIGINT'));
    }

    /**
     * @return void
     */
    public function testDecodeSupportsExplicitBounds(): void
    {
        $this->assertSame([7, 8], PgsqlArrayCodec::decode('[0:1]={7,8}', 'INTEGER'));
    }

    /**
     * @return void
     */
    public function testNestedArraysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PgsqlArrayCodec::decode('{{1,2},{3,4}}', 'INTEGER');
    }
}
