<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\Connection;

use PDO;
use PHPUnit\Framework\TestCase;
use Propel\Runtime\Connection\PdoConnection;

class PdoConnectionTest extends TestCase
{
    /**
     * @return void
     */
    public function testLastInsertIdForwardsOptionalSequenceName()
    {
        $pdo = new class () extends PDO {
            public ?string $receivedSequenceName = null;

            public function __construct()
            {
            }

            public function lastInsertId(?string $name = null): string|false
            {
                $this->receivedSequenceName = $name;

                return '42';
            }
        };

        $connection = new class ($pdo) extends PdoConnection {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };

        $this->assertSame('42', $connection->lastInsertId('logs_id_seq'));
        $this->assertSame('logs_id_seq', $pdo->receivedSequenceName);
    }
}
