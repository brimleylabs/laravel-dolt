<?php

namespace BrimleyLabs\Dolt\Exceptions;

use RuntimeException;
use Throwable;

class DoltException extends RuntimeException
{
    public static function notDolt(string $connection): self
    {
        return new self(
            "The [{$connection}] database connection is not a Dolt connection. ".
            'Set its driver to "dolt", or "doltgres" on Postgres, in config/database.php.'
        );
    }

    public static function notADoltServer(string $connection): self
    {
        return new self(
            "The [{$connection}] connection uses a Dolt driver but the server did not answer dolt_version(). ".
            'Check that it points at a Dolt or Doltgres server.'
        );
    }

    public static function insideTransaction(string $action): self
    {
        return new self(
            "Cannot {$action} inside a database transaction. ".
            'Dolt version control calls end the open transaction, so run them after it commits.'
        );
    }

    public static function readOnlyRevision(string $action): self
    {
        return new self("Cannot {$action} while the connection is reading a past revision.");
    }

    public static function resetWouldDropCommits(string $branch, int $commits): self
    {
        $count = $commits === 1 ? 'one commit' : "{$commits} commits";

        return new self(
            "Moving [{$branch}] back would drop {$count} from its history, and other people's work with them. ".
            'Use revert() to undo changes with new commits, or pass force: true to move the branch anyway.'
        );
    }

    public static function sharedTablesUnsupported(string $version, ?Throwable $previous = null): self
    {
        return new self(
            "Dolt {$version} cannot keep tables out of version control and share them between branches. ".
            'Upgrade Dolt, or set "ignore" to an empty list in config/dolt.php.',
            previous: $previous,
        );
    }
}
