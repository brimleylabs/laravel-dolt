<?php

namespace BrimleyLabs\Dolt\Events;

use BrimleyLabs\Dolt\Data\MergeResult;

class Merged
{
    public function __construct(
        public readonly string $connection,
        public readonly MergeResult $result,
    ) {}
}
