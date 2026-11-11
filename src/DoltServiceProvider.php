<?php

namespace BrimleyLabs\Dolt;

use BrimleyLabs\Dolt\Commits\AutoCommit;
use BrimleyLabs\Dolt\Console\BranchCommand;
use BrimleyLabs\Dolt\Console\CommitCommand;
use BrimleyLabs\Dolt\Console\DiffCommand;
use BrimleyLabs\Dolt\Console\DoctorCommand;
use BrimleyLabs\Dolt\Console\IgnoreCommand;
use BrimleyLabs\Dolt\Console\InstallCommand;
use BrimleyLabs\Dolt\Console\LogCommand;
use BrimleyLabs\Dolt\Console\MergeCommand;
use BrimleyLabs\Dolt\Console\PruneCommand;
use BrimleyLabs\Dolt\Console\StatusCommand;
use BrimleyLabs\Dolt\Console\WorkspacesCommand;
use BrimleyLabs\Dolt\Database\DoltConnector;
use BrimleyLabs\Dolt\Database\DoltgresConnection;
use BrimleyLabs\Dolt\Database\DoltgresConnector;
use BrimleyLabs\Dolt\Database\DoltMySqlConnection;
use BrimleyLabs\Dolt\Database\QueryMacros;
use BrimleyLabs\Dolt\Http\Middleware\HandleDoltRequests;
use BrimleyLabs\Dolt\Listeners\CommitConsoleChanges;
use BrimleyLabs\Dolt\Listeners\CommitMigrations;
use BrimleyLabs\Dolt\Listeners\CommitQueueChanges;
use BrimleyLabs\Dolt\Listeners\PrepareIgnoredTables;
use BrimleyLabs\Dolt\Listeners\ResetConnections;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class DoltServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dolt.php', 'dolt');

        $this->app->singleton(DoltManager::class, fn ($app) => new DoltManager($app));
        $this->app->alias(DoltManager::class, 'dolt');

        $this->app->singleton(AutoCommit::class);
        $this->app->singleton(CommitMigrations::class);
        $this->app->singleton(CommitConsoleChanges::class);
        $this->app->singleton(CommitQueueChanges::class);

        $this->app->bind('db.connector.dolt', DoltConnector::class);

        Connection::resolverFor('dolt', fn ($pdo, $database, $prefix, $config) => new DoltMySqlConnection($pdo, $database, $prefix, $config));

        $this->app->bind('db.connector.doltgres', DoltgresConnector::class);

        Connection::resolverFor('doltgres', fn ($pdo, $database, $prefix, $config) => new DoltgresConnection($pdo, $database, $prefix, $config));

        $this->registerDefaultConnections();
    }

    public function boot(): void
    {
        QueryMacros::register();

        $this->registerMiddleware();
        $this->registerListeners();

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/dolt.php' => $this->app->configPath('dolt.php')], 'dolt-config');

            $this->commands([
                InstallCommand::class,
                DoctorCommand::class,
                IgnoreCommand::class,
                StatusCommand::class,
                LogCommand::class,
                CommitCommand::class,
                BranchCommand::class,
                DiffCommand::class,
                MergeCommand::class,
                WorkspacesCommand::class,
                PruneCommand::class,
            ]);
        }
    }

    /**
     * Offer ready made "dolt" and "doltgres" connections built from the application's own settings.
     *
     * Dolt speaks the MySQL protocol and Doltgres the Postgres one, so the
     * host, port and credentials an application already has are all either
     * needs. Setting DB_CONNECTION=dolt or DB_CONNECTION=doltgres is then the
     * whole installation. A connection the application defines itself under
     * one of those names is left alone.
     */
    protected function registerDefaultConnections(): void
    {
        $config = $this->app['config'];

        foreach (['dolt' => 'mysql', 'doltgres' => 'pgsql'] as $name => $source) {
            if ($config->get("database.connections.{$name}") !== null) {
                continue;
            }

            $settings = $config->get("database.connections.{$source}");

            if (! is_array($settings)) {
                continue;
            }

            $config->set("database.connections.{$name}", array_merge($settings, [
                'driver' => $name,
                'branch' => $config->get('dolt.branch'),
            ]));
        }
    }

    protected function registerMiddleware(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('dolt', HandleDoltRequests::class);

        // The branch has to be chosen after the session starts and the user is
        // known, and before route model binding reads any records.
        $this->callAfterResolving(HttpKernel::class, function ($kernel) {
            if (method_exists($kernel, 'addToMiddlewarePriorityBefore')) {
                $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, HandleDoltRequests::class);
            }
        });
    }

    protected function registerListeners(): void
    {
        $events = $this->app['events'];
        $config = $this->app['config'];

        if ($config->get('dolt.commit.queue', true)) {
            $events->listen(JobProcessing::class, [ResetConnections::class, 'handle']);
            $events->listen(JobProcessing::class, [CommitQueueChanges::class, 'processing']);
            $events->listen(JobProcessed::class, [CommitQueueChanges::class, 'processed']);
            $events->listen(JobExceptionOccurred::class, [CommitQueueChanges::class, 'failed']);
        }

        if ($config->get('dolt.commit.console', true)) {
            $events->listen(CommandStarting::class, [CommitConsoleChanges::class, 'starting']);
            $events->listen(CommandFinished::class, [CommitConsoleChanges::class, 'handle']);
        }

        if ($config->get('dolt.commit.migrations', true)) {
            $events->listen(MigrationStarted::class, [CommitMigrations::class, 'starting']);
            $events->listen(MigrationEnded::class, [CommitMigrations::class, 'migrated']);
            $events->listen(MigrationsEnded::class, [CommitMigrations::class, 'finished']);
        }

        $events->listen(MigrationsStarted::class, [PrepareIgnoredTables::class, 'handle']);

        // A connection opened in the middle of a request or job gets that request's author and message.
        $events->listen(ConnectionEstablished::class, fn (ConnectionEstablished $event) => $this->app->make(AutoCommit::class)->adopt($event->connection));

        // Signing in or out in the middle of a request changes who the next transactions belong to.
        foreach (['Illuminate\Auth\Events\Login', 'Illuminate\Auth\Events\Authenticated', 'Illuminate\Auth\Events\Logout'] as $event) {
            $events->listen($event, fn () => $this->app->make(AutoCommit::class)->refreshAuthor());
        }

        // Octane keeps connections alive between requests. Each request starts on the home branch.
        $events->listen('Laravel\Octane\Events\RequestReceived', [ResetConnections::class, 'handle']);
        $events->listen('Laravel\Octane\Events\RequestReceived', fn () => $this->app->make(AutoCommit::class)->reset());
    }
}
