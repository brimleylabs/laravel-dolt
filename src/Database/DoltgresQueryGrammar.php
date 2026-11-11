<?php

namespace BrimleyLabs\Dolt\Database;

use BrimleyLabs\Dolt\Support\Adjustments;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * The Postgres query grammar, with the statements Doltgres does not take yet written another way.
 */
class DoltgresQueryGrammar extends PostgresGrammar
{
    /**
     * Doltgres 1.5 has no ILIKE. Lowering both sides gives the same matches.
     *
     * @param  array<string, mixed>  $where
     */
    protected function whereBasic(Builder $query, $where)
    {
        $operator = strtolower((string) $where['operator']);

        if (($operator === 'ilike' || $operator === 'not ilike') && ! $this->serverSupports('ilike')) {
            return sprintf(
                'lower(%s::text) %s lower(%s)',
                $this->wrap($where['column']),
                $operator === 'ilike' ? 'like' : 'not like',
                $this->parameter($where['value'])
            );
        }

        return parent::whereBasic($query, $where);
    }

    /**
     * Doltgres 1.5 does not lock rows, and refuses a query that asks for it.
     *
     * The clause is left off so the query runs. Two sessions that then write
     * the same row are caught when the second one commits its transaction.
     *
     * @see Adjustments
     */
    protected function compileLock(Builder $query, $value)
    {
        // Laravel asks only when a lock is set: true for lockForUpdate(), false for sharedLock().
        if ($this->serverSupports('row_locks')) {
            return parent::compileLock($query, $value);
        }

        Adjustments::make(
            (string) $this->connection->getName(),
            'row_lock',
            "A query on {$query->from} asks for a row lock, and Doltgres does not lock rows yet.",
            'It ran without the lock. When two sessions change the same row, the second fails when it commits.',
        );

        return '';
    }

    /**
     * Doltgres takes a plain TRUNCATE only, so the counters are put back with a second statement.
     *
     * @return array<string, array<mixed>>
     */
    public function compileTruncate(Builder $query)
    {
        $table = $this->wrapTable($query->from);
        $name = $this->quoteString($this->getTablePrefix().$query->from);

        return [
            'truncate '.$table => [],
            "select setval(pg_get_serial_sequence({$name}, column_name), 1, false) from information_schema.columns "
                ."where table_schema = current_schema() and table_name = {$name} and column_default like 'nextval(%'" => [],
        ];
    }

    /**
     * Whether the server takes a statement Doltgres 1.5 refused. Only a Doltgres
     * connection probes for that. Any other Postgres server takes both.
     */
    protected function serverSupports(string $feature): bool
    {
        return ! $this->connection instanceof DoltgresConnection || $this->connection->supports($feature);
    }
}
