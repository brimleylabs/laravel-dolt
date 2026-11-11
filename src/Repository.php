<?php

namespace BrimleyLabs\Dolt;

use BrimleyLabs\Dolt\Data\Activity;
use BrimleyLabs\Dolt\Data\Branch;
use BrimleyLabs\Dolt\Data\ChangeSet;
use BrimleyLabs\Dolt\Data\Commit;
use BrimleyLabs\Dolt\Data\Conflict;
use BrimleyLabs\Dolt\Data\ConflictSummary;
use BrimleyLabs\Dolt\Data\IgnoreResult;
use BrimleyLabs\Dolt\Data\MergeResult;
use BrimleyLabs\Dolt\Data\StatusEntry;
use BrimleyLabs\Dolt\Data\Tag;
use BrimleyLabs\Dolt\Database\Dialects\Dialect;
use BrimleyLabs\Dolt\Database\DoltConnection;
use BrimleyLabs\Dolt\Events\Committed;
use BrimleyLabs\Dolt\Exceptions\BranchNotFoundException;
use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Exceptions\InvalidRefException;
use BrimleyLabs\Dolt\Exceptions\MergeConflictException;
use BrimleyLabs\Dolt\Support\Ref;
use BrimleyLabs\Dolt\Versioning\Merger;
use BrimleyLabs\Dolt\Versioning\SharedTables;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Version control for one Dolt database connection.
 */
class Repository
{
    public const OURS = 'ours';

    public const THEIRS = 'theirs';

    public const ABORT = 'abort';

    /**
     * A date, with or without a time and zone, as a person would write it.
     */
    protected const MOMENT = '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D';

    /** @var array<string, list<string>> */
    protected array $primaryKeys = [];

    protected ?string $version = null;

    protected ?Merger $merger = null;

    protected ?SharedTables $sharedTables = null;

    public function __construct(
        protected DoltConnection $connection,
        protected DoltManager $manager,
    ) {}

    public function connection(): DoltConnection
    {
        return $this->connection;
    }

    /**
     * The SQL spellings of the server behind this repository.
     */
    public function dialect(): Dialect
    {
        return $this->connection->dialect();
    }

    /**
     * Extra select columns that carry dates as exact moments, where the server needs them.
     *
     * @param  list<string>  $columns
     */
    protected function epoch(array $columns, string $alias): string
    {
        return $this->dialect()->epochColumns($columns, $alias);
    }

    /**
     * Rows Dolt reported about tables, with each name as the application knows it.
     *
     * Doltgres puts the schema in front of every name and lists sequences next
     * to tables. Its status tables also list tables that dolt_ignore keeps out
     * of version control. This brings its answers in line with Dolt's.
     *
     * @param  list<object>  $rows
     * @return list<object>
     */
    public function reported(array $rows, string $column = 'table_name', bool $withoutIgnored = false): array
    {
        $dialect = $this->dialect();

        if ($rows === [] || $dialect->sequences() === null) {
            return $rows;
        }

        $sequences = array_map(fn ($row) => (string) $row->name, $this->connection->select($dialect->sequences(), [], false));
        $patterns = $withoutIgnored && $dialect->reportsIgnoredTables() ? $this->ignored()->all() : [];
        $tables = null;
        $kept = [];

        foreach ($rows as $row) {
            $name = $dialect->table((string) $row->{$column});

            if (in_array($name, $sequences, true)) {
                continue;
            }

            // A sequence that was dropped is no longer in the catalogue. Its name still gives it away.
            if (str_ends_with($name, '_seq') && ! in_array($name, $tables ??= $this->sharedTables()->tableNames(), true)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (Ref::matches($pattern, $name)) {
                    continue 2;
                }
            }

            $row = clone $row;
            $row->{$column} = $name;
            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * The Dolt server version, such as "1.43.0".
     */
    public function version(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        try {
            $rows = $this->connection->select('select dolt_version() as version', [], false);
        } catch (QueryException $e) {
            throw DoltException::notADoltServer((string) $this->connection->getName());
        }

        return $this->version = (string) $rows[0]->version;
    }

    /*
    |--------------------------------------------------------------------------
    | Branches
    |--------------------------------------------------------------------------
    */

    /**
     * The branch the connection returns to after a temporary switch.
     */
    public function defaultBranch(): string
    {
        return $this->connection->homeBranch();
    }

    /**
     * The branch queries are running on. Null while reading a past revision.
     */
    public function branch(): ?string
    {
        return $this->connection->currentBranch();
    }

    /**
     * @return Collection<int, Branch>
     */
    public function branches(): Collection
    {
        $rows = $this->connection->select("select b.*{$this->epoch(['latest_commit_date'], 'b')} from dolt_branches b order by b.name", [], false);

        return collect($rows)->map(fn ($row) => Branch::fromRow($row, $this->manager->timezone()));
    }

    public function findBranch(string $name): ?Branch
    {
        $rows = $this->connection->select("select b.*{$this->epoch(['latest_commit_date'], 'b')} from dolt_branches b where b.name = ? limit 1", [$name], false);

        return $rows ? Branch::fromRow($rows[0], $this->manager->timezone()) : null;
    }

    public function branchExists(string $name): bool
    {
        return $this->connection->select('select 1 from dolt_branches where name = ? limit 1', [$name], false) !== [];
    }

    /**
     * Create a branch. It starts at the current head unless a starting point is given.
     */
    public function createBranch(string $name, string|DateTimeInterface|Commit|null $from = null): Branch
    {
        Ref::validate($name);

        $arguments = [$name];

        if ($from !== null) {
            $arguments[] = $from instanceof DateTimeInterface || $from instanceof Commit
                ? $this->resolve($from)
                : $this->normalize($from);
        }

        $this->connection->call('dolt_branch', $arguments);

        return $this->findBranch($name) ?? throw BranchNotFoundException::named($name);
    }

    /**
     * Delete a branch. Force is needed when it holds unmerged work or another session has it checked out.
     */
    public function deleteBranch(string $name, bool $force = false): void
    {
        Ref::validate($name);

        if ($name === $this->defaultBranch()) {
            throw new DoltException("Refusing to delete [{$name}], the default branch of this connection.");
        }

        if ($name === $this->branch()) {
            throw new DoltException("Cannot delete [{$name}] while the connection is on it. Check out another branch first.");
        }

        try {
            $this->connection->call('dolt_branch', [$force ? '-D' : '-d', $name]);
        } catch (QueryException $e) {
            if (! $force && str_contains($e->getMessage(), 'not fully merged')) {
                throw new DoltException("[{$name}] holds work that is not merged. Delete it with force to throw that work away.", previous: $e);
            }

            throw $e;
        }
    }

    public function renameBranch(string $from, string $to): void
    {
        $this->connection->call('dolt_branch', ['-m', Ref::validate($from), Ref::validate($to)]);
    }

    /**
     * Switch the connection to a branch for the rest of the process, or until the next switch.
     */
    public function checkout(?string $branch = null, bool $create = false): static
    {
        if ($create && $branch !== null && ! $this->branchExists($branch)) {
            $this->createBranch($branch);
        }

        $this->connection->checkout($branch);

        return $this;
    }

    /**
     * Run a callback on a branch, then return to where the connection was.
     *
     * @template T
     *
     * @param  callable(static): T  $callback
     * @return T
     */
    public function on(string $branch, callable $callback): mixed
    {
        $previous = $this->connection->currentBranch();

        $this->connection->checkout($branch);

        try {
            return $callback($this);
        } finally {
            $this->connection->checkout($previous);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Time travel
    |--------------------------------------------------------------------------
    */

    /**
     * Run a callback with the whole connection reading one point in history.
     *
     * Every query inside, including relationships and joins, sees the database
     * as it was. The connection is read only until the callback returns.
     *
     * @template T
     *
     * @param  callable(static): T  $callback
     * @return T
     */
    public function asOf(string|DateTimeInterface|Commit|Branch|Tag $ref, callable $callback): mixed
    {
        $hash = $this->resolve($ref);
        $previous = $this->connection->revision();

        $this->connection->readRevision($hash);

        try {
            return $callback($this);
        } finally {
            $this->connection->readRevision($previous);
        }
    }

    /**
     * Turn a branch, tag, ancestry expression, commit or moment in time into a commit hash.
     */
    public function resolve(string|DateTimeInterface|Commit|Branch|Tag $ref): string
    {
        if ($ref instanceof Commit || $ref instanceof Branch || $ref instanceof Tag) {
            return $ref->hash;
        }

        if ($ref instanceof DateTimeInterface) {
            return $this->commitAt($ref);
        }

        $ref = $this->normalize($ref);

        if (Ref::isHash($ref)) {
            return $ref;
        }

        try {
            $rows = $this->connection->select('select hashof(?) as hash', [$ref], false);
        } catch (QueryException $e) {
            throw InvalidRefException::unresolved($ref);
        }

        if (empty($rows[0]->hash)) {
            throw InvalidRefException::unresolved($ref);
        }

        return (string) $rows[0]->hash;
    }

    /**
     * Turn a ref as a person would type it into one Dolt accepts.
     *
     * A short hash, as dolt:log prints it, becomes the full hash. A date such
     * as "2031-01-01" or "2031-01-01 09:00" becomes the commit that was
     * current at that moment, read in the application timezone. Branches,
     * tags, full hashes and expressions such as main~2 pass through as they
     * are, and so does each side of a range such as main..feature.
     */
    public function normalize(string $ref): string
    {
        if (preg_match('/^(.+?)(\.{2,3})(.+)$/D', $ref, $range)) {
            return $this->normalize($range[1]).$range[2].$this->normalize($range[3]);
        }

        if (preg_match(self::MOMENT, $ref) && ! $this->isNamed($ref)) {
            return $this->commitAt(CarbonImmutable::parse($ref, $this->manager->timezone()));
        }

        Ref::validate($ref);

        if (! preg_match('/^([0-9a-v]{6,31})([~^][~^0-9]*)?$/D', $ref, $short) || $this->isNamed($short[1])) {
            return $ref;
        }

        $rows = $this->connection->select(
            'select commit_hash from dolt_commits where commit_hash like ? limit 2',
            [$short[1].'%'],
            false,
        );

        if (count($rows) > 1) {
            throw InvalidRefException::ambiguous($short[1]);
        }

        return $rows ? $rows[0]->commit_hash.($short[2] ?? '') : $ref;
    }

    /**
     * The commit that was the tip of the current branch at a moment in time.
     */
    protected function commitAt(DateTimeInterface $moment): string
    {
        $moment = CarbonImmutable::instance($moment)->utc();

        [$condition, $binding] = $this->dialect()->notAfter('date', $moment);

        $rows = $this->connection->select(
            "select commit_hash from dolt_log where {$condition} order by commit_order desc limit 1",
            [$binding],
            false,
        );

        if (! $rows) {
            throw InvalidRefException::unresolved($moment->toIso8601String());
        }

        return (string) $rows[0]->commit_hash;
    }

    /**
     * Whether a branch or tag has exactly this name. Names win over short hashes and dates.
     */
    protected function isNamed(string $ref): bool
    {
        if (! preg_match('/^[A-Za-z0-9_][A-Za-z0-9_\-\.\/]*$/D', $ref)) {
            return false;
        }

        return $this->connection->select(
            'select 1 from dolt_branches where name = ? union all select 1 from dolt_tags where tag_name = ? limit 1',
            [$ref, $ref],
            false,
        ) !== [];
    }

    /**
     * The commit hash at the tip of the current branch, or of the given ref.
     */
    public function head(?string $ref = null): string
    {
        return $this->resolve($ref ?? Ref::HEAD);
    }

    /*
    |--------------------------------------------------------------------------
    | Working set and commits
    |--------------------------------------------------------------------------
    */

    /**
     * Tables with changes that are not committed yet.
     *
     * @return Collection<int, StatusEntry>
     */
    public function status(): Collection
    {
        $rows = $this->connection->select('select * from dolt_status', [], false);

        return collect($this->reported($rows, withoutIgnored: true))->map(fn ($row) => StatusEntry::fromRow($row));
    }

    public function isDirty(): bool
    {
        if ($this->dialect()->reportsIgnoredTables()) {
            return $this->status()->isNotEmpty();
        }

        return $this->connection->select('select 1 from dolt_status limit 1', [], false) !== [];
    }

    /**
     * Commit every pending change on the current branch.
     *
     * Returns null when there was nothing to commit.
     */
    public function commit(string $message, mixed $author = null, bool $allowEmpty = false): ?Commit
    {
        $this->guard('commit');

        $author = $this->manager->author($author);
        $message = trim($message) !== '' ? trim($message) : 'Update';

        $rows = $this->connection->withoutTransactionCommit(fn () => $this->connection->call('dolt_commit', [
            '-A', '-m', $message, '--author', (string) $author, $allowEmpty ? '--allow-empty' : '--skip-empty',
        ]));

        $this->connection->forgetPendingChanges();

        $hash = $rows[0]->hash ?? null;

        if (! is_string($hash) || $hash === '') {
            return null;
        }

        $commit = $this->find($hash) ?? new Commit($hash, $message, $author, $author, CarbonImmutable::now('UTC'));

        $this->manager->dispatch(new Committed((string) $this->connection->getName(), $commit, $this->branch()));

        return $commit;
    }

    /**
     * The commit log, newest first.
     *
     * The ref may be a branch, tag, hash or a range such as "main..feature".
     *
     * @return Collection<int, Commit>
     */
    public function log(?string $ref = null, int $limit = 50, int $offset = 0): Collection
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $rows = $this->connection->select(
            "select l.*{$this->epoch(['date', 'author_date'], 'l')} from dolt_log(?, '--parents') l limit {$limit} offset {$offset}",
            [$this->normalize($ref ?? Ref::HEAD)],
            false,
        );

        return collect($rows)->map(fn ($row) => Commit::fromLogRow($row, $this->manager->timezone()));
    }

    public function find(string $ref): ?Commit
    {
        try {
            return $this->log($ref, 1)->first();
        } catch (QueryException|InvalidRefException) {
            return null;
        }
    }

    /**
     * The history as people think of it: one entry per request, job or command, newest first.
     *
     * In transaction mode a request that writes three times is three commits.
     * Here it is one Activity that holds all three. log() still lists every commit.
     *
     * @return Collection<int, Activity>
     */
    public function activity(?string $ref = null, int $limit = 20): Collection
    {
        $limit = max(1, $limit);
        $page = 200;
        $commits = [];

        for ($offset = 0; ; $offset += $page) {
            $batch = $this->log($ref, $page, $offset);

            array_push($commits, ...$batch->all());

            $activities = Activity::gather($commits);

            // One activity more than asked for means the last one asked for is complete.
            if ($batch->count() < $page || $activities->count() > $limit) {
                return $activities->take($limit)->values();
            }
        }
    }

    /**
     * Throw away uncommitted changes, or move the branch back to an earlier commit.
     *
     * Tables that were never committed are removed as well. Moving the default
     * branch back drops commits from its history, which can hold other
     * people's work, so that needs $force. revert() undoes changes with new
     * commits instead and is the safer way there.
     *
     * Returns the commit the branch was on. reset($previous, force: true) moves
     * it back there. Uncommitted changes that were thrown away cannot be.
     */
    public function reset(string|DateTimeInterface|Commit|null $to = null, bool $force = false): string
    {
        $this->guard('reset');

        $previous = $this->head();
        $arguments = ['--hard'];

        if ($to !== null) {
            $target = $this->resolve($to);
            $arguments[] = $target;

            if (! $force && $this->branch() === $this->defaultBranch()) {
                $dropped = (int) ($this->connection->select('select count(*) as n from dolt_log(?)', ["{$target}..{$previous}"], false)[0]->n ?? 0);

                if ($dropped > 0) {
                    throw DoltException::resetWouldDropCommits((string) $this->branch(), $dropped);
                }
            }
        }

        // With transaction commit on, Dolt would record the reset as a commit of its own.
        $this->connection->withoutTransactionCommit(function () use ($arguments) {
            if ($this->dialect()->cleansBeforeReset()) {
                $this->connection->call('dolt_clean');
                $this->connection->call('dolt_reset', $arguments);
            } else {
                $this->connection->call('dolt_reset', $arguments);
                $this->connection->call('dolt_clean');
            }
        });

        $this->connection->forgetPendingChanges();

        return $previous;
    }

    /**
     * Undo one commit by adding a new commit that reverses it.
     */
    public function revert(string|Commit $ref, mixed $author = null): ?Commit
    {
        $this->guard('revert');

        $author = $this->manager->author($author);
        $target = $ref instanceof Commit ? $ref->hash : $this->normalize($ref);

        $rows = $this->connection->withoutTransactionCommit(fn () => $this->connection->call('dolt_revert', [$target, '--author', (string) $author]));

        $hash = $rows[0]->hash ?? null;

        return is_string($hash) && $hash !== '' ? $this->find($hash) : $this->find(Ref::HEAD);
    }

    /*
    |--------------------------------------------------------------------------
    | Diffs
    |--------------------------------------------------------------------------
    */

    /**
     * What changed between two points. With no arguments, what is not committed yet.
     *
     * Pass a single range such as "main...feature" to compare a branch with
     * the point where it left main.
     */
    public function diff(
        string|DateTimeInterface|Commit|Branch|Tag $from = Ref::HEAD,
        string|DateTimeInterface|Commit|Branch|Tag|null $to = null,
    ): ChangeSet {
        if (is_string($from) && str_contains($from, '..')) {
            return new ChangeSet($this, $this->normalize($from));
        }

        return new ChangeSet(
            $this,
            is_string($from) ? $this->normalize($from) : $this->resolve($from),
            $to === null ? Ref::WORKING : (is_string($to) ? $this->normalize($to) : $this->resolve($to)),
        );
    }

    /**
     * The most recent commit two refs have in common.
     */
    public function mergeBase(string $a, string $b): string
    {
        $rows = $this->connection->select(
            'select dolt_merge_base(?, ?) as hash',
            [$this->normalize($a), $this->normalize($b)],
            false,
        );

        return (string) ($rows[0]->hash ?? throw InvalidRefException::unresolved("{$a} {$b}"));
    }

    /*
    |--------------------------------------------------------------------------
    | Merging
    |--------------------------------------------------------------------------
    */

    /**
     * Merge a branch into the current branch.
     *
     * $onConflict decides what happens when both sides changed the same row:
     *   "abort"   leave the branch untouched and throw MergeConflictException
     *   "ours"    keep the current branch's version of every conflicted row
     *   "theirs"  take the incoming branch's version of every conflicted row
     *   array     a strategy per table, with "*" as the fallback
     *   Closure   called with each Conflict; return "ours", "theirs", an array
     *             of column values to write, or anything else to abort
     *
     * @param  string|array<string, string|Closure>|Closure  $onConflict
     */
    public function merge(
        string $source,
        ?string $message = null,
        mixed $author = null,
        string|array|Closure $onConflict = self::ABORT,
        bool $squash = false,
        bool $fastForward = false,
    ): MergeResult {
        $this->guard('merge');

        Ref::validate($source);

        // With transaction commit on, Dolt would commit a squash under the session's name instead of the merge's.
        return $this->connection->withoutTransactionCommit(
            fn () => $this->merger()->merge($source, $message, $author, $onConflict, $squash, $fastForward),
        );
    }

    /**
     * The conflicts a merge would hit, without merging anything.
     *
     * @return Collection<int, ConflictSummary>
     */
    public function previewMerge(string $source, ?string $target = null): Collection
    {
        return $this->merger()->previewMerge($source, $target);
    }

    /**
     * The conflicting rows a merge would hit in one table, without merging anything.
     *
     * @return Collection<int, Conflict>
     */
    public function previewConflicts(string $source, string $table, ?string $target = null): Collection
    {
        return $this->merger()->previewConflicts($source, $table, $target);
    }

    protected function merger(): Merger
    {
        return $this->merger ??= new Merger($this, $this->connection, $this->manager);
    }

    /*
    |--------------------------------------------------------------------------
    | Tags
    |--------------------------------------------------------------------------
    */

    /**
     * Name a commit so it can be found again, for example before a risky import.
     */
    public function tag(string $name, string|DateTimeInterface|Commit|null $ref = null, ?string $message = null, mixed $author = null): Tag
    {
        Ref::validate($name);

        $arguments = [$name, $ref === null ? Ref::HEAD : (is_string($ref) ? $this->normalize($ref) : $this->resolve($ref))];

        if ($message !== null && trim($message) !== '') {
            array_push($arguments, '-m', trim($message));
        }

        array_push($arguments, '--author', (string) $this->manager->author($author));

        $this->connection->call('dolt_tag', $arguments);

        return $this->tags()->firstWhere('name', $name);
    }

    /**
     * @return Collection<int, Tag>
     */
    public function tags(): Collection
    {
        $rows = $this->connection->select("select t.*{$this->epoch(['date'], 't')} from dolt_tags t order by t.date desc", [], false);

        return collect($rows)->map(fn ($row) => Tag::fromRow($row, $this->manager->timezone()));
    }

    public function deleteTag(string $name): void
    {
        $this->connection->call('dolt_tag', ['-d', Ref::validate($name)]);
    }

    /*
    |--------------------------------------------------------------------------
    | Tables outside version control
    |--------------------------------------------------------------------------
    */

    /**
     * The table patterns that are kept out of version control.
     *
     * @return Collection<int, string>
     */
    public function ignored(): Collection
    {
        return $this->sharedTables()->ignored();
    }

    /**
     * Keep tables out of version control and share them between every branch.
     *
     * Sessions, cache entries and queued jobs change on every request and say
     * nothing in a diff. Tables that match a pattern are left out of commits,
     * and every branch reads and writes the one copy on the default branch, so
     * a user stays signed in while working in a workspace.
     *
     * A pattern is a table name; "%" matches any run of characters. With no
     * patterns, the "ignore" list in config/dolt.php is used. Tables that are
     * already versioned are released only when $untrack is true. Their rows
     * stay where they are.
     *
     * @param  string|list<string>|null  $patterns
     */
    public function ignore(
        string|array|null $patterns = null,
        bool $untrack = true,
        bool $commit = true,
        mixed $author = null,
    ): IgnoreResult {
        $this->guard('change which tables are versioned');

        // With transaction commit on, every change to the ignore list would be a commit of its own.
        return $this->connection->withoutTransactionCommit(fn () => $this->sharedTables()->ignore($patterns, $untrack, $commit, $author));
    }

    /**
     * The tables that exist right now and are kept out of version control.
     *
     * @return list<string>
     */
    public function ignoredTables(): array
    {
        return $this->sharedTables()->ignoredTables();
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
        return $this->sharedTables()->versionedMatching($patterns);
    }

    protected function sharedTables(): SharedTables
    {
        return $this->sharedTables ??= new SharedTables($this, $this->connection, $this->manager);
    }

    /*
    |--------------------------------------------------------------------------
    | Schema helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The primary key columns of a table on the current branch.
     *
     * @return list<string>
     */
    public function primaryKey(string $table): array
    {
        if (isset($this->primaryKeys[$table])) {
            return $this->primaryKeys[$table];
        }

        [$query, $bindings] = $this->dialect()->primaryKey((string) $this->connection->getDatabaseName(), $table);

        $rows = $this->connection->select($query, $bindings, false);

        $columns = array_map(fn ($row) => (string) $row->name, $rows);

        if ($columns !== []) {
            $this->primaryKeys[$table] = $columns;
        }

        return $columns;
    }

    /**
     * Version control calls end the open SQL transaction, and a past revision is read only.
     */
    protected function guard(string $action): void
    {
        if ($this->connection->transactionLevel() > 0) {
            throw DoltException::insideTransaction($action);
        }

        if ($this->connection->isReadingRevision()) {
            throw DoltException::readOnlyRevision($action);
        }
    }
}
