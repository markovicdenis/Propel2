<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Util;

use Propel\Generator\Util\PhpValueExporter;
use Propel\Tests\TestCase;

class PhpValueExporterTest extends TestCase
{
    /**
     * @return void
     */
    public function testExportFormatsNestedArraysWithShortSyntaxAndIndentation()
    {
        $value = [
            'bookstore' => [
                'tablesByName' => [
                    'author' => '\\ExampleNamespace\\Bookstore\\Map\\AuthorTableMap',
                ],
            ],
        ];

        $expected = <<<'PHP'
[
    'bookstore' => [
        'tablesByName' => [
            'author' => '\ExampleNamespace\Bookstore\Map\AuthorTableMap',
        ],
    ],
]
PHP;

        $this->assertSame($expected, PhpValueExporter::export($value));
    }

    /**
     * @return void
     */
    public function testExportSupportsCustomBaseIndentation()
    {
        $value = ['nested' => ['answer' => 42]];

        $expected = <<<'PHP'
[
                'nested' => [
                    'answer' => 42,
                ],
            ]
PHP;

        $this->assertSame($expected, PhpValueExporter::export($value, 3));
    }
}
