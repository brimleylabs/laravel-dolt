<?php

namespace BrimleyLabs\Dolt\Listeners;

use BrimleyLabs\Dolt\Database\DoltConnection;
use BrimleyLabs\Dolt\DoltManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\MigrationsStarted;
use Throwable;

/**
 * Applies the "ignore" list before migrations create any tables.
 *
 * On a new database this is what keeps the sessions, cache and jobs tables
 * out of the very first commit. Tables that are already versioned are left
 * alone here; releasing them is a decision for "php artisan dolt:ignore".
 */
class PrepareIgnoredTables
{
    public function __construct(protected DoltManager $dolt, protected Application $app) {}

    public function handle(MigrationsStarted $event): void
    {
        if ($event->method !== 'up' || ! empty($event->options['pretend']) || $this->dolt->ignoreList() === []) {
            return;
        }

        try {
            $name = $this->app->bound('migrator') ? $this->app['migrator']->getConnection() : null;

            $connection = $this->app['db']->connection($name ?: null);

            if (! $connection instanceof DoltConnection || $connection->transactionLevel() > 0 || $connection->isReadingRevision()) {
                return;
            }

            $repository = $this->dolt->connection($connection->getName());

            // Migrations tried out on a workspace or preview do not get to change the list.
            if ($repository->branch() !== $repository->defaultBranch()) {
                return;
            }

            $repository->ignore(untrack: false, commit: (bool) config('dolt.commit.migrations', true));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
