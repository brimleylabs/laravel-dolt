<?php

namespace BrimleyLabs\Dolt\Support;

use Carbon\CarbonImmutable;

/**
 * Reads the dates Dolt puts on commits, branches and tags.
 */
final class Moment
{
    /**
     * The first of the given columns that holds a date, as a UTC moment.
     *
     * Dolt reports dates in UTC. Doltgres reports them in the server's own time
     * zone, so queries there also select "<column>_epoch", which wins when present.
     *
     * @param  array<string, mixed>  $row
     */
    public static function read(array $row, string ...$columns): ?CarbonImmutable
    {
        foreach ($columns as $column) {
            if (isset($row[$column.'_epoch']) && is_numeric($row[$column.'_epoch'])) {
                return CarbonImmutable::createFromTimestampUTC((float) $row[$column.'_epoch']);
            }

            if (! empty($row[$column])) {
                return CarbonImmutable::parse((string) $row[$column], 'UTC');
            }
        }

        return null;
    }
}
