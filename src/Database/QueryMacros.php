<?php

namespace BrimleyLabs\Dolt\Database;

use BrimleyLabs\Dolt\Data\Branch;
use BrimleyLabs\Dolt\Data\Commit;
use BrimleyLabs\Dolt\Data\Tag;
use BrimleyLabs\Dolt\DoltManager;
use BrimleyLabs\Dolt\Exceptions\DoltException;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * Query builder additions for reading the past.
 */
class QueryMacros
{
    public static function register(): void
    {
        /*
         * Read this query's table as it was at a commit, tag, branch or moment in time.
         *
         *   DB::table('orders')->asOf('v1.4')->where('status', 'open')->get();
         *   Order::asOf(now()->subWeek())->find(42);
         *
         * Only the table the query starts from is moved. Joined tables and eager
         * loaded relationships read current data. To move everything at once,
         * wrap the code in Dolt::asOf().
         */
        Builder::macro('asOf', function (string|DateTimeInterface|Commit|Branch|Tag $ref) {
            /** @var Builder $this */
            $connection = $this->getConnection();

            if (! $connection instanceof DoltConnection) {
                // Every Laravel connection extends Connection. Only a custom one would lack a name.
                throw DoltException::notDolt($connection instanceof Connection ? (string) $connection->getName() : $connection::class);
            }

            if (! is_string($this->from)) {
                throw new DoltException('asOf() needs a query that starts from a plain table name.');
            }

            $hash = app(DoltManager::class)->connection($connection->getName())->resolve($ref);

            [$table, $alias] = array_pad(preg_split('/\s+as\s+/i', trim($this->from), 2) ?: [], 2, null);

            // Drop a revision that an earlier asOf() call put on this query.
            $table = substr((string) $table, (int) strrpos('.'.$table, '.'));

            $this->from = $connection->dialect()->revisionTable((string) $connection->getDatabaseName(), $hash, $table).' as '.($alias ?? $table);

            return $this;
        });
    }
}
