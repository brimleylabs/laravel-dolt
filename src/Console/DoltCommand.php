<?php

namespace BrimleyLabs\Dolt\Console;

use BrimleyLabs\Dolt\Data\ChangeSet;
use BrimleyLabs\Dolt\Data\TableChange;
use BrimleyLabs\Dolt\DoltManager;
use BrimleyLabs\Dolt\Repository;
use Illuminate\Console\Command;

abstract class DoltCommand extends Command
{
    protected function dolt(): DoltManager
    {
        return $this->laravel->make(DoltManager::class);
    }

    /**
     * The repository for the --database option, or the default Dolt connection.
     */
    protected function repository(): Repository
    {
        $name = $this->hasOption('database') ? $this->option('database') : null;

        return $this->dolt()->connection(is_string($name) && $name !== '' ? $name : null);
    }

    protected function renderChanges(ChangeSet $changes): void
    {
        $this->table(
            ['Table', 'Change', 'Rows added', 'Rows modified', 'Rows deleted', 'Schema'],
            $changes->tables()->map(fn (TableChange $table) => [
                $table->table,
                $table->type,
                $table->rowsAdded ?? '',
                $table->rowsModified ?? '',
                $table->rowsDeleted ?? '',
                $table->schemaChanged ? 'changed' : '',
            ])->values()->all(),
        );
    }
}
