<?php

namespace BrimleyLabs\Dolt\Data;

use BrimleyLabs\Dolt\Support\Moment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class Branch implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $name,
        public readonly string $hash,
        public readonly ?string $latestCommitter = null,
        public readonly ?string $latestCommitterEmail = null,
        public readonly ?CarbonImmutable $latestCommitDate = null,
        public readonly ?string $latestCommitMessage = null,
        public readonly bool $dirty = false,
    ) {}

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function fromRow(object|array $row, ?string $timezone = null): self
    {
        $row = (array) $row;

        $date = Moment::read($row, 'latest_commit_date');

        return new self(
            name: (string) $row['name'],
            hash: (string) $row['hash'],
            latestCommitter: $row['latest_author'] ?? $row['latest_committer'] ?? null,
            latestCommitterEmail: $row['latest_author_email'] ?? $row['latest_committer_email'] ?? null,
            latestCommitDate: $date && $timezone ? $date->setTimezone($timezone) : $date,
            latestCommitMessage: $row['latest_commit_message'] ?? null,
            dirty: (bool) ($row['dirty'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'hash' => $this->hash,
            'latest_committer' => $this->latestCommitter,
            'latest_committer_email' => $this->latestCommitterEmail,
            'latest_commit_date' => $this->latestCommitDate?->toIso8601String(),
            'latest_commit_message' => $this->latestCommitMessage,
            'dirty' => $this->dirty,
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
