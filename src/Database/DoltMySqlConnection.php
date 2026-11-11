<?php

namespace BrimleyLabs\Dolt\Database;

use BrimleyLabs\Dolt\Database\Concerns\ManagesDoltSession;
use BrimleyLabs\Dolt\Database\Dialects\Dialect;
use BrimleyLabs\Dolt\Database\Dialects\MySqlDialect;
use Illuminate\Database\MySqlConnection;

/**
 * A MySQL connection that knows it is talking to Dolt.
 */
class DoltMySqlConnection extends MySqlConnection implements DoltConnection
{
    use ManagesDoltSession;

    protected ?Dialect $dialect = null;

    public function dialect(): Dialect
    {
        return $this->dialect ??= new MySqlDialect;
    }

    /**
     * Packages and migrations ask for the driver name to choose SQL syntax. Dolt wants MySQL syntax.
     */
    public function getDriverName()
    {
        return 'mysql';
    }

    public function getDriverTitle()
    {
        return 'Dolt';
    }

    /**
     * MySQL fills in required columns an insert leaves out when strict mode is off. Dolt
     * does not, so for a connection with "strict" set to false the columns get those
     * defaults when they are created. "implicit_defaults" overrides the choice.
     */
    protected function getDefaultSchemaGrammar()
    {
        return (new DoltSchemaGrammar($this))->useImplicitDefaults(
            (bool) ($this->config['implicit_defaults'] ?? (($this->config['strict'] ?? null) === false))
        );
    }
}
