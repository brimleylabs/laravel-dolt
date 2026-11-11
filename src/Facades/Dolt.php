<?php

namespace BrimleyLabs\Dolt\Facades;

use BrimleyLabs\Dolt\DoltManager;
use BrimleyLabs\Dolt\Repository;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \BrimleyLabs\Dolt\Repository connection(?string $name = null)
 * @method static string defaultConnection()
 * @method static list<\BrimleyLabs\Dolt\Data\Commit> commitPending(string $message, mixed $author = null)
 * @method static \BrimleyLabs\Dolt\Data\Author author(mixed $author = null)
 * @method static \BrimleyLabs\Dolt\Data\Author systemAuthor()
 * @method static \BrimleyLabs\Dolt\DoltManager resolveAuthorUsing(?\Closure $callback)
 * @method static \BrimleyLabs\Dolt\DoltManager resolveBranchUsing(?\Closure $callback)
 * @method static \BrimleyLabs\Dolt\DoltManager describeRequestUsing(?\Closure $callback)
 * @method static \BrimleyLabs\Dolt\DoltManager message(string $message)
 * @method static \BrimleyLabs\Dolt\Workspaces\WorkspaceManager workspaces(?string $connection = null)
 * @method static \BrimleyLabs\Dolt\Workspaces\WorkspaceManager previews(?string $connection = null)
 * @method static \BrimleyLabs\Dolt\Workspaces\Workspace preview(callable $callback, ?string $title = null, mixed $author = null, ?string $connection = null)
 * @method static string version()
 * @method static string defaultBranch()
 * @method static string|null branch()
 * @method static \Illuminate\Support\Collection<int, \BrimleyLabs\Dolt\Data\Branch> branches()
 * @method static \BrimleyLabs\Dolt\Data\Branch|null findBranch(string $name)
 * @method static bool branchExists(string $name)
 * @method static \BrimleyLabs\Dolt\Data\Branch createBranch(string $name, string|\DateTimeInterface|\BrimleyLabs\Dolt\Data\Commit|null $from = null)
 * @method static void deleteBranch(string $name, bool $force = false)
 * @method static void renameBranch(string $from, string $to)
 * @method static \BrimleyLabs\Dolt\Repository checkout(?string $branch = null, bool $create = false)
 * @method static mixed on(string $branch, callable $callback)
 * @method static mixed asOf(string|\DateTimeInterface|\BrimleyLabs\Dolt\Data\Commit|\BrimleyLabs\Dolt\Data\Branch|\BrimleyLabs\Dolt\Data\Tag $ref, callable $callback)
 * @method static string resolve(string|\DateTimeInterface|\BrimleyLabs\Dolt\Data\Commit|\BrimleyLabs\Dolt\Data\Branch|\BrimleyLabs\Dolt\Data\Tag $ref)
 * @method static string head(?string $ref = null)
 * @method static \Illuminate\Support\Collection<int, \BrimleyLabs\Dolt\Data\StatusEntry> status()
 * @method static bool isDirty()
 * @method static \BrimleyLabs\Dolt\Data\Commit|null commit(string $message, mixed $author = null, bool $allowEmpty = false)
 * @method static \Illuminate\Support\Collection<int, \BrimleyLabs\Dolt\Data\Commit> log(?string $ref = null, int $limit = 50, int $offset = 0)
 * @method static \BrimleyLabs\Dolt\Data\Commit|null find(string $ref)
 * @method static \Illuminate\Support\Collection<int, \BrimleyLabs\Dolt\Data\Activity> activity(?string $ref = null, int $limit = 20)
 * @method static string reset(string|\DateTimeInterface|\BrimleyLabs\Dolt\Data\Commit|null $to = null, bool $force = false)
 * @method static \BrimleyLabs\Dolt\Data\Commit|null revert(string|\BrimleyLabs\Dolt\Data\Commit $ref, mixed $author = null)
 * @method static \BrimleyLabs\Dolt\Data\ChangeSet diff(mixed $from = 'HEAD', mixed $to = null)
 * @method static string mergeBase(string $a, string $b)
 * @method static \BrimleyLabs\Dolt\Data\MergeResult merge(string $source, ?string $message = null, mixed $author = null, string|array<string, string|\Closure>|\Closure $onConflict = 'abort', bool $squash = false, bool $fastForward = false)
 * @method static \Illuminate\Support\Collection<int, \BrimleyLabs\Dolt\Data\ConflictSummary> previewMerge(string $source, ?string $target = null)
 * @method static \Illuminate\Support\Collection<int, \BrimleyLabs\Dolt\Data\Conflict> previewConflicts(string $source, string $table, ?string $target = null)
 * @method static \BrimleyLabs\Dolt\Data\Tag tag(string $name, string|\DateTimeInterface|\BrimleyLabs\Dolt\Data\Commit|null $ref = null, ?string $message = null, mixed $author = null)
 * @method static \Illuminate\Support\Collection<int, \BrimleyLabs\Dolt\Data\Tag> tags()
 * @method static void deleteTag(string $name)
 * @method static \Illuminate\Support\Collection<int, string> ignored()
 * @method static \BrimleyLabs\Dolt\Data\IgnoreResult ignore(string|list<string>|null $patterns = null, bool $untrack = true, bool $commit = true, mixed $author = null)
 * @method static list<string> ignoredTables()
 * @method static list<string> versionedMatching(string|list<string>|null $patterns = null)
 * @method static list<string> ignoreList()
 * @method static list<string> primaryKey(string $table)
 *
 * @see DoltManager
 * @see Repository
 */
class Dolt extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DoltManager::class;
    }
}
