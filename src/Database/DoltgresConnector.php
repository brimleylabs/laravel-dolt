<?php

namespace BrimleyLabs\Dolt\Database;

use Illuminate\Database\Connectors\PostgresConnector;

/**
 * Doltgres speaks the Postgres wire protocol, so the Postgres connector does the work.
 *
 * As with Dolt, a "database/branch" name is cut back to the plain database.
 * The connection tracks the branch itself.
 */
class DoltgresConnector extends PostgresConnector
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config)
    {
        if (isset($config['database'])) {
            [$config['database']] = DoltConnector::splitDatabase((string) $config['database']);
        }

        return parent::connect($config);
    }
}
