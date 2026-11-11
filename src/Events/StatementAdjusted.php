<?php

namespace BrimleyLabs\Dolt\Events;

/**
 * A statement was changed to run on Dolt or Doltgres, and does a little less than it asked for.
 *
 * $kind is "cascade_foreign_key" or "row_lock".
 */
class StatementAdjusted
{
    public function __construct(
        public readonly string $connection,
        public readonly string $kind,
        public readonly string $message,
    ) {}
}
