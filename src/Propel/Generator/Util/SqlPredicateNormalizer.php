<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Util;

use function preg_replace;
use function preg_replace_callback;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * Brings SQL predicates into a comparable form.
 *
 * Predicates that are read back from a database are rewritten by the database, so comparing them
 * to the predicate of a schema by string comparison reports a difference on every run, even though
 * both describe the same condition. PostgreSQL for example stores `status = 'open'` as
 * `status::text = 'open'::text` and `status IN ('a', 'b')` as
 * `status::text = ANY (ARRAY['a'::character varying, 'b'::character varying]::text[])`.
 *
 * The normalized form is only meant for comparison, it is not valid SQL to run.
 */
class SqlPredicateNormalizer
{
    /**
     * Check whether two predicates describe the same condition.
     *
     * @param string|null $predicate
     * @param string|null $otherPredicate
     *
     * @return bool
     */
    public static function areEqual(?string $predicate, ?string $otherPredicate): bool
    {
        return static::normalize($predicate) === static::normalize($otherPredicate);
    }

    /**
     * Bring a predicate into a comparable form.
     *
     * @param string|null $predicate
     *
     * @return string|null
     */
    public static function normalize(?string $predicate): ?string
    {
        if ($predicate === null) {
            return null;
        }

        $predicate = static::lowercaseOutsideStringLiterals($predicate);
        $predicate = static::removeTypeCasts($predicate);
        $predicate = static::inlineArrayComparisons($predicate);
        $predicate = static::canonicalizeOperators($predicate);
        $predicate = static::removeInsignificantWhitespace($predicate);
        $predicate = static::removeEnclosingParentheses($predicate);

        return $predicate === '' ? null : $predicate;
    }

    /**
     * Lowercase everything but string literals and quoted identifiers, as SQL keywords and
     * unquoted identifiers are case insensitive.
     *
     * @param string $predicate
     *
     * @return string
     */
    protected static function lowercaseOutsideStringLiterals(string $predicate): string
    {
        return static::mapUnquotedParts($predicate, static fn (string $part): string => strtolower($part));
    }

    /**
     * Remove the type casts a database adds to the values and columns of a predicate, for example
     * `status::text = 'open'::character varying` or `tags::text[] = '{}'::text[]`.
     *
     * @param string $predicate
     *
     * @return string
     */
    protected static function removeTypeCasts(string $predicate): string
    {
        $typeName = '(?:character\s+varying|bit\s+varying|double\s+precision'
            . '|(?:timestamp|time)(?:\s+with(?:out)?\s+time\s+zone)?'
            . '|[a-z_][a-z0-9_]*)';

        return static::mapUnquotedParts(
            $predicate,
            static fn (string $part): string => (string)preg_replace(
                '/::\s*' . $typeName . '(\([^)]*\))?(\s*\[\s*\])*/',
                '',
                $part,
            ),
        );
    }

    /**
     * Rewrite the array comparisons a database stores for `IN` and `NOT IN` lists back into lists,
     * for example `status = ANY (ARRAY['a', 'b'])` and `status <> ALL (ARRAY['a', 'b'])`.
     *
     * @param string $predicate
     *
     * @return string
     */
    protected static function inlineArrayComparisons(string $predicate): string
    {
        $predicate = (string)preg_replace(
            '/=\s*any\s*\(\s*array\s*\[([^\]]*)\]\s*\)/',
            'in ($1)',
            $predicate,
        );

        return (string)preg_replace(
            '/(?:<>|!=)\s*all\s*\(\s*array\s*\[([^\]]*)\]\s*\)/',
            'not in ($1)',
            $predicate,
        );
    }

    /**
     * Rewrite the operators that a database stores under a different name, and lists holding a
     * single value, which a database stores as a plain comparison.
     *
     * @param string $predicate
     *
     * @return string
     */
    protected static function canonicalizeOperators(string $predicate): string
    {
        $predicate = static::mapUnquotedParts($predicate, static function (string $part): string {
            $operators = [
                '/\bnot\s+ilike\b/' => '!~~*',
                '/\bnot\s+like\b/' => '!~~',
                '/\bilike\b/' => '~~*',
                '/\blike\b/' => '~~',
                '/!=/' => '<>',
            ];

            foreach ($operators as $pattern => $operator) {
                $part = (string)preg_replace($pattern, $operator, $part);
            }

            return $part;
        });

        // A list of a single value is stored as a comparison to that value.
        $predicate = (string)preg_replace('/\bnot\s+in\s*\(\s*([^(),]+?)\s*\)/', '<> $1', $predicate);

        return (string)preg_replace('/\bin\s*\(\s*([^(),]+?)\s*\)/', '= $1', $predicate);
    }

    /**
     * Remove whitespace that does not separate two words, so that `status=4` and `status = 4`
     * describe the same condition, while `is not null` keeps its words apart.
     *
     * @param string $predicate
     *
     * @return string
     */
    protected static function removeInsignificantWhitespace(string $predicate): string
    {
        return static::mapUnquotedParts($predicate, static function (string $part): string {
            $part = (string)preg_replace('/\s+/', ' ', $part);
            $part = (string)preg_replace('/\s*([^\w\s\'"])\s*/', '$1', $part);

            return $part;
        });
    }

    /**
     * Remove the parentheses a database puts around a whole predicate.
     *
     * @param string $predicate
     *
     * @return string
     */
    protected static function removeEnclosingParentheses(string $predicate): string
    {
        $predicate = trim($predicate);

        while ($predicate !== '' && $predicate[0] === '(' && static::findClosingParenthesis($predicate) === strlen($predicate) - 1) {
            $predicate = trim(substr($predicate, 1, -1));
        }

        return $predicate;
    }

    /**
     * Find the position of the parenthesis closing the one at the beginning of the predicate.
     *
     * @param string $predicate
     *
     * @return int Position of the closing parenthesis, or -1 if it is not closed.
     */
    protected static function findClosingParenthesis(string $predicate): int
    {
        $depth = 0;
        $length = strlen($predicate);
        $quote = null;

        for ($position = 0; $position < $length; $position++) {
            $character = $predicate[$position];

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
                if ($depth === 0) {
                    return $position;
                }
            }
        }

        return -1;
    }

    /**
     * Apply a mapping to the parts of a predicate that are neither string literals nor quoted
     * identifiers, as those are used as they are written.
     *
     * @param string $predicate
     * @param callable(string):string $map
     *
     * @return string
     */
    protected static function mapUnquotedParts(string $predicate, callable $map): string
    {
        $result = (string)preg_replace_callback(
            '/(\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*")|([^\'"]+)/',
            static fn (array $match): string => isset($match[2]) && $match[2] !== '' ? $map($match[2]) : $match[0],
            $predicate,
        );

        return $result;
    }
}
