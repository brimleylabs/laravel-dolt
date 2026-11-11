<?php

namespace BrimleyLabs\Dolt\Versioning;

use BrimleyLabs\Dolt\Data\Author;
use BrimleyLabs\Dolt\Data\Conflict;
use BrimleyLabs\Dolt\Data\ConflictSummary;
use BrimleyLabs\Dolt\Data\MergeResult;
use BrimleyLabs\Dolt\Database\Dialects\Dialect;
use BrimleyLabs\Dolt\Database\DoltConnection;
use BrimleyLabs\Dolt\DoltManager;
use BrimleyLabs\Dolt\Events\Merged;
use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Exceptions\MergeConflictException;
use BrimleyLabs\Dolt\Repository;
use BrimleyLabs\Dolt\Support\Ref;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Merges a branch into the current one and settles the conflicts it hits.
 *
 * @internal Reached through Repository::merge(), previewMerge() and previewConflicts().
 */
class Merger
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
     * @param  string|array<string, string|Closure>|Closure  $onConflict
     */
    public function merge(
        string $source,
        ?string $message,
        mixed $author,
        string|array|Closure $onConflict,
        bool $squash,
        bool $fastForward,
    ): MergeResult {
        $target = (string) $this->repository->branch();
        $author = $this->manager->author($author);
        $message = trim((string) $message) !== '' ? trim((string) $message) : "Merge {$source} into {$target}";

        $arguments = [$source];

        if ($squash) {
            $arguments[] = '--squash';
        } elseif (! $fastForward) {
            $arguments[] = '--no-ff';
        }

        array_push($arguments, '-m', $message, '--author', (string) $author);

        // Left alone, Dolt rolls a conflicted merge back before anyone can look at
        // it. Holding the transaction open keeps the conflicts there to be settled.
        $this->connection->control($this->dialect()->beginMerge());

        try {
            try {
                $row = $this->connection->call('dolt_merge', $arguments)[0] ?? null;
            } catch (QueryException $e) {
                $this->connection->control('rollback');

                if (str_contains($e->getMessage(), 'stomped')) {
                    if ($this->repository->isDirty() === false) {
                        // Nothing is pending, so the clash is a table this branch stopped versioning.
                        throw new DoltException(
                            "[{$source}] still versions a table that [{$target}] has released from version control. ".
                            "Merge [{$target}] into [{$source}] first (sync the workspace), then merge again.",
                            previous: $e,
                        );
                    }

                    throw new DoltException(
                        "Cannot merge into [{$target}] while it has uncommitted changes. Commit or reset them first.",
                        previous: $e,
                    );
                }

                throw $e;
            }

            $hash = (string) ($row->hash ?? '');
            $resolved = 0;

            if ((int) ($row->conflicts ?? 0) > 0) {
                $hash = $this->settleConflicts($source, $target, $onConflict, $message, $author, $resolved);
            } else {
                $this->connection->control('commit');

                // A squash leaves the incoming changes staged instead of committing them.
                if ($squash) {
                    $rows = $this->connection->call('dolt_commit', ['-A', '-m', $message, '--author', (string) $author, '--skip-empty']);
                    $hash = (string) ($rows[0]->hash ?? '');
                }
            }
        } finally {
            try {
                $this->connection->control($this->dialect()->endMerge());
            } catch (Throwable) {
                // The session is gone. A new one starts with autocommit on.
            }
        }

        $this->connection->forgetPendingChanges();

        $result = new MergeResult(
            source: $source,
            target: $target,
            hash: $hash !== '' ? $hash : null,
            fastForward: (bool) ($row->fast_forward ?? false),
            conflictsResolved: $resolved,
            message: (string) ($row->message ?? ''),
        );

        if (! $result->upToDate()) {
            $this->manager->dispatch(new Merged((string) $this->connection->getName(), $result));
        }

        return $result;
    }

    /**
     * The conflicts a merge would hit, without merging anything.
     *
     * @return Collection<int, ConflictSummary>
     */
    public function previewMerge(string $source, ?string $target = null): Collection
    {
        $target ??= (string) $this->repository->branch();

        $rows = $this->connection->select(
            'select * from dolt_preview_merge_conflicts_summary('.Ref::literal($target).', '.Ref::literal($source).')',
            [],
            false,
        );

        return collect($rows)->map(fn ($row) => new ConflictSummary(
            $this->dialect()->table((string) $row->table),
            (int) ($row->num_data_conflicts ?? 0),
            (int) ($row->num_schema_conflicts ?? 0),
        ));
    }

    /**
     * The conflicting rows a merge would hit in one table, without merging anything.
     *
     * @return Collection<int, Conflict>
     */
    public function previewConflicts(string $source, string $table, ?string $target = null): Collection
    {
        $target ??= (string) $this->repository->branch();

        $rows = $this->connection->select(
            'select * from dolt_preview_merge_conflicts('.Ref::literal($target).', '.Ref::literal($source).', '.Ref::tableLiteral($table).')',
            [],
            false,
        );

        return collect($rows)->map(fn ($row) => Conflict::fromRow($table, $row));
    }

    /**
     * Settle an open, conflicted merge and commit it. Returns the merge commit hash.
     *
     * @param  string|array<string, string|Closure>|Closure  $strategy
     */
    protected function settleConflicts(string $source, string $target, string|array|Closure $strategy, string $message, Author $author, int &$resolved): string
    {
        $summaries = collect($this->connection->select('select * from dolt_conflicts', [], false))
            ->map(fn ($row) => new ConflictSummary($this->dialect()->table((string) $row->table), (int) $row->num_conflicts));

        $plan = [];

        foreach ($summaries as $summary) {
            $choice = match (true) {
                $strategy instanceof Closure => $strategy,
                is_array($strategy) => $strategy[$summary->table] ?? $strategy['*'] ?? Repository::ABORT,
                default => $strategy,
            };

            if (! $choice instanceof Closure && ! in_array($choice, [Repository::OURS, Repository::THEIRS], true)) {
                $this->abortMerge();

                throw MergeConflictException::between($source, $target, $summaries);
            }

            $plan[$summary->table] = $choice;
        }

        try {
            foreach ($plan as $table => $choice) {
                if ($choice instanceof Closure) {
                    if (! $this->settleRows($table, $choice)) {
                        $this->abortMerge();

                        throw MergeConflictException::between($source, $target, $summaries);
                    }
                } else {
                    $this->connection->call('dolt_conflicts_resolve', ['--'.$choice, $table]);
                }

                $resolved++;
            }

            $rows = $this->connection->call('dolt_commit', ['-A', '-m', $message, '--author', (string) $author]);
        } catch (MergeConflictException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->abortMerge();

            throw $e;
        }

        return (string) ($rows[0]->hash ?? '');
    }

    /**
     * Settle the conflicts of one table row by row. False means the callback asked to abort.
     */
    protected function settleRows(string $table, Closure $callback): bool
    {
        $primaryKey = $this->repository->primaryKey($table);

        if ($primaryKey === []) {
            return false;
        }

        $conflictTable = $this->dialect()->quote('dolt_conflicts_'.Ref::table($table));

        foreach ($this->connection->select("select * from {$conflictTable}", [], false) as $row) {
            $conflict = Conflict::fromRow($table, $row);
            $decision = $callback($conflict);

            if ($decision === Repository::THEIRS) {
                $this->writeRow($table, $primaryKey, $conflict->ours, $conflict->theirs);
            } elseif (is_array($decision)) {
                $this->writeRow($table, $primaryKey, $conflict->ours, array_merge($conflict->ours ?? $conflict->theirs ?? [], $decision));
            } elseif ($decision !== Repository::OURS) {
                return false;
            }

            $this->connection->affectingStatement("delete from {$conflictTable} where dolt_conflict_id = ?", [$conflict->id]);
        }

        return true;
    }

    /**
     * Make a row match the wanted values: insert, update or delete as needed.
     *
     * @param  list<string>  $primaryKey
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>|null  $wanted
     */
    protected function writeRow(string $table, array $primaryKey, ?array $current, ?array $wanted): void
    {
        $quote = $this->dialect()->quote(...);
        $quotedTable = $quote(Ref::table($table));

        $where = fn (array $row) => [
            implode(' and ', array_map(fn ($column) => $quote($column).' = ?', $primaryKey)),
            array_map(fn ($column) => $row[$column] ?? null, $primaryKey),
        ];

        if ($wanted === null) {
            if ($current !== null) {
                [$sql, $bindings] = $where($current);
                $this->connection->affectingStatement("delete from {$quotedTable} where {$sql}", $bindings);
            }

            return;
        }

        if ($current === null) {
            $columns = implode(', ', array_map($quote, array_keys($wanted)));
            $marks = implode(', ', array_fill(0, count($wanted), '?'));

            $this->connection->affectingStatement("insert into {$quotedTable} ({$columns}) values ({$marks})", array_values($wanted));

            return;
        }

        [$sql, $bindings] = $where($current);
        $assignments = implode(', ', array_map(fn ($column) => $quote($column).' = ?', array_keys($wanted)));

        $this->connection->affectingStatement(
            "update {$quotedTable} set {$assignments} where {$sql}",
            [...array_values($wanted), ...$bindings],
        );
    }

    protected function abortMerge(): void
    {
        try {
            $this->connection->call('dolt_merge', ['--abort']);
        } catch (Throwable) {
            // Nothing to abort.
        }

        $this->connection->control('rollback');
    }
}
