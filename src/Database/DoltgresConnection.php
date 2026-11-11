<?php

namespace BrimleyLabs\Dolt\Database;

use BrimleyLabs\Dolt\Database\Concerns\ManagesDoltSession;
use BrimleyLabs\Dolt\Database\Dialects\Dialect;
use BrimleyLabs\Dolt\Database\Dialects\PostgresDialect;
use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use PDOException;
use Throwable;

/**
 * A Postgres connection that knows it is talking to Doltgres.
 */
class DoltgresConnection extends PostgresConnection implements DoltConnection
{
    use ManagesDoltSession;

    protected ?Dialect $dialect = null;

    /**
     * What the server was found to accept, by feature.
     *
     * @var array<string, bool>
     */
    protected array $features = [];

    /**
     * The statements Doltgres 1.5 refused, used to ask a server whether it takes them now.
     */
    protected const PROBES = [
        'row_locks' => 'select 1 as probe for update',
        'ilike' => "select 'a' ilike 'A' as probe",
    ];

    public function dialect(): Dialect
    {
        return $this->dialect ??= new PostgresDialect($this->schemaName());
    }

    /**
     * Packages and migrations ask for the driver name to choose SQL syntax. Doltgres wants Postgres syntax.
     */
    public function getDriverName()
    {
        return 'pgsql';
    }

    public function getDriverTitle()
    {
        return 'Doltgres';
    }

    protected function getDefaultQueryGrammar()
    {
        return new DoltgresQueryGrammar($this);
    }

    protected function getDefaultSchemaGrammar()
    {
        return new DoltgresSchemaGrammar($this);
    }

    /**
     * Whether the server accepts something Doltgres 1.5 did not: "row_locks" or "ilike".
     *
     * The package works around both. It asks the server once per connection,
     * the first time it matters, so a Doltgres that has caught up gets the real
     * statement. Inside a transaction the question is asked under a savepoint,
     * because a refused statement would otherwise end the transaction.
     */
    public function supports(string $feature): bool
    {
        if (! isset(self::PROBES[$feature])) {
            throw new InvalidArgumentException("[{$feature}] is not a feature this connection can check.");
        }

        return $this->features[$feature] ??= $this->probe(self::PROBES[$feature]);
    }

    protected function probe(string $sql): bool
    {
        $pdo = $this->getPdo();
        $guarded = $pdo->inTransaction();

        try {
            if ($guarded) {
                $pdo->exec('savepoint dolt_probe');
            }

            $pdo->query($sql)->fetchAll();

            if ($guarded) {
                $pdo->exec('release savepoint dolt_probe');
            }

            return true;
        } catch (PDOException) {
            if ($guarded) {
                try {
                    $pdo->exec('rollback to savepoint dolt_probe');
                } catch (Throwable) {
                    // The transaction was over already.
                }
            }

            return false;
        }
    }

    /**
     * Rows of a shared table are numbered from the clock, and two rows can draw the
     * same number. Outside a transaction such an insert is tried once more, which
     * draws a new number. Inside one, Doltgres has already ended the transaction.
     *
     * @see Dialect::sharedKeyDefault()
     *
     * @param  array<mixed>  $bindings
     */
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        try {
            return parent::runQueryCallback($query, $bindings, $callback);
        } catch (QueryException $e) {
            if (! $this->drewATakenNumber((string) $query, $e)) {
                throw $e;
            }

            return parent::runQueryCallback($query, $bindings, $callback);
        }
    }

    protected function drewATakenNumber(string $query, QueryException $e): bool
    {
        if ((string) $e->getCode() !== '23505' || $this->transactionLevel() > 0) {
            return false;
        }

        if (! preg_match('/^\s*insert\s+into\s+"?([A-Za-z0-9_]+)"?\s/i', $query, $match)) {
            return false;
        }

        try {
            return $this->fetch(
                $this->getPdo(),
                'select 1 from information_schema.columns where table_schema = ? and table_name = ? and column_default like ? limit 1',
                [$this->schemaName(), $match[1], '%clock_timestamp%'],
            ) !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The schema the application's tables live in: the first entry of the search path.
     */
    protected function schemaName(): string
    {
        $path = $this->config['search_path'] ?? $this->config['schema'] ?? 'public';
        $first = is_array($path) ? reset($path) : explode(',', (string) $path)[0];
        $first = trim((string) $first, " \t\"'");

        return $first === '' || $first === '$user' ? 'public' : $first;
    }
}
