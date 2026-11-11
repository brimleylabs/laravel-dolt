<?php

namespace BrimleyLabs\Dolt\Database\Dialects;

use BrimleyLabs\Dolt\Support\Ref;
use DateTimeInterface;
use DateTimeZone;

/**
 * Dolt, the MySQL flavour.
 */
class MySqlDialect extends Dialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function testedVersion(): string
    {
        return '2.7.2';
    }

    public function quote(string $identifier): string
    {
        return Ref::identifier($identifier);
    }

    public function procedure(string $name, int $arguments): string
    {
        return 'call '.$name.'('.implode(', ', array_fill(0, $arguments, '?')).')';
    }

    /**
     * One statement sets them all.
     */
    public function setVariables(array $variables): array
    {
        $assignments = $bindings = [];

        foreach ($variables as $name => $value) {
            if (is_bool($value) || is_int($value)) {
                $assignments[] = "@@session.{$name} = ".(int) $value;

                continue;
            }

            $assignments[] = "@@session.{$name} = ?";
            $bindings[] = (string) $value;
        }

        return $assignments === [] ? [] : [['set '.implode(', ', $assignments), $bindings]];
    }

    public function boolean(bool $value): string
    {
        return $value ? '1' : '0';
    }

    public function beginMerge(): string
    {
        return 'set autocommit = 0';
    }

    public function endMerge(): string
    {
        return 'set autocommit = 1';
    }

    public function ownTables(string $database): array
    {
        return ['table_schema = ?', [$database]];
    }

    public function primaryKey(string $database, string $table): array
    {
        return [
            "select column_name as name from information_schema.key_column_usage
             where table_schema = ? and table_name = ? and constraint_name = 'PRIMARY'
             order by ordinal_position",
            [$database, $table],
        ];
    }

    public function revisionTable(string $database, string $revision, string $table): string
    {
        return "{$database}/{$revision}.{$table}";
    }

    public function notAfter(string $column, DateTimeInterface $moment): array
    {
        $utc = \DateTimeImmutable::createFromInterface($moment)->setTimezone(new DateTimeZone('UTC'));

        return ["{$column} <= ?", $utc->format('Y-m-d H:i:s.u')];
    }
}
