<?php

namespace BrimleyLabs\Dolt\Data;

use BrimleyLabs\Dolt\Support\Moment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One entry in the Dolt commit log.
 *
 * @implements Arrayable<string, mixed>
 */
final class Commit implements Arrayable, JsonSerializable
{
    /**
     * The last line of a commit Dolt made in transaction mode names the request, job or command it belongs to.
     */
    public const SCOPE_TRAILER = 'Dolt-Scope:';

    /**
     * @param  list<string>  $parents
     * @param  string|null  $scope  the request, job or command the commit belongs to, in transaction mode
     */
    public function __construct(
        public readonly string $hash,
        public readonly string $message,
        public readonly Author $author,
        public readonly Author $committer,
        public readonly CarbonImmutable $date,
        public readonly array $parents = [],
        public readonly ?string $scope = null,
    ) {}

    /**
     * Build a commit from a dolt_log row. Dolt reports commit dates in UTC.
     *
     * @param  object|array<string, mixed>  $row
     */
    public static function fromLogRow(object|array $row, ?string $timezone = null): self
    {
        $row = (array) $row;

        $committer = new Author(
            (string) ($row['committer'] ?? 'unknown') ?: 'unknown',
            (string) ($row['email'] ?? 'unknown@localhost') ?: 'unknown@localhost',
        );

        $author = ! empty($row['author']) && ! empty($row['author_email'])
            ? new Author((string) $row['author'], (string) $row['author_email'])
            : $committer;

        $date = Moment::read($row, 'author_date', 'date', 'commit_date') ?? CarbonImmutable::now('UTC');

        $parents = array_values(array_filter(array_map('trim', explode(',', (string) ($row['parents'] ?? '')))));

        [$message, $scope] = self::splitScope((string) ($row['message'] ?? ''));

        return new self(
            hash: (string) ($row['commit_hash'] ?? $row['commit'] ?? ''),
            message: $message,
            author: $author,
            committer: $committer,
            date: $timezone ? $date->setTimezone($timezone) : $date,
            parents: $parents,
            scope: $scope,
        );
    }

    /**
     * The message without its scope line, and the scope.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function splitScope(string $message): array
    {
        if (preg_match('/\A(.*?)\s*\n'.preg_quote(self::SCOPE_TRAILER, '/').' ([a-z0-9]+)\s*\z/s', $message, $match)) {
            return [$match[1], $match[2]];
        }

        return [$message, null];
    }

    public function shortHash(int $length = 8): string
    {
        return substr($this->hash, 0, $length);
    }

    public function isMerge(): bool
    {
        return count($this->parents) > 1;
    }

    /**
     * The first line of the commit message.
     */
    public function subject(): string
    {
        return trim(strtok($this->message, "\n") ?: '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hash' => $this->hash,
            'message' => $this->message,
            'author' => $this->author->toArray(),
            'committer' => $this->committer->toArray(),
            'date' => $this->date->toIso8601String(),
            'parents' => $this->parents,
            'scope' => $this->scope,
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
