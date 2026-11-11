<?php

namespace BrimleyLabs\Dolt\Exceptions;

use Throwable;

class BranchNotFoundException extends DoltException
{
    public string $branch = '';

    public static function named(string $branch, ?Throwable $previous = null): self
    {
        $exception = new self("Dolt branch [{$branch}] does not exist.", 0, $previous);
        $exception->branch = $branch;

        return $exception;
    }
}
