<?php

namespace BrimleyLabs\Dolt\Testing;

use BrimleyLabs\Dolt\DoltManager;
use BrimleyLabs\Dolt\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\Traits\CanConfigureMigrationCommands;

/**
 * Gives every test the same database without wrapping it in a transaction.
 *
 * A Dolt commit ends the open transaction, so the usual "roll back after each
 * test" approach cannot contain code that commits, branches or merges. This
 * trait migrates once, remembers that commit, and puts every branch back to
 * it before each test: data, schema, branches and tags.
 */
trait RefreshDoltDatabase
{
    use CanConfigureMigrationCommands;

    public function setUpRefreshDoltDatabase(): void
    {
        $this->refreshDoltDatabase();
    }

    /**
     * Migrate on first use, restore the starting point on every later call.
     */
    protected function refreshDoltDatabase(): void
    {
        $repository = $this->app->make(DoltManager::class)->connection($this->doltConnectionToRefresh());
        $connection = $repository->connection();
        $key = $connection->getName().':'.$connection->getDatabaseName();

        if (isset(RefreshDoltDatabaseState::$baselines[$key])) {
            $this->restoreDoltBaseline($repository, RefreshDoltDatabaseState::$baselines[$key]);

            return;
        }

        $this->artisan('migrate:fresh', $this->migrateFreshUsing())->run();

        $this->app[Kernel::class]->setArtisan(null);

        $repository->commit('Test baseline', $this->app->make(DoltManager::class)->systemAuthor());

        RefreshDoltDatabaseState::$baselines[$key] = [
            'hash' => $repository->head(),
            'branches' => $repository->branches()->pluck('hash', 'name')->all(),
            'tags' => $repository->tags()->pluck('name')->all(),
        ];
    }

    /**
     * @param  array{hash: string, branches: array<string, string>, tags: list<string>}  $baseline
     */
    protected function restoreDoltBaseline(Repository $repository, array $baseline): void
    {
        $connection = $repository->connection();

        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        if ($connection->isReadingRevision()) {
            $connection->readRevision(null);
        }

        $repository->checkout();

        foreach ($repository->branches() as $branch) {
            if (! isset($baseline['branches'][$branch->name])) {
                $repository->deleteBranch($branch->name, force: true);
            } elseif ($branch->name !== $repository->defaultBranch() && ($branch->hash !== $baseline['branches'][$branch->name] || $branch->dirty)) {
                $repository->on($branch->name, fn (Repository $r) => $r->reset($baseline['branches'][$branch->name]));
            }
        }

        $repository->reset($baseline['hash'], force: true);

        foreach ($repository->tags() as $tag) {
            if (! in_array($tag->name, $baseline['tags'], true)) {
                $repository->deleteTag($tag->name);
            }
        }

        // Sessions, cache and jobs live outside history, so a reset does not empty them.
        foreach ($repository->ignoredTables() as $table) {
            $connection->statement('delete from '.$repository->dialect()->quote($table));
        }

        $connection->forgetPendingChanges();
    }

    /**
     * The Dolt connection to refresh. Null means the default one.
     */
    protected function doltConnectionToRefresh(): ?string
    {
        return property_exists($this, 'doltConnection') ? $this->doltConnection : null;
    }
}
