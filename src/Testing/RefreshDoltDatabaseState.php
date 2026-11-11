<?php

namespace BrimleyLabs\Dolt\Testing;

class RefreshDoltDatabaseState
{
    /**
     * The starting point of every database migrated in this process.
     *
     * @var array<string, array{hash: string, branches: array<string, string>, tags: list<string>}>
     */
    public static array $baselines = [];
}
