<?php

namespace BrimleyLabs\Dolt\Events;

use Throwable;

/**
 * An automatic commit failed twice. The writes are safe in the working set and
 * wait for the next commit on the branch.
 */
class CommitFailed
{
    public function __construct(
        public readonly string $connection,
        public readonly string $message,
        public readonly ?string $branch,
        public readonly Throwable $exception,
    ) {}
}
