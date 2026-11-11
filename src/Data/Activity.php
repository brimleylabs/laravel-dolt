<?php

namespace BrimleyLabs\Dolt\Data;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;

/**
 * One request, job or command in the history, with every commit it made.
 *
 * In transaction mode Dolt commits each transaction on its own, so a request
 * that wrote three times is three commits. Activity puts them back together.
 * A commit that belongs to no scope, such as a merge, is an activity of one.
 *
 * @implements Arrayable<string, mixed>
 */
final class Activity implements Arrayable, JsonSerializable
{
    /**
     * @param  non-empty-list<Commit>  $commits  newest first
     */
    public function __construct(public readonly array $commits) {}

    /**
     * Gather commits, newest first, into activities. Each activity sits where its newest commit is.
     *
     * Requests that overlap leave their commits interleaved in the log, so
     * commits are gathered by scope, not only when they are next to each other.
     *
     * @param  iterable<Commit>  $commits
     * @return Collection<int, self>
     */
    public static function gather(iterable $commits): Collection
    {
        /** @var array<string, non-empty-list<Commit>> $groups */
        $groups = [];

        foreach ($commits as $commit) {
            $groups[$commit->scope ?? 'commit:'.$commit->hash][] = $commit;
        }

        return collect(array_values(array_map(fn (array $group) => new self($group), $groups)));
    }

    /**
     * The newest commit.
     */
    public function head(): Commit
    {
        return $this->commits[0];
    }

    public function subject(): string
    {
        return $this->head()->subject();
    }

    public function author(): Author
    {
        return $this->head()->author;
    }

    public function date(): CarbonImmutable
    {
        return $this->head()->date;
    }

    public function scope(): ?string
    {
        return $this->head()->scope;
    }

    public function count(): int
    {
        return count($this->commits);
    }

    /**
     * The commit before this activity began, for a diff of everything it changed.
     */
    public function base(): string
    {
        $oldest = $this->commits[array_key_last($this->commits)];

        return $oldest->parents[0] ?? $oldest->hash.'~1';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject(),
            'author' => $this->author()->toArray(),
            'date' => $this->date()->toIso8601String(),
            'scope' => $this->scope(),
            'commits' => array_map(fn (Commit $commit) => $commit->toArray(), $this->commits),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
