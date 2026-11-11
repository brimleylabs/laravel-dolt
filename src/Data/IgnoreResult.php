<?php

namespace BrimleyLabs\Dolt\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * What changed when tables were taken out of version control.
 *
 * @implements Arrayable<string, mixed>
 */
final class IgnoreResult implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $added  patterns that were not ignored before
     * @param  list<string>  $released  tables that were versioned and no longer are
     * @param  list<string>  $skipped  patterns left alone because they match versioned tables
     */
    public function __construct(
        public readonly array $added = [],
        public readonly array $released = [],
        public readonly array $skipped = [],
        public readonly ?Commit $commit = null,
    ) {}

    public function changed(): bool
    {
        return $this->added !== [] || $this->released !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'added' => $this->added,
            'released' => $this->released,
            'skipped' => $this->skipped,
            'commit' => $this->commit?->hash,
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
