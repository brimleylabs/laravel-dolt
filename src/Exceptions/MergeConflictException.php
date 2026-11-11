<?php

namespace BrimleyLabs\Dolt\Exceptions;

use BrimleyLabs\Dolt\Data\ConflictSummary;
use Illuminate\Support\Collection;

class MergeConflictException extends DoltException
{
    public string $source = '';

    public string $target = '';

    /** @var Collection<int, ConflictSummary> */
    public Collection $conflicts;

    /**
     * @param  Collection<int, ConflictSummary>  $conflicts
     */
    public static function between(string $source, string $target, Collection $conflicts): self
    {
        $tables = $conflicts->map(fn (ConflictSummary $c) => $c->table)->implode(', ');

        $exception = new self(
            "Merging [{$source}] into [{$target}] has conflicts in: {$tables}. ".
            'The merge was aborted and nothing changed.'
        );
        $exception->source = $source;
        $exception->target = $target;
        $exception->conflicts = $conflicts;

        return $exception;
    }
}
