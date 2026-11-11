<?php

namespace BrimleyLabs\Dolt\Commits;

use BrimleyLabs\Dolt\Data\Author;
use BrimleyLabs\Dolt\Data\Commit;
use BrimleyLabs\Dolt\Database\DoltConnection;
use BrimleyLabs\Dolt\DoltManager;
use BrimleyLabs\Dolt\Events\CommitFailed;
use BrimleyLabs\Dolt\Repository;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Decides who and what each automatic commit is for.
 *
 * Requests, jobs, commands and migrations each open a scope with a message and
 * an author. Scopes nest: a command called from a request has its own, and the
 * request's comes back when the command ends.
 *
 * In "transaction" mode Dolt commits every SQL transaction as it happens. A
 * scope puts its message and author on every Dolt session, so each commit is
 * credited to the person whose transaction it was, even when requests overlap.
 *
 * In "request" mode nothing is sent to the server. What a scope wrote is
 * committed when it ends, which is cheaper but can sweep in writes from other
 * sessions on the same branch.
 */
class AutoCommit
{
    public const REQUEST = 'request';

    public const TRANSACTION = 'transaction';

    /**
     * Open scopes, outermost first.
     *
     * @var list<array{id: string, message: Closure|string, author: mixed, fixed: bool}>
     */
    protected array $scopes = [];

    /**
     * The variables every Dolt session carries right now.
     *
     * @var array<string, scalar|null>
     */
    protected array $variables = self::OFF;

    protected bool $resolving = false;

    protected const OFF = [
        'dolt_transaction_commit' => null,
        'dolt_transaction_commit_message' => null,
        'dolt_author_name' => null,
        'dolt_author_email' => null,
    ];

    public function __construct(protected DoltManager $dolt, protected Config $config) {}

    public function mode(): string
    {
        return $this->config->get('dolt.commit.mode') === self::REQUEST ? self::REQUEST : self::TRANSACTION;
    }

    /**
     * Whether Dolt commits every transaction itself.
     */
    public function perTransaction(): bool
    {
        return $this->mode() === self::TRANSACTION;
    }

    /**
     * Whether a scope is open.
     */
    public function active(): bool
    {
        return $this->scopes !== [];
    }

    /**
     * Open a scope. Pass the returned token to end().
     *
     * A null author is worked out the usual way: the custom resolver, then the
     * signed in user, then the system author. A closure message is only called
     * when it is needed.
     *
     * @param  Closure(): string|string  $message
     */
    public function begin(Closure|string $message, mixed $author = null): int
    {
        $token = count($this->scopes);

        $this->scopes[] = ['id' => bin2hex(random_bytes(6)), 'message' => $message, 'author' => $author, 'fixed' => $author !== null];

        $this->apply();

        return $token;
    }

    /**
     * Close a scope and every scope opened inside it.
     */
    public function end(int $token): void
    {
        if ($token >= count($this->scopes)) {
            return;
        }

        $this->scopes = array_slice($this->scopes, 0, max(0, $token));

        $this->apply();
    }

    /**
     * Give the current scope a new message. Transactions from now on carry it.
     */
    public function label(string $message): bool
    {
        if (! $this->perTransaction() || $this->scopes === []) {
            return false;
        }

        $this->scopes[array_key_last($this->scopes)]['message'] = $message;

        $this->apply();

        return true;
    }

    /**
     * Work the author out again, after somebody signed in or out.
     */
    public function refreshAuthor(): void
    {
        if ($this->resolving || ! $this->perTransaction() || $this->scopes === []) {
            return;
        }

        if ($this->scopes[array_key_last($this->scopes)]['fixed']) {
            return;
        }

        $this->apply();
    }

    /**
     * Close every scope, for a worker that starts a new unit of work.
     */
    public function reset(): void
    {
        $this->scopes = [];

        $this->apply();
    }

    /**
     * Give a connection opened in the middle of a scope the scope's variables.
     */
    public function adopt(object $connection): void
    {
        if ($connection instanceof DoltConnection && $this->variables !== self::OFF) {
            $connection->setSessionVariables($this->variables);
        }
    }

    /**
     * Commit what every opened Dolt connection has written and Dolt has not, each on its own branch.
     *
     * In "transaction" mode Dolt commits as it goes, so this only finds writes
     * made while no scope was open, such as one from a middleware that runs
     * before "dolt". Leaving them would sweep them into the next person's commit.
     *
     * A commit that fails is tried once more. If it fails again it is reported
     * and CommitFailed is fired, and the writes wait in the working set.
     * Connections inside a transaction or reading the past are left alone.
     *
     * @param  Closure(): string|string  $message
     * @return list<Commit>
     */
    public function flush(Closure|string $message, mixed $author = null): array
    {
        $commits = [];

        foreach ($this->dolt->opened() as $repository) {
            $connection = $repository->connection();

            if (! $connection->hasPendingChanges() || $connection->transactionLevel() > 0 || $connection->isReadingRevision()) {
                continue;
            }

            if ($message instanceof Closure) {
                $message = (string) $message();
            }

            if ($commit = $this->commit($repository, $message, $author)) {
                $commits[] = $commit;
            }
        }

        return $commits;
    }

    protected function commit(Repository $repository, string $message, mixed $author): ?Commit
    {
        try {
            return $repository->commit($message, $author);
        } catch (Throwable) {
            // A lost session or a clash with another writer usually clears at once.
            usleep(50_000);
        }

        try {
            return $repository->commit($message, $author);
        } catch (Throwable $e) {
            report($e);

            $connection = $repository->connection();

            try {
                $branch = $connection->currentBranch();
            } catch (Throwable) {
                $branch = null;
            }

            $this->dolt->dispatch(new CommitFailed((string) $connection->getName(), $message, $branch, $e));

            return null;
        }
    }

    /**
     * Put the current scope's variables on every opened Dolt connection.
     */
    protected function apply(): void
    {
        $variables = $this->resolveVariables();

        if ($variables === $this->variables) {
            return;
        }

        $this->variables = $variables;

        foreach ($this->dolt->opened() as $repository) {
            $repository->connection()->setSessionVariables($variables);
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function resolveVariables(): array
    {
        if (! $this->perTransaction() || $this->scopes === []) {
            return self::OFF;
        }

        $key = array_key_last($this->scopes);
        $scope = $this->scopes[$key];

        // Finding the signed in user can fire the events that bring us back here.
        $this->resolving = true;

        try {
            if ($scope['message'] instanceof Closure) {
                $this->scopes[$key]['message'] = $scope['message'] = (string) ($scope['message'])();
            }

            $author = $this->dolt->author($scope['author']);
        } catch (Throwable $e) {
            report($e);

            $author = $this->dolt->systemAuthor();
        } finally {
            $this->resolving = false;
        }

        $message = trim($scope['message']) !== '' ? trim($scope['message']) : 'Update';

        return [
            'dolt_transaction_commit' => 1,
            // The scope's id lets the log show a request's commits as one entry. Commit::fromLogRow() takes it off again.
            'dolt_transaction_commit_message' => $message."\n\n".Commit::SCOPE_TRAILER.' '.$scope['id'],
            'dolt_author_name' => $author->name,
            'dolt_author_email' => $author->email,
        ];
    }
}
