<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Behavior\CheckConstraint;

use Propel\Generator\Exception\InvalidArgumentException;
use Propel\Generator\Model\Behavior;
use Propel\Generator\Platform\PlatformInterface;

use function sprintf;
use function strlen;
use function trim;

/**
 * Emits table-level CHECK constraints declared in the schema.
 *
 * Propel's model has no concept of a CHECK, so invariants that the schema cannot express —
 * value ranges, cross-column coherence, counters that are written by SQL arithmetic and
 * therefore never pass through a PHP guard — had to be hand-written in a migration, where
 * they drift away from the table they belong to.
 *
 * Each parameter declares one constraint: the parameter name becomes the constraint name
 * suffix, the value is the expression.
 *
 * <code>
 * <table name="sportsbook_bets">
 *   <behavior name="check_constraint">
 *     <parameter name="stake_positive" value="stake &gt; 0"/>
 *     <parameter name="bonus_stake_bounded" value="bonus_stake &gt;= 0 AND bonus_stake &lt;= stake"/>
 *     <parameter name="settled_has_result" value="(status = 'settled') = (result IS NOT NULL)"/>
 *   </behavior>
 * </table>
 * </code>
 *
 * yields, inside CREATE TABLE:
 *
 * <code>
 * CONSTRAINT "sportsbook_bets_stake_positive" CHECK (stake > 0),
 * CONSTRAINT "sportsbook_bets_bonus_stake_bounded" CHECK (bonus_stake >= 0 AND bonus_stake <= stake),
 * CONSTRAINT "sportsbook_bets_settled_has_result" CHECK ((status = 'settled') = (result IS NOT NULL))
 * </code>
 *
 * The expression is passed through verbatim — it is SQL, not a Propel abstraction, so it is
 * the schema author's job to keep it valid for the target platform. Note also that the
 * reverse-engineering and diff pipelines do not read CHECK constraints, so `propel:diff`
 * will neither propose nor remove them; a changed expression needs its own migration.
 */
class CheckConstraintBehavior extends Behavior
{
    /**
     * PostgreSQL and most other engines cap identifiers at 63 bytes. Silently exceeding it
     * truncates, which can collide two constraints into one name.
     *
     * @var int
     */
    private const MAX_IDENTIFIER_LENGTH = 63;

    /**
     * Every parameter is a constraint, so there are no defaults to merge.
     *
     * @var array<string, mixed>
     */
    protected $parameters = [];

    /**
     * Allows a second instance under a distinct `id`, for schemas that group constraints.
     *
     * @return bool
     */
    public function allowMultiple(): bool
    {
        return true;
    }

    /**
     * @throws InvalidArgumentException
     *
     * @return array<string>
     */
    public function getTableConstraints(PlatformInterface $platform): array
    {
        $table = $this->getTableOrFail();
        $constraints = [];

        foreach ($this->getParameters() as $name => $expression) {
            $expression = trim((string)$expression);

            // An empty expression would emit `CHECK ()`, which fails at migration time with an
            // error pointing at the SQL rather than at the schema typo that produced it.
            if ($expression === '') {
                throw new InvalidArgumentException(sprintf(
                    'CHECK constraint "%s" on table "%s" has an empty expression.',
                    $name,
                    $table->getName(),
                ));
            }

            $constraintName = sprintf('%s_%s', $table->getName(), $name);

            if (strlen($constraintName) > self::MAX_IDENTIFIER_LENGTH) {
                throw new InvalidArgumentException(sprintf(
                    'CHECK constraint name "%s" is %d bytes, exceeding the %d-byte identifier limit. '
                    . 'Shorten the parameter name on table "%s".',
                    $constraintName,
                    strlen($constraintName),
                    self::MAX_IDENTIFIER_LENGTH,
                    $table->getName(),
                ));
            }

            $constraints[] = sprintf(
                'CONSTRAINT %s CHECK (%s)',
                $platform->quoteIdentifier($constraintName),
                $expression,
            );
        }

        return $constraints;
    }
}
