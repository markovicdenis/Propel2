<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\ActiveRecord\Persistence;

use PDO;
use Propel\Runtime\Connection\StatementInterface;

final class InsertColumnBindingDto
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $quotedColumnName,
        public mixed $value,
        public readonly int $pdoType = PDO::PARAM_STR,
    ) {
    }

    public function bind(StatementInterface $stmt): void
    {
        if (is_resource($this->value)) {
            rewind($this->value);
        }

        if ($this->pdoType === PDO::PARAM_BOOL && $this->value !== null) {
            $stmt->bindValue($this->identifier, (int)$this->value, PDO::PARAM_INT);

            return;
        }

        $stmt->bindValue($this->identifier, $this->value, $this->pdoType);
    }
}
