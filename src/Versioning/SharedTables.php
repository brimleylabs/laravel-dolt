<?php

namespace BrimleyLabs\Dolt\Versioning;

use BrimleyLabs\Dolt\Data\Commit;
use BrimleyLabs\Dolt\Data\IgnoreResult;
use BrimleyLabs\Dolt\Database\Dialects\Dialect;
use BrimleyLabs\Dolt\Database\DoltConnection;
use BrimleyLabs\Dolt\DoltManager;
use BrimleyLabs\Dolt\Events\Committed;
use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Repository;
use BrimleyLabs\Dolt\Support\Ref;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Keeps tables out of version control and shares them between every branch.
 *
 * @internal Reached through Repository::ignore(), ignored(), ignoredTables() and versionedMatching().
 */
class SharedTables
{
    public function __construct(
        protected Repository $repository,
        protected DoltConnection $connection,
        protected DoltManager $manager,
    ) {}

    protected function dialect(): Dialect
    {
        return $this->connection->dialect();
    }

    /**
     * The table patterns that are kept out of version control.
     *
     * @return Collection<int, string>
     */
    public function ignored(): Collection
    {
        try {
            $rows = $this->connection->select('select pattern from dolt_ignore where ignored = '.$this->dialect()->boolean(true).' order by pattern', [], false);
        } catch (QueryException $e) {
            throw DoltException::sharedTablesUnsupported($this->repository->version(), $e);
        }

        return collect($rows)->map(fn ($row) => (string) $row->pattern);
    }

    /**
     * @param  string|list<string>|null  $patterns
     */
    public function ignore(string|array|null $patterns, bool $untrack, bool $commit, mixed $author): IgnoreResult
    {
        $home = $this->repository->defaultBranch();

        // The shared copy of each table lives on the default branch, so the list is kept there.
        if ($this->repository->branch() !== $home) {
            return $this->repository->on($home, fn () => $this->repository->ignore($patterns, $untrack, $commit, $author));
        }

        $patterns = $this->ignorePatterns($patterns);

        if ($patterns === []) {
            return new IgnoreResult;
        }

        try {
            $listed = [];

            foreach ($this->connection->select('select pattern, ignored from dolt_ignore', [], false) as $row) {
                $listed[(string) $row->pattern] = (bool) $row->ignored;
            }

            $shared = array_map(
                fn ($row) => (string) $row->table_name,
                $this->connection->select('select table_name from dolt_nonlocal_tables', [], false),
            );

            $versioned = $this->versionedTables();
        } catch (QueryException $e) {
            throw DoltException::sharedTablesUnsupported($this->repository->version(), $e);
        }

        $wasPending = $this->connection->hasPendingChanges();
        $added = $released = $skipped = [];

        foreach ($patterns as $pattern) {
            $matches = array_values(array_filter($versioned, fn ($table) => Ref::matches($pattern, $table)));

            // A pattern the application marked as "not ignored" in dolt_ignore stays versioned.
            if (($listed[$pattern] ?? true) === false || ($matches !== [] && ! $untrack)) {
                $skipped[] = $pattern;

                continue;
            }

            $new = false;

            $text = $this->dialect()->text();

            if (! isset($listed[$pattern])) {
                $this->connection->affectingStatement("insert into dolt_ignore (pattern, ignored) values ({$text}, ".$this->dialect()->boolean(true).')', [$pattern]);

                $new = true;
            }

            if (! in_array($pattern, $shared, true)) {
                $this->connection->affectingStatement(
                    "insert into dolt_nonlocal_tables (table_name, target_ref, options) values ({$text}, {$text}, 'immediate')",
                    [$pattern, $home],
                );

                $new = true;
            }

            if ($new) {
                $added[] = $pattern;
            }

            foreach ($matches as $table) {
                $this->connection->call('dolt_rm', ['--cached', $table]);

                $released[] = $table;
            }
        }

        if ($added === [] && $released === []) {
            return new IgnoreResult(skipped: $skipped);
        }

        $this->numberWithoutCounter(array_values(array_diff($patterns, $skipped)));

        $recorded = null;

        if ($commit) {
            $recorded = $this->commitIgnoreList($added, $released, $author);

            if (! $wasPending) {
                $this->connection->forgetPendingChanges();
            }
        }

        return new IgnoreResult($added, $released, $skipped, $recorded);
    }

    /**
     * Take the tables the patterns match off their counters, where a counter cannot be shared.
     *
     * @param  list<string>  $patterns
     *
     * @see Dialect::sharedKeyDefault()
     */
    protected function numberWithoutCounter(array $patterns): void
    {
        $dialect = $this->dialect();
        $default = $dialect->sharedKeyDefault();

        if ($default === null) {
            return;
        }

        [$condition, $bindings] = $dialect->ownTables((string) $this->connection->getDatabaseName());

        $columns = $this->connection->select(
            "select table_name, column_name from information_schema.columns where {$condition} and column_default like 'nextval(%'",
            $bindings,
            false,
        );

        foreach ($columns as $column) {
            if (! collect($patterns)->contains(fn ($pattern) => Ref::matches($pattern, (string) $column->table_name))) {
                continue;
            }

            $this->connection->statement(sprintf(
                'alter table %s alter column %s set default %s',
                $dialect->quote((string) $column->table_name),
                $dialect->quote((string) $column->column_name),
                $default,
            ));
        }
    }

    /**
     * The tables that exist right now and are kept out of version control.
     *
     * @return list<string>
     */
    public function ignoredTables(): array
    {
        $dialect = $this->dialect();

        // Doltgres lists an ignored table as an ordinary new one, so there the patterns decide.
        $condition = $dialect->reportsIgnoredTables()
            ? 'staged = '.$dialect->boolean(false)
            : 'ignored = '.$dialect->boolean(true);

        try {
            $rows = $this->repository->reported($this->connection->select(
                "select table_name from dolt_status_ignored where {$condition} and status = 'new table' order by table_name", [], false,
            ));

            $patterns = $dialect->reportsIgnoredTables() ? $this->ignored()->all() : null;
        } catch (QueryException $e) {
            throw DoltException::sharedTablesUnsupported($this->repository->version(), $e);
        }

        $tables = array_map(fn ($row) => (string) $row->table_name, $rows);

        if ($patterns === null) {
            return $tables;
        }

        return array_values(array_filter(
            $tables,
            fn ($table) => collect($patterns)->contains(fn ($pattern) => Ref::matches($pattern, $table)),
        ));
    }

    /**
     * Tables that match the patterns but are still versioned.
     *
     * Run ignore() to release them. With no patterns, the "ignore" list in
     * config/dolt.php is used.
     *
     * @param  string|list<string>|null  $patterns
     * @return list<string>
     */
    public function versionedMatching(string|array|null $patterns = null): array
    {
        $patterns = $this->ignorePatterns($patterns);

        if ($patterns === []) {
            return [];
        }

        try {
            $versioned = $this->versionedTables();
        } catch (QueryException $e) {
            throw DoltException::sharedTablesUnsupported($this->repository->version(), $e);
        }

        return array_values(array_filter(
            $versioned,
            fn ($table) => collect($patterns)->contains(fn ($pattern) => Ref::matches($pattern, $table)),
        ));
    }

    /**
     * @param  string|list<string>|null  $patterns
     * @return list<string>
     */
    protected function ignorePatterns(string|array|null $patterns): array
    {
        if ($patterns === null) {
            $prefix = $this->connection->getTablePrefix();

            $patterns = array_map(fn ($pattern) => $prefix.$pattern, $this->manager->ignoreList());
        }

        return array_values(array_unique(array_map(Ref::pattern(...), (array) $patterns)));
    }

    /**
     * The tables on the current branch that are under version control.
     *
     * @return list<string>
     */
    protected function versionedTables(): array
    {
        $untracked = $this->repository->reported($this->connection->select(
            'select table_name as name from dolt_status_ignored where staged = '.$this->dialect()->boolean(false)." and status = 'new table'",
            [],
            false,
        ), 'name');

        return array_values(array_diff(
            $this->tableNames(),
            array_map(fn ($row) => (string) $row->name, $untracked),
        ));
    }

    /**
     * Every table of this database on the current branch.
     *
     * @return list<string>
     */
    public function tableNames(): array
    {
        [$condition, $bindings] = $this->dialect()->ownTables((string) $this->connection->getDatabaseName());

        $rows = $this->connection->select(
            "select table_name as name from information_schema.tables where {$condition} and table_type = 'BASE TABLE'",
            $bindings,
            false,
        );

        return array_map(fn ($row) => (string) $row->name, $rows);
    }

    /**
     * Commit the ignore list on its own, leaving every other pending change where it is.
     *
     * @param  list<string>  $added
     * @param  list<string>  $released
     */
    protected function commitIgnoreList(array $added, array $released, mixed $author): ?Commit
    {
        $author = $author !== null ? $this->manager->author($author) : $this->manager->systemAuthor();

        $lines = ['Keep tables out of version control', ''];

        if ($added !== []) {
            $lines[] = 'Not versioned from now on: '.implode(', ', $added);
        }

        if ($released !== []) {
            $lines[] = 'Released, rows kept: '.implode(', ', $released);
        }

        $this->connection->call('dolt_add', ['dolt_ignore', 'dolt_nonlocal_tables']);

        $rows = $this->connection->call('dolt_commit', ['-m', implode("\n", $lines), '--author', (string) $author, '--skip-empty']);

        $hash = $rows[0]->hash ?? null;

        if (! is_string($hash) || $hash === '') {
            return null;
        }

        $recorded = $this->repository->find($hash);

        if ($recorded !== null) {
            $this->manager->dispatch(new Committed((string) $this->connection->getName(), $recorded, $this->repository->branch()));
        }

        return $recorded;
    }
}
