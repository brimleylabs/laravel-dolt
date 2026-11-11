<?php

namespace BrimleyLabs\Dolt\Database\Dialects;

use DateTimeInterface;

/**
 * Doltgres, the Postgres flavour.
 */
class PostgresDialect extends Dialect
{
    public function __construct(protected string $schema = 'public') {}

    public function name(): string
    {
        return 'pgsql';
    }

    public function testedVersion(): string
    {
        return '1.5.0';
    }

    public function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * Doltgres offers the Dolt procedures as functions.
     */
    public function procedure(string $name, int $arguments): string
    {
        return 'select * from '.$name.'('.implode(', ', array_fill(0, $arguments, '?')).')';
    }

    /**
     * Text goes through set_config() in one statement. Doltgres refuses a number
     * there, so each number is set on its own.
     */
    public function setVariables(array $variables): array
    {
        $statements = $calls = $bindings = [];

        foreach ($variables as $name => $value) {
            if (is_bool($value) || is_int($value)) {
                $statements[] = ["set {$name} = ".(int) $value, []];

                continue;
            }

            $calls[] = "set_config('{$name}', ?, false)";
            $bindings[] = (string) $value;
        }

        if ($calls !== []) {
            $statements[] = ['select '.implode(', ', $calls), $bindings];
        }

        return $statements;
    }

    public function boolean(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Doltgres cannot work out the type of a bare placeholder in some of Dolt's own tables.
     */
    public function text(): string
    {
        return 'cast(? as text)';
    }

    /**
     * Outside a transaction Doltgres rolls a conflicted merge back before anyone can look at it.
     */
    public function beginMerge(): string
    {
        return 'begin';
    }

    public function endMerge(): string
    {
        return 'commit';
    }

    /**
     * Doltgres reports tables with their schema, as in "public.orders".
     */
    public function table(string $reported): string
    {
        $dot = strrpos($reported, '.');

        return $dot === false ? $reported : substr($reported, $dot + 1);
    }

    public function ownTables(string $database): array
    {
        return ['table_schema = current_schema()', []];
    }

    public function primaryKey(string $database, string $table): array
    {
        return [
            "select k.column_name as name
             from information_schema.table_constraints c
             join information_schema.key_column_usage k
               on k.constraint_name = c.constraint_name and k.table_schema = c.table_schema and k.table_name = c.table_name
             where c.constraint_type = 'PRIMARY KEY' and c.table_schema = current_schema() and c.table_name = ?
             order by k.ordinal_position",
            [$table],
        ];
    }

    public function revisionTable(string $database, string $revision, string $table): string
    {
        return "{$database}/{$revision}.{$this->schema}.{$table}";
    }

    public function epochColumns(array $columns, string $alias = '', string $prefix = ''): string
    {
        $from = $alias === '' ? '' : $alias.'.';

        return implode('', array_map(
            fn ($column) => ", extract(epoch from {$from}{$column}) as {$prefix}{$column}_epoch",
            $columns,
        ));
    }

    public function notAfter(string $column, DateTimeInterface $moment): array
    {
        return ["extract(epoch from {$column}) <= ?", $moment->format('U.u')];
    }

    public function sequences(): ?string
    {
        return "select relname as name from pg_class where relkind = 'S'";
    }

    /**
     * The current time in microseconds followed by three random digits: in insert order, and 19 digits long.
     *
     * Doltgres has no counter that every branch shares. A sequence that is not
     * versioned exists on one branch only, and a versioned one would leave a
     * change behind on every insert. A clock with fewer digits, small enough for
     * a JavaScript number, would leave too few random digits to keep rows apart.
     * Two rows written in the same microsecond clash one time in a thousand, and
     * DoltgresConnection then tries the insert again.
     */
    public function sharedKeyDefault(): ?string
    {
        return '((extract(epoch from clock_timestamp()) * 1000000)::bigint * 1000 + floor(random() * 1000)::bigint)';
    }

    public function reportsIgnoredTables(): bool
    {
        return true;
    }

    /**
     * dolt_clean on Doltgres also removes sequences that are committed. A hard reset afterwards restores them.
     */
    public function cleansBeforeReset(): bool
    {
        return true;
    }
}
