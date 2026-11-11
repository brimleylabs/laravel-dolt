<?php

namespace BrimleyLabs\Dolt\Database\Concerns;

use BrimleyLabs\Dolt\Database\DoltConnector;
use BrimleyLabs\Dolt\Exceptions\BranchNotFoundException;
use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Exceptions\InvalidRefException;
use BrimleyLabs\Dolt\Support\Ref;
use InvalidArgumentException;
use PDO;
use PDOException;
use WeakMap;

/**
 * What makes a Laravel connection a Dolt connection, whichever SQL flavour it speaks.
 *
 * In Dolt the checked out branch belongs to the database session. Laravel opens
 * sessions lazily, may hold a separate one for reads, and replaces them when a
 * connection drops. This trait keeps the wanted branch, revision and session
 * variables itself and brings every PDO session in line before it is used, so
 * a reconnect can never quietly land a query on the wrong branch.
 */
trait ManagesDoltSession
{
    /**
     * The branch a session sits on when nothing else is asked for.
     */
    protected ?string $homeBranch = null;

    /**
     * The branch queries should run on. Null until a branch is configured or first switched.
     */
    protected ?string $branch = null;

    /**
     * The commit the connection is reading, when it is looking at the past.
     */
    protected ?string $revision = null;

    /**
     * Session variables every PDO session should carry.
     *
     * @var array<string, scalar|null>
     */
    protected array $sessionVariables = [];

    /**
     * Bumped on every state change so an up to date session costs one integer comparison.
     */
    protected int $stateVersion = 0;

    /**
     * What has been applied to each PDO session.
     *
     * @var WeakMap<PDO, array{version: int, branch: string|null, revision: string|null, variables: array<string, scalar|null>}>
     */
    protected WeakMap $sessions;

    /**
     * Whether this connection has written anything since the last Dolt commit.
     */
    protected bool $pendingChanges = false;

    /**
     * How many callers have switched transaction commit off for the moment.
     */
    protected int $transactionCommitSuspended = 0;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        [$database, $branch] = DoltConnector::splitDatabase((string) $database);

        $config['database'] = $database;

        parent::__construct($pdo, $database, $tablePrefix, $config);

        $this->sessions = new WeakMap;

        $branch = $config['branch'] ?? $branch;

        if (is_string($branch) && $branch !== '') {
            $this->homeBranch = $this->branch = Ref::validate($branch);
        }
    }

    public function getPdo()
    {
        return $this->synchronise(parent::getPdo());
    }

    public function getReadPdo()
    {
        return $this->synchronise(parent::getReadPdo());
    }

    /**
     * The branch this connection returns to after a temporary switch.
     */
    public function homeBranch(): string
    {
        if ($this->homeBranch !== null) {
            return $this->homeBranch;
        }

        // Nothing has moved the session yet, so the server can say where it starts.
        $pdo = parent::getPdo();

        try {
            $branch = $this->fetch($pdo, 'select active_branch() as branch')[0]->branch ?? null;
        } catch (PDOException $e) {
            throw DoltException::notADoltServer((string) $this->getName());
        }

        if (! is_string($branch) || $branch === '') {
            throw DoltException::notADoltServer((string) $this->getName());
        }

        $this->homeBranch = $this->branch = $branch;

        $state = $this->sessions[$pdo] ?? $this->blankState();
        $state['branch'] = $branch;
        $this->sessions[$pdo] = $state;

        return $branch;
    }

    /**
     * The branch queries run on, or null while the connection is reading a past revision.
     */
    public function currentBranch(): ?string
    {
        if ($this->revision !== null) {
            return null;
        }

        return $this->branch ?? $this->homeBranch();
    }

    /**
     * Switch the connection to a branch. Null returns to the home branch.
     *
     * A deferred switch is applied the next time the connection is used. That
     * makes returning home at the end of a request free when nothing follows.
     */
    public function checkout(?string $branch = null, bool $defer = false): static
    {
        $home = $this->homeBranch();
        $branch = Ref::validate($branch ?? $home);

        if ($this->revision !== null) {
            throw DoltException::readOnlyRevision('switch branches');
        }

        if ($branch === $this->branch) {
            return $this;
        }

        if ($this->transactions > 0) {
            throw DoltException::insideTransaction('switch branches');
        }

        $previous = $this->branch;

        $this->branch = $branch;
        $this->stateVersion++;

        if (! $defer) {
            try {
                $this->getPdo();
            } catch (BranchNotFoundException $e) {
                $this->branch = $previous;
                $this->stateVersion++;

                throw $e;
            }
        }

        return $this;
    }

    /**
     * Point the whole connection at one commit, read only. Null returns to the branch.
     */
    public function readRevision(?string $hash): static
    {
        if ($hash === $this->revision) {
            return $this;
        }

        if ($this->transactions > 0) {
            throw DoltException::insideTransaction('change revision');
        }

        if ($hash !== null) {
            $this->homeBranch();

            if (! Ref::isHash($hash)) {
                throw InvalidRefException::forRef($hash);
            }
        }

        $this->revision = $hash;
        $this->stateVersion++;

        $this->getPdo();

        return $this;
    }

    public function isReadingRevision(): bool
    {
        return $this->revision !== null;
    }

    public function revision(): ?string
    {
        return $this->revision;
    }

    /**
     * Set Dolt session variables on every session this connection opens.
     *
     * A null value hands the variable back to its default.
     *
     * @param  array<string, scalar|null>  $variables
     */
    public function setSessionVariables(array $variables): static
    {
        foreach ($variables as $name => $value) {
            // Callers can pass any array. A numeric key fails the pattern too.
            if (! preg_match('/^[a-z][a-z0-9_]*$/D', $name)) {
                throw new InvalidArgumentException("[{$name}] is not a valid session variable name.");
            }

            if ($value === null) {
                unset($this->sessionVariables[$name]);
            } else {
                $this->sessionVariables[$name] = $value;
            }
        }

        $this->stateVersion++;

        return $this;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function sessionVariables(): array
    {
        return $this->sessionVariables;
    }

    /**
     * Run a Dolt stored procedure and return its result rows.
     *
     * Dolt procedures always answer with a result set. Reading it through a
     * prepared statement keeps the session clean for the next query.
     *
     * The arguments are bound in order and their keys are ignored, so a
     * keyed array from a caller still lines up with the placeholders.
     *
     * @param  array<scalar|null>  $arguments
     * @return list<object>
     */
    public function call(string $procedure, array $arguments = []): array
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/D', $procedure)) {
            throw new InvalidArgumentException("[{$procedure}] is not a valid procedure name.");
        }

        $query = $this->dialect()->procedure($procedure, count($arguments));

        return $this->run($query, array_values($arguments), function ($query, $bindings) {
            if ($this->pretending()) {
                return [];
            }

            $statement = $this->prepared($this->getPdo()->prepare($query));

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $statement->execute();

            $rows = $statement->columnCount() > 0 ? $statement->fetchAll() : [];

            $statement->closeCursor();

            return $rows;
        });
    }

    /**
     * Run a statement on the write session without marking the connection as changed.
     */
    public function control(string $sql): void
    {
        $this->getPdo()->exec($sql);
    }

    public function recordsHaveBeenModified($value = true)
    {
        // With transaction commit on, Dolt commits the write when its transaction ends.
        if ($value && empty($this->wantedVariables()['dolt_transaction_commit'])) {
            $this->pendingChanges = true;
        }

        parent::recordsHaveBeenModified($value);
    }

    /**
     * Whether this connection has written anything Dolt has not committed yet.
     *
     * Writes made with transaction commit on are committed by Dolt itself and do not count.
     */
    public function hasPendingChanges(): bool
    {
        return $this->pendingChanges;
    }

    public function forgetPendingChanges(): static
    {
        $this->pendingChanges = false;

        return $this;
    }

    /**
     * Bring a PDO session in line with the wanted branch, revision and variables.
     */
    protected function synchronise(PDO $pdo): PDO
    {
        $state = $this->sessions[$pdo] ?? null;

        if ($state !== null && $state['version'] === $this->stateVersion) {
            return $pdo;
        }

        $state ??= $this->blankState();

        if ($state['revision'] !== null && $state['revision'] !== $this->revision) {
            $pdo->exec($this->dialect()->useDatabase($this->database));
            $state['revision'] = null;
        }

        if ($this->branch !== null && $state['branch'] !== $this->branch) {
            $this->checkoutOn($pdo, $this->branch);
            $state['branch'] = $this->branch;
        }

        if ($this->revision !== null && $state['revision'] !== $this->revision) {
            $pdo->exec($this->dialect()->useDatabase($this->database.'/'.$this->revision));
            $state['revision'] = $this->revision;
        }

        $state['variables'] = $this->applyVariables($pdo, $state['variables']);
        $state['version'] = $this->stateVersion;

        $this->sessions[$pdo] = $state;

        return $pdo;
    }

    protected function checkoutOn(PDO $pdo, string $branch): void
    {
        try {
            $this->fetch($pdo, $this->dialect()->procedure('dolt_checkout', 1), [$branch]);
        } catch (PDOException $e) {
            if (preg_match('/did not match|not found|could not find|unknown branch/i', $e->getMessage())) {
                throw BranchNotFoundException::named($branch, $e);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, scalar|null>  $applied
     * @return array<string, scalar|null>
     */
    protected function applyVariables(PDO $pdo, array $applied): array
    {
        $changes = [];

        // The value that switches each variable this package manages back off.
        $defaults = [
            'dolt_author_name' => '',
            'dolt_author_email' => '',
            'dolt_transaction_commit' => 0,
            'dolt_transaction_commit_message' => '',
        ];

        $wanted = $this->wantedVariables();

        foreach ($wanted as $name => $value) {
            if (! array_key_exists($name, $applied) || $applied[$name] !== $value) {
                $changes[$name] = $value;
            }
        }

        foreach (array_diff_key($applied, $wanted) as $name => $value) {
            $changes[$name] = $defaults[$name] ?? '';
        }

        foreach ($this->dialect()->setVariables(array_map(fn ($value) => is_float($value) ? (string) $value : $value, $changes)) as [$sql, $bindings]) {
            $this->fetch($pdo, $sql, $bindings);
        }

        return $wanted;
    }

    /**
     * The variables a session should carry right now.
     *
     * @return array<string, scalar|null>
     */
    protected function wantedVariables(): array
    {
        $variables = $this->sessionVariables;

        if ($this->transactionCommitSuspended > 0) {
            unset($variables['dolt_transaction_commit']);
        }

        return $variables;
    }

    /**
     * Run a callback with Dolt's transaction commit switched off.
     *
     * Version control calls such as a merge or a reset make their own commits,
     * with their own message and author. With transaction commit on, Dolt would
     * add a commit of its own around them, credited to whoever the session
     * names. A connection without transaction commit runs the callback as is.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withoutTransactionCommit(callable $callback): mixed
    {
        if (empty($this->sessionVariables['dolt_transaction_commit'])) {
            return $callback();
        }

        $this->transactionCommitSuspended++;
        $this->stateVersion++;

        try {
            return $callback();
        } finally {
            $this->transactionCommitSuspended--;
            $this->stateVersion++;
        }
    }

    /**
     * @param  list<scalar|null>  $bindings
     * @return list<object>
     */
    protected function fetch(PDO $pdo, string $sql, array $bindings = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);

        $rows = $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_OBJ) : [];

        $statement->closeCursor();

        return $rows;
    }

    /**
     * @return array{version: int, branch: string|null, revision: string|null, variables: array<string, scalar|null>}
     */
    protected function blankState(): array
    {
        return ['version' => -1, 'branch' => null, 'revision' => null, 'variables' => []];
    }
}
