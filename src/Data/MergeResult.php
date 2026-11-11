<?php

namespace BrimleyLabs\Dolt\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class MergeResult implements Arrayable, JsonSerializable
{
    /**
     * @param  string|null  $hash  The commit the target branch points at after the merge. Null when nothing was merged.
     * @param  int  $conflictsResolved  Conflicted tables that were settled by the chosen strategy.
     */
    public function __construct(
        public readonly string $source,
        public readonly string $target,
        public readonly ?string $hash,
        public readonly bool $fastForward = false,
        public readonly int $conflictsResolved = 0,
        public readonly string $message = '',
    ) {}

    /**
     * True when the target already contained everything on the source.
     */
    public function upToDate(): bool
    {
        return $this->hash === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'target' => $this->target,
            'hash' => $this->hash,
            'fast_forward' => $this->fastForward,
            'conflicts_resolved' => $this->conflictsResolved,
            'up_to_date' => $this->upToDate(),
            'message' => $this->message,
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
