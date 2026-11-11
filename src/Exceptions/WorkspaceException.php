<?php

namespace BrimleyLabs\Dolt\Exceptions;

class WorkspaceException extends DoltException
{
    public static function notFound(string $name): self
    {
        return new self("Workspace [{$name}] does not exist.");
    }

    public static function alreadyExists(string $name): self
    {
        return new self("Workspace [{$name}] already exists.");
    }

    public static function invalidName(string $name): self
    {
        return new self(
            "[{$name}] is not a valid workspace name. Use letters, numbers, dashes and underscores."
        );
    }
}
