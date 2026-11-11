<?php

namespace BrimleyLabs\Dolt\Support;

use BrimleyLabs\Dolt\Exceptions\InvalidRefException;

/**
 * Validation and quoting for values Dolt will not accept as bound parameters.
 *
 * Several Dolt table functions (dolt_diff, AS OF) reject placeholders, so refs
 * and table names are written into the SQL as literals. Everything that goes
 * through here is checked against a strict character list first.
 */
final class Ref
{
    public const HEAD = 'HEAD';

    public const WORKING = 'WORKING';

    public const STAGED = 'STAGED';

    /**
     * Check a branch, tag, commit hash or ancestry expression such as main~2.
     */
    public static function validate(string $ref): string
    {
        if ($ref === '' || strlen($ref) > 255 || ! preg_match('/^[A-Za-z0-9_][A-Za-z0-9_\-\.\/~^]*$/D', $ref)) {
            throw InvalidRefException::forRef($ref);
        }

        return $ref;
    }

    /**
     * A ref written as a quoted SQL string literal.
     */
    public static function literal(string $ref): string
    {
        return "'".self::validate($ref)."'";
    }

    /**
     * Check a table name. Laravel table names never need more than this.
     */
    public static function table(string $table): string
    {
        if ($table === '' || strlen($table) > 64 || ! preg_match('/^[A-Za-z0-9_$]+$/D', $table)) {
            throw InvalidRefException::forTable($table);
        }

        return $table;
    }

    /**
     * A table name written as a quoted SQL string literal.
     */
    public static function tableLiteral(string $table): string
    {
        return "'".self::table($table)."'";
    }

    /**
     * Check a table pattern for dolt_ignore. "%" and "*" match any run of characters, "?" matches one.
     */
    public static function pattern(string $pattern): string
    {
        if ($pattern === '' || strlen($pattern) > 64 || ! preg_match('/^[A-Za-z0-9_$%*?]+$/D', $pattern)) {
            throw InvalidRefException::forTable($pattern);
        }

        return $pattern;
    }

    /**
     * Whether a table name matches a dolt_ignore pattern.
     */
    public static function matches(string $pattern, string $table): bool
    {
        $expression = strtr(preg_quote($pattern, '/'), ['%' => '.*', '\*' => '.*', '\?' => '.']);

        return (bool) preg_match('/^'.$expression.'$/D', $table);
    }

    /**
     * Dolt commit hashes are 32 characters of base32.
     */
    public static function isHash(string $ref): bool
    {
        return (bool) preg_match('/^[0-9a-v]{32}$/D', $ref);
    }

    /**
     * A backtick quoted identifier.
     */
    public static function identifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }
}
