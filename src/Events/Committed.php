<?php

namespace BrimleyLabs\Dolt\Events;

use BrimleyLabs\Dolt\Data\Commit;

class Committed
{
    public function __construct(
        public readonly string $connection,
        public readonly Commit $commit,
        public readonly ?string $branch,
    ) {}
}
