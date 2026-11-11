<?php

namespace BrimleyLabs\Dolt\Database\Concerns;

use BrimleyLabs\Dolt\Support\Adjustments;
use BrimleyLabs\Dolt\Support\Ref;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;
use Throwable;

/**
 * What a schema grammar has to know about tables shared between branches.
 */
trait RespectsSharedTables
{
    /**
     * Dolt refuses cascade rules on a foreign key that touches a table shared between
     * branches. Such a key is left out, so the migrations of packages like Telescope run.
     *
     * Null is how a grammar says "no statement": the blueprint skips it, as it
     * does for the foreign keys Laravel's own SQLite grammar cannot add.
     *
     * @see Adjustments
     *
     * @param  Fluent<string, mixed>  $command
     * @return string|null
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command)
    {
        if (($command->get('onDelete') !== null || $command->get('onUpdate') !== null) && $this->touchesSharedTable($blueprint, $command)) {
            Adjustments::make(
                (string) $this->connection->getName(),
                'cascade_foreign_key',
                sprintf(
                    'The foreign key from %s (%s) to %s (%s) has an on delete or on update rule, and Dolt refuses those on tables shared between branches.',
                    $blueprint->getTable(),
                    implode(', ', (array) $command->get('columns')),
                    (string) $command->get('on'),
                    implode(', ', (array) $command->get('references')),
                ),
                'The key was left out. Delete related rows yourself where the rule would have.',
            );

            return null;
        }

        return parent::compileForeign($blueprint, $command);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    protected function touchesSharedTable(Blueprint $blueprint, Fluent $command): bool
    {
        return $this->isSharedTable($blueprint->getTable()) || $this->isSharedTable((string) $command->get('on'));
    }

    /**
     * Whether a table, named without the connection's prefix, is shared between branches.
     */
    protected function isSharedTable(string $table): bool
    {
        try {
            $patterns = $this->connection->select('select table_name from dolt_nonlocal_tables', [], false);
        } catch (Throwable) {
            return false;
        }

        $table = $this->connection->getTablePrefix().$table;

        foreach ($patterns as $row) {
            if (Ref::matches((string) $row->table_name, $table)) {
                return true;
            }
        }

        return false;
    }
}
