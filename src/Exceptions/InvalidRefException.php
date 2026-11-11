<?php

namespace BrimleyLabs\Dolt\Exceptions;

class InvalidRefException extends DoltException
{
    public static function forRef(string $ref): self
    {
        return new self("[{$ref}] is not a valid branch, tag or commit reference.");
    }

    public static function forTable(string $table): self
    {
        return new self("[{$table}] is not a valid table name.");
    }

    public static function unresolved(string $ref): self
    {
        return new self("No commit found for [{$ref}].");
    }

    public static function ambiguous(string $ref): self
    {
        return new self("More than one commit starts with [{$ref}]. Use more of the hash.");
    }
}
