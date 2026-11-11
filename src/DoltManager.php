<?php

namespace BrimleyLabs\Dolt;

use BrimleyLabs\Dolt\Commits\AutoCommit;
use BrimleyLabs\Dolt\Data\Author;
use BrimleyLabs\Dolt\Data\Commit;
use BrimleyLabs\Dolt\Database\DoltConnection;
use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Workspaces\Workspace;
use BrimleyLabs\Dolt\Workspaces\WorkspaceManager;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\ForwardsCalls;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use WeakMap;

/**
 * The entry point behind the Dolt facade.
 *
 * Calls that are not defined here go to the repository of the default Dolt
 * connection, so Dolt::commit() and Dolt::connection()->commit() are the same.
 *
 * @mixin Repository
 */
class DoltManager
{
    use ForwardsCalls;

    /** @var WeakMap<DoltConnection, Repository> */
    protected WeakMap $repositories;

    protected ?Closure $authorResolver = null;

    protected ?Closure $branchResolver = null;

    protected ?Closure $requestDescriber = null;

    protected ?string $nextMessage = null;

    public function __construct(protected Application $app)
    {
        $this->repositories = new WeakMap;
    }

    /**
     * The repository for a Dolt database connection.
     */
    public function connection(?string $name = null): Repository
    {
        $name ??= $this->defaultConnection();

        $connection = $this->app['db']->connection($name);

        if (! $connection instanceof DoltConnection) {
            throw DoltException::notDolt($name);
        }

        return $this->repositories[$connection] ??= new Repository($connection, $this);
    }

    public function defaultConnection(): string
    {
        return (string) ($this->app['config']->get('dolt.connection') ?: $this->app['config']->get('database.default'));
    }

    /**
     * Repositories for the Dolt connections this process has already opened.
     *
     * @return list<Repository>
     */
    public function opened(): array
    {
        $repositories = [];

        foreach ($this->app['db']->getConnections() as $connection) {
            if ($connection instanceof DoltConnection) {
                $repositories[] = $this->repositories[$connection] ??= new Repository($connection, $this);
            }
        }

        return $repositories;
    }

    /**
     * Commit what every opened Dolt connection has written, each on the branch it is on.
     *
     * Connections that are inside a transaction or reading the past are left alone.
     *
     * @return list<Commit>
     */
    public function commitPending(string $message, mixed $author = null): array
    {
        $commits = [];

        foreach ($this->opened() as $repository) {
            $connection = $repository->connection();

            if (! $connection->hasPendingChanges() || $connection->transactionLevel() > 0 || $connection->isReadingRevision()) {
                continue;
            }

            if ($commit = $repository->commit($message, $author)) {
                $commits[] = $commit;
            }
        }

        return $commits;
    }

    /*
    |--------------------------------------------------------------------------
    | Authors
    |--------------------------------------------------------------------------
    */

    /**
     * Who a commit should be credited to.
     *
     * An explicit value wins, then the custom resolver, then the signed in
     * user, then the system author from the config file.
     */
    public function author(mixed $author = null): Author
    {
        if ($author !== null) {
            return Author::from($author);
        }

        if ($this->authorResolver !== null && ($resolved = ($this->authorResolver)()) !== null) {
            return Author::from($resolved);
        }

        try {
            $user = $this->app->bound('auth') ? $this->app['auth']->user() : null;
        } catch (Throwable) {
            $user = null;
        }

        return $user !== null ? Author::fromUser($user) : $this->systemAuthor();
    }

    /**
     * The author used when no person is behind a change: jobs, commands and migrations.
     */
    public function systemAuthor(): Author
    {
        $config = $this->app['config'];

        $name = $config->get('dolt.author.name') ?: $config->get('app.name') ?: 'Laravel';
        $email = $config->get('dolt.author.email');

        if (! $email) {
            $host = parse_url((string) $config->get('app.url'), PHP_URL_HOST) ?: 'localhost';
            $email = "system@{$host}";
        }

        return new Author((string) $name, (string) $email);
    }

    /**
     * Decide the commit author yourself. Return anything Author::from() accepts, or null to fall through.
     */
    public function resolveAuthorUsing(?Closure $callback): static
    {
        $this->authorResolver = $callback;

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Requests
    |--------------------------------------------------------------------------
    */

    /**
     * Decide which branch a request runs on. Return null for the default branch.
     */
    public function resolveBranchUsing(?Closure $callback): static
    {
        $this->branchResolver = $callback;

        return $this;
    }

    /**
     * Write the commit message for a request yourself.
     */
    public function describeRequestUsing(?Closure $callback): static
    {
        $this->requestDescriber = $callback;

        return $this;
    }

    /**
     * Set the message for the next automatic commit, for example from a controller.
     *
     * In "transaction" mode it names the transactions that follow, not the ones before.
     */
    public function message(string $message): static
    {
        $message = trim($message);

        if ($message !== '' && $this->autoCommit()->label($message)) {
            return $this;
        }

        $this->nextMessage = $message !== '' ? $message : null;

        return $this;
    }

    /**
     * @internal Take the message set with message(), leaving none behind.
     */
    public function pullMessage(): ?string
    {
        [$message, $this->nextMessage] = [$this->nextMessage, null];

        return $message;
    }

    /**
     * @internal The branch a request should run on, or null for the default branch.
     */
    public function branchForRequest(Request $request): ?string
    {
        if ($this->branchResolver !== null) {
            $branch = ($this->branchResolver)($request);

            return is_string($branch) && $branch !== '' ? $branch : null;
        }

        $workspaces = $this->workspaces();
        $key = $workspaces->sessionKey();

        if ($key === null || ! $request->hasSession()) {
            return null;
        }

        $name = $request->session()->get($key);

        try {
            return is_string($name) && $name !== '' ? $workspaces->branchFor($name) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @internal The commit message for a finished request.
     */
    public function describeRequest(Request $request, ?Response $response = null): string
    {
        if ($message = $this->pullMessage()) {
            return $message;
        }

        if ($this->requestDescriber !== null && ($described = ($this->requestDescriber)($request, $response))) {
            return (string) $described;
        }

        $message = $request->getMethod().' /'.ltrim($request->path(), '/');

        if ($response !== null && $response->getStatusCode() >= 400) {
            $message .= " [{$response->getStatusCode()}]";
        }

        return $message;
    }

    /*
    |--------------------------------------------------------------------------
    | Workspaces and previews
    |--------------------------------------------------------------------------
    */

    public function workspaces(?string $connection = null): WorkspaceManager
    {
        $config = $this->app['config'];

        return new WorkspaceManager(
            $this,
            $connection,
            (string) $config->get('dolt.workspaces.prefix', 'workspace/'),
            'workspace',
            $config->get('dolt.workspaces.session_key', 'dolt.workspace'),
        );
    }

    public function previews(?string $connection = null): WorkspaceManager
    {
        return new WorkspaceManager(
            $this,
            $connection,
            (string) $this->app['config']->get('dolt.previews.prefix', 'preview/'),
            'preview',
        );
    }

    /**
     * Run a callback against a private copy of live data and return the result for review.
     *
     * Nothing the callback writes is live until apply() is called on the
     * returned preview. Call discard() to throw it away.
     *
     * @param  callable(Workspace): mixed  $callback
     */
    public function preview(callable $callback, ?string $title = null, mixed $author = null, ?string $connection = null): Workspace
    {
        $title = trim((string) $title) !== '' ? trim((string) $title) : 'Preview';

        $preview = $this->previews($connection)->open($title, strtolower((string) Str::ulid()), $author);

        try {
            $preview->result = $preview->run($callback, $title, $author);
        } catch (Throwable $e) {
            $preview->discard();

            throw $e;
        }

        return $preview;
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    /**
     * The table patterns from config/dolt.php that stay out of version control.
     *
     * @return list<string>
     */
    public function ignoreList(): array
    {
        $patterns = $this->app['config']->get('dolt.ignore', []);

        return is_array($patterns) ? array_values(array_filter($patterns, fn ($pattern) => is_string($pattern) && $pattern !== '')) : [];
    }

    /**
     * The timezone commit dates are shown in.
     */
    public function timezone(): ?string
    {
        return $this->app['config']->get('dolt.timezone') ?: $this->app['config']->get('app.timezone');
    }

    public function session(): ?Session
    {
        try {
            return $this->app->bound('session') ? $this->app['session']->driver() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @internal The service that names automatic commits.
     */
    public function autoCommit(): AutoCommit
    {
        return $this->app->make(AutoCommit::class);
    }

    public function dispatch(object $event): void
    {
        if ($this->app->bound('events')) {
            $this->app['events']->dispatch($event);
        }
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->connection(), $method, $parameters);
    }
}
