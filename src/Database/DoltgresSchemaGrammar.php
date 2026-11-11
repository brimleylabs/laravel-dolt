<?php

namespace BrimleyLabs\Dolt\Database;

use BrimleyLabs\Dolt\Database\Concerns\RespectsSharedTables;
use BrimleyLabs\Dolt\Database\Dialects\Dialect;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;

/**
 * The Postgres schema grammar, with the statements Doltgres does not take yet written another way.
 */
class DoltgresSchemaGrammar extends PostgresGrammar
{
    use RespectsSharedTables;

    /**
     * The default that stands in for a counter while a shared table is being created.
     */
    protected ?string $keyDefault = null;

    /**
     * A table shared between branches gets its row numbers from the clock instead of a counter.
     *
     * @see Dialect::sharedKeyDefault()
     *
     * @param  Fluent<string, mixed>  $command
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command)
    {
        $this->keyDefault = ! $blueprint->temporary && $this->isSharedTable($blueprint->getTable())
            ? $this->sharedKeyDefault()
            : null;

        try {
            return parent::compileCreate($blueprint, $command);
        } finally {
            $this->keyDefault = null;
        }
    }

    /**
     * The clock based default from the connection's dialect. Only a Dolt
     * connection has shared tables, so any other connection has none.
     */
    protected function sharedKeyDefault(): ?string
    {
        return $this->connection instanceof DoltConnection
            ? $this->connection->dialect()->sharedKeyDefault()
            : null;
    }

    /**
     * @param  Fluent<string, mixed>  $column
     */
    protected function typeInteger(Fluent $column)
    {
        return $this->countsWithoutCounter($column) ? 'bigint' : parent::typeInteger($column);
    }

    /**
     * @param  Fluent<string, mixed>  $column
     */
    protected function typeBigInteger(Fluent $column)
    {
        return $this->countsWithoutCounter($column) ? 'bigint' : parent::typeBigInteger($column);
    }

    /**
     * @param  Fluent<string, mixed>  $column
     */
    protected function typeSmallInteger(Fluent $column)
    {
        return $this->countsWithoutCounter($column) ? 'bigint' : parent::typeSmallInteger($column);
    }

    /**
     * @param  Fluent<string, mixed>  $column
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column)
    {
        if ($this->countsWithoutCounter($column)) {
            return ' default '.$this->keyDefault;
        }

        return parent::modifyDefault($blueprint, $column);
    }

    /**
     * @param  Fluent<string, mixed>  $column
     */
    protected function countsWithoutCounter(Fluent $column): bool
    {
        return $this->keyDefault !== null && $column->get('autoIncrement') && $column->get('generatedAs') === null && ! $column->get('change');
    }

    /**
     * Doltgres cannot run the catalogue query Laravel uses here. Its
     * information schema holds the same facts.
     */
    public function compileForeignKeys($schema, $table)
    {
        $rule = fn (string $column) => "max(case {$column} when 'CASCADE' then 'c' when 'SET NULL' then 'n' "
            ."when 'SET DEFAULT' then 'd' when 'RESTRICT' then 'r' else 'a' end)";

        return sprintf(
            'select k.constraint_name as name, '
            ."string_agg(k.column_name, ',' order by k.ordinal_position) as columns, "
            .'max(k.table_schema) as foreign_schema, max(k.referenced_table_name) as foreign_table, '
            ."string_agg(k.referenced_column_name, ',' order by k.ordinal_position) as foreign_columns, "
            .$rule('r.update_rule').' as on_update, '.$rule('r.delete_rule').' as on_delete '
            .'from information_schema.key_column_usage k '
            .'join information_schema.referential_constraints r '
            .'on r.constraint_name = k.constraint_name and r.constraint_schema = k.constraint_schema '
            .'where k.referenced_table_name is not null and k.table_name = %s and k.table_schema = %s '
            .'group by k.constraint_name',
            $this->quoteString($table),
            $schema ? $this->quoteString($schema) : 'current_schema()'
        );
    }

    /**
     * Changing a column also asks Postgres to drop its identity "if exists".
     * Doltgres does not know that clause, and such a column has none to drop.
     *
     * @param  Fluent<string, mixed>  $command
     */
    public function compileChange(Blueprint $blueprint, Fluent $command)
    {
        $sql = parent::compileChange($blueprint, $command);

        return preg_replace('/,\s*alter column "(?:[^"]|"")+" drop identity if exists/', '', $sql);
    }
}
