<?php

namespace BrimleyLabs\Dolt\Workspaces;

use BrimleyLabs\Dolt\Data\Author;
use BrimleyLabs\Dolt\Data\Branch;
use BrimleyLabs\Dolt\Data\ChangeSet;
use BrimleyLabs\Dolt\Data\Commit;
use BrimleyLabs\Dolt\Data\Conflict;
use BrimleyLabs\Dolt\Data\ConflictSummary;
use BrimleyLabs\Dolt\Data\MergeResult;
use BrimleyLabs\Dolt\Events\WorkspaceDiscarded;
use BrimleyLabs\Dolt\Events\WorkspacePublished;
use BrimleyLabs\Dolt\Repository;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;

/**
 * A named set of changes that is kept apart from live data until it is published.
 *
 * Underneath it is a Dolt branch. Its title, owner and opening date live in the
 * first commit on that branch, so a workspace needs no table of its own.
 *
 * @implements Arrayable<string, mixed>
 */
class Workspace implements Arrayable, JsonSerializable
{
    /**
     * What the callback returned, when the workspace was made by Dolt::preview().
     */
    public mixed $result = null;

    /** @var array{title: string, owner: Author|null, opened_at: CarbonImmutable|null}|null */
    protected ?array $details = null;

    public function __construct(
        protected WorkspaceManager $manager,
        public readonly string $name,
        public readonly string $branch,
    ) {}

    public function title(): string
    {
        return $this->details()['title'];
    }

    public function owner(): ?Author
    {
        return $this->details()['owner'];
    }

    public function openedAt(): ?CarbonImmutable
    {
        return $this->details()['opened_at'];
    }

    public function exists(): bool
    {
        return $this->repository()->branchExists($this->branch);
    }

    /**
     * The branch record, with its latest commit.
     */
    public function head(): ?Branch
    {
        return $this->repository()->findBranch($this->branch);
    }

    /**
     * Run a callback inside the workspace and commit what it wrote.
     *
     * @template T
     *
     * @param  callable(static): T  $callback
     * @return T
     */
    public function run(callable $callback, ?string $message = null, mixed $author = null): mixed
    {
        $commits = $this->manager->dolt()->autoCommit();

        return $this->repository()->on($this->branch, function (Repository $repository) use ($callback, $message, $author, $commits) {
            $message ??= "Changes in {$this->title()}";

            // In "transaction" mode Dolt commits what the callback writes as it goes.
            $scope = $commits->begin($message, $author);

            try {
                $result = $callback($this);
            } finally {
                $commits->end($scope);
            }

            $repository->commit($message, $author);

            return $result;
        });
    }

    /**
     * Commit whatever is pending inside the workspace.
     */
    public function commit(string $message, mixed $author = null): ?Commit
    {
        return $this->repository()->on($this->branch, fn (Repository $repository) => $repository->commit($message, $author));
    }

    /**
     * Everything this workspace would change when published.
     */
    public function changes(): ChangeSet
    {
        $repository = $this->repository();

        return $repository->diff("{$repository->defaultBranch()}...{$this->branch}");
    }

    public function hasChanges(): bool
    {
        return $this->changes()->isNotEmpty();
    }

    /**
     * The commits made inside the workspace, newest first.
     *
     * @return Collection<int, Commit>
     */
    public function commits(int $limit = 50, int $offset = 0): Collection
    {
        $repository = $this->repository();

        return $repository->log("{$repository->defaultBranch()}..{$this->branch}", $limit, $offset);
    }

    /**
     * Tables where publishing would collide with changes made since the workspace was opened.
     *
     * @return Collection<int, ConflictSummary>
     */
    public function conflicts(): Collection
    {
        $repository = $this->repository();

        return $repository->previewMerge($this->branch, $repository->defaultBranch());
    }

    /**
     * The colliding rows in one table. "Ours" is live data, "theirs" is the workspace.
     *
     * @return Collection<int, Conflict>
     */
    public function conflictsIn(string $table): Collection
    {
        $repository = $this->repository();

        return $repository->previewConflicts($this->branch, $table, $repository->defaultBranch());
    }

    public function canPublish(): bool
    {
        return $this->conflicts()->isEmpty();
    }

    /**
     * Bring the latest live data into the workspace.
     *
     * @param  string|array<string, string|Closure>|Closure  $onConflict
     */
    public function sync(mixed $author = null, string|array|Closure $onConflict = Repository::ABORT): MergeResult
    {
        return $this->repository()->on($this->branch, function (Repository $repository) use ($author, $onConflict) {
            $default = $repository->defaultBranch();

            $repository->commit("Save changes in {$this->title()}", $author);

            return $repository->merge($default, "Sync {$default} into {$this->title()}", $author, $onConflict);
        });
    }

    /**
     * Make the workspace live by merging it into the default branch.
     *
     * In a conflict "ours" is live data and "theirs" is the workspace. The
     * workspace is removed afterwards unless $keep is true. With $squash the
     * whole workspace lands as one commit, and its own commits are left behind.
     *
     * @param  string|array<string, string|Closure>|Closure  $onConflict
     */
    public function publish(
        ?string $message = null,
        mixed $author = null,
        string|array|Closure $onConflict = Repository::ABORT,
        bool $keep = false,
        bool $squash = false,
    ): MergeResult {
        $repository = $this->repository();
        $default = $repository->defaultBranch();
        $title = $this->title();
        $label = $this->manager->label();

        $repository->on($this->branch, fn (Repository $r) => $r->commit("Save changes in {$title}", $author));

        $result = $repository->on($default, function (Repository $r) use ($message, $author, $onConflict, $squash, $title, $label) {
            // Anything written to live data outside a commit would block the merge.
            $r->commit('Save pending changes before publishing', $this->manager->dolt()->systemAuthor());

            return $r->merge($this->branch, $message ?? ucfirst("publish {$label}: {$title}"), $author, $onConflict, $squash);
        });

        if (! $keep) {
            $this->remove();
        }

        $this->manager->dolt()->dispatch(new WorkspacePublished($this, $result));

        return $result;
    }

    /**
     * Make the whole thing live as one commit. This is how a preview is approved.
     *
     * The commit carries the title and is credited to whoever opened the
     * preview, unless a message or an author is given.
     *
     * @param  string|array<string, string|Closure>|Closure  $onConflict
     */
    public function apply(?string $message = null, mixed $author = null, string|array|Closure $onConflict = Repository::ABORT): MergeResult
    {
        return $this->publish($message ?? $this->title(), $author ?? $this->owner(), $onConflict, squash: true);
    }

    /**
     * Throw the workspace away. Live data is not touched.
     */
    public function discard(): void
    {
        // Read the title before the branch that stores it is gone.
        $this->details();

        $this->remove();

        $this->manager->dolt()->dispatch(new WorkspaceDiscarded($this));
    }

    public function is(?self $other): bool
    {
        return $other !== null && $other->branch === $this->branch;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'branch' => $this->branch,
            'title' => $this->title(),
            'owner' => $this->owner()?->toArray(),
            'opened_at' => $this->openedAt()?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @internal
     *
     * @param  array{title: string, owner: Author|null, opened_at: CarbonImmutable|null}  $details
     */
    public function withDetails(array $details): static
    {
        $this->details = $details;

        return $this;
    }

    protected function remove(): void
    {
        $repository = $this->repository();

        if ($repository->branch() === $this->branch) {
            $repository->checkout($repository->defaultBranch());
        }

        if ($repository->branchExists($this->branch)) {
            $repository->deleteBranch($this->branch, force: true);
        }

        $this->manager->forget($this);
    }

    /**
     * @return array{title: string, owner: Author|null, opened_at: CarbonImmutable|null}
     */
    protected function details(): array
    {
        return $this->details ??= $this->manager->describe($this);
    }

    protected function repository(): Repository
    {
        return $this->manager->repository();
    }
}
