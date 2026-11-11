<?php

namespace BrimleyLabs\Dolt\Database;

use Illuminate\Database\Connectors\MySqlConnector;

/**
 * Dolt speaks the MySQL wire protocol, so the MySQL connector does the work.
 *
 * The one difference is the database name. Dolt accepts "database/branch" to
 * start a session on a branch. The connection tracks the branch itself, so the
 * connector always connects to the plain database.
 */
class DoltConnector extends MySqlConnector
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config)
    {
        if (isset($config['database'])) {
            [$config['database']] = self::splitDatabase((string) $config['database']);
        }

        return parent::connect($config);
    }

    /**
     * Split "database/branch" into its two parts. Branch names may contain slashes.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function splitDatabase(string $database): array
    {
        if (! str_contains($database, '/')) {
            return [$database, null];
        }

        [$name, $branch] = explode('/', $database, 2);

        return [$name, $branch === '' ? null : $branch];
    }
}
