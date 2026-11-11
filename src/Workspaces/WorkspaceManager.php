<?php

namespace BrimleyLabs\Dolt\Workspaces;

use BrimleyLabs\Dolt\Data\Author;
use BrimleyLabs\Dolt\Data\Commit;
use BrimleyLabs\Dolt\DoltManager;
use BrimleyLabs\Dolt\Events\WorkspaceOpened;
use BrimleyLabs\Dolt\Exceptions\WorkspaceException;
use BrimleyLabs\Dolt\Repository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Opens, finds and tracks workspaces: branches that share one name prefix.
 */
class WorkspaceManager
{
    public function __construct(
        protected DoltManager $dolt,
        protected ?string $connection,
        protected string $prefix,
        protected string $label = 'workspace',
        protected ?string $sessionKey = null,
    ) {}

    /**
     * Every open workspace, newest activity first.
     *
     * @return Collection<int, Workspace>
     */
    public function all(): Collection
    {
        return $this->repository()->branches()
            ->filter(fn ($branch) => str_starts_with($branch->name, $this->prefix))
            ->sortByDesc(fn ($branch) => $branch->latestCommitDate?->getTimestamp() ?? 0)
            ->map(fn ($branch) => new Workspace($this, substr($branch->name, strlen($this->prefix)), $branch->name))
            ->values();
    }

    public function find(string $name): ?Workspace
    {
        if (! $this->isValidName($name) || ! $this->repository()->branchExists($this->prefix.$name)) {
            return null;
        }

        return new Workspace($this, $name, $this->prefix.$name);
    }

    public function get(string $name): Workspace
    {
        return $this->find($name) ?? throw WorkspaceException::notFound($name);
    }

    public function exists(string $name): bool
    {
        return $this->find($name) !== null;
    }

    /**
     * Open a workspace that starts from the current live data.
     *
     * The name becomes part of a branch name. It is taken from the title when not given.
     */
    public function open(string $title, ?string $name = null, mixed $owner = null): Workspace
    {
        $title = trim($title);
        $name ??= Str::slug($title) ?: strtolower((string) Str::ulid());

        if (! $this->isValidName($name)) {
            throw WorkspaceException::invalidName($name);
        }

        $repository = $this->repository();
        $branch = $this->prefix.$name;

        if ($repository->branchExists($branch)) {
            throw WorkspaceException::alreadyExists($name);
        }

        $repository->createBranch($branch, $repository->defaultBranch());

        $owner = $this->dolt->author($owner);

        $commit = $repository->on($branch, fn (Repository $r) => $r->commit(
            $this->openingMessage($title !== '' ? $title : $name),
            $owner,
            allowEmpty: true,
        ));

        $workspace = (new Workspace($this, $name, $branch))->withDetails([
            'title' => $title !== '' ? $title : $name,
            'owner' => $owner,
            'opened_at' => $commit?->date,
        ]);

        $this->dolt->dispatch(new WorkspaceOpened($workspace));

        return $workspace;
    }

    /**
     * Find a workspace, or open it when it does not exist yet.
     */
    public function firstOrOpen(string $title, ?string $name = null, mixed $owner = null): Workspace
    {
        $name ??= Str::slug($title);

        return $this->find($name) ?? $this->open($title, $name, $owner);
    }

    /**
     * Remember a workspace in the user's session. The middleware routes their requests into it.
     */
    public function enter(string|Workspace $workspace): Workspace
    {
        $workspace = is_string($workspace) ? $this->get($workspace) : $workspace;

        if ($this->sessionKey !== null && ($session = $this->dolt->session())) {
            $session->put($this->sessionKey, $workspace->name);
        }

        return $workspace;
    }

    /**
     * Go back to live data.
     */
    public function leave(): void
    {
        if ($this->sessionKey !== null && ($session = $this->dolt->session())) {
            $session->forget($this->sessionKey);
        }
    }

    /**
     * The workspace the current user is working in, if any.
     */
    public function current(): ?Workspace
    {
        $name = $this->currentName();

        return $name !== null ? $this->find($name) : null;
    }

    public function currentName(): ?string
    {
        if ($this->sessionKey === null || ! ($session = $this->dolt->session())) {
            return null;
        }

        $name = $session->get($this->sessionKey);

        return is_string($name) && $this->isValidName($name) ? $name : null;
    }

    /**
     * The branch behind a workspace name.
     */
    public function branchFor(string $name): string
    {
        if (! $this->isValidName($name)) {
            throw WorkspaceException::invalidName($name);
        }

        return $this->prefix.$name;
    }

    /**
     * Discard workspaces with no activity since the given moment. Returns how many were removed.
     */
    public function prune(DateTimeInterface $before): int
    {
        $removed = 0;

        foreach ($this->repository()->branches() as $branch) {
            if (! str_starts_with($branch->name, $this->prefix)) {
                continue;
            }

            if ($branch->latestCommitDate !== null && $branch->latestCommitDate->getTimestamp() < $before->getTimestamp()) {
                (new Workspace($this, substr($branch->name, strlen($this->prefix)), $branch->name))->discard();

                $removed++;
            }
        }

        return $removed;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function sessionKey(): ?string
    {
        return $this->sessionKey;
    }

    public function dolt(): DoltManager
    {
        return $this->dolt;
    }

    public function repository(): Repository
    {
        return $this->dolt->connection($this->connection);
    }

    /**
     * @internal Drop the session pointer when its workspace is gone.
     */
    public function forget(Workspace $workspace): void
    {
        if ($this->currentName() === $workspace->name) {
            $this->leave();
        }
    }

    /**
     * @internal Read a workspace's title, owner and opening date from its first commit.
     *
     * @return array{title: string, owner: Author|null, opened_at: CarbonImmutable|null}
     */
    public function describe(Workspace $workspace): array
    {
        $repository = $this->repository();

        try {
            $rows = $repository->connection()->select(
                "select l.*{$repository->dialect()->epochColumns(['date', 'author_date'], 'l')} from dolt_log(?) l order by l.commit_order asc limit 1",
                ["{$repository->defaultBranch()}..{$workspace->branch}"],
                false,
            );
        } catch (QueryException) {
            $rows = [];
        }

        if (! $rows) {
            return ['title' => $workspace->name, 'owner' => null, 'opened_at' => null];
        }

        $commit = Commit::fromLogRow($rows[0], $this->dolt->timezone());
        $opening = $this->openingMessage('');

        return [
            'title' => str_starts_with($commit->message, $opening)
                ? trim(substr($commit->subject(), strlen($opening)))
                : $workspace->name,
            'owner' => $commit->author,
            'opened_at' => $commit->date,
        ];
    }

    protected function openingMessage(string $title): string
    {
        return 'Open '.$this->label.': '.$title;
    }

    protected function isValidName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9_\-]|\.(?!\.)){0,99}$/D', $name) && ! str_ends_with($name, '.');
    }
}
