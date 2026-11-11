<?php

namespace BrimleyLabs\Dolt\Console;

use BrimleyLabs\Dolt\Database\DoltgresConnection;
use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Http\Middleware\HandleDoltRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Throwable;

class DoctorCommand extends DoltCommand
{
    protected $signature = 'dolt:doctor {--database= : The Dolt connection to check}';

    protected $description = 'Check that the application is connected to Dolt and set up to record history';

    public function handle(): int
    {
        try {
            $repository = $this->repository();
        } catch (DoltException $e) {
            $this->components->error($e->getMessage());
            $this->components->bulletList(['Set DB_CONNECTION=dolt, or DB_CONNECTION=doltgres on Postgres, or give a connection the "dolt" or "doltgres" driver in config/database.php.']);

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error('Could not connect: '.$e->getMessage());

            return self::FAILURE;
        }

        $connection = $repository->connection();

        try {
            $version = $repository->version();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $author = $this->dolt()->systemAuthor();
        $pending = $repository->status()->count();
        $perTransaction = $this->dolt()->autoCommit()->perTransaction();

        $this->components->twoColumnDetail('Connection', (string) $connection->getName());
        $this->components->twoColumnDetail('Database', (string) $connection->getDatabaseName());
        $server = $repository->dialect()->name() === 'pgsql' ? 'Doltgres' : 'Dolt';
        $tested = $repository->dialect()->testedVersion();

        $this->components->twoColumnDetail("{$server} version", $version);
        $this->components->twoColumnDetail('Tested with', "{$server} {$tested}");

        if ($connection instanceof DoltgresConnection) {
            $this->components->twoColumnDetail('Row locks', $connection->supports('row_locks') ? 'taken' : 'not supported yet, left off');
            $this->components->twoColumnDetail('ILIKE', $connection->supports('ilike') ? 'supported' : 'not supported yet, rewritten with lower()');
        }

        $this->components->twoColumnDetail('Branch', (string) $repository->branch());
        $this->components->twoColumnDetail('Commits', $perTransaction ? 'one per transaction (transaction mode)' : 'one per request (request mode)');
        $this->components->twoColumnDetail('System author', (string) $author);
        $this->components->twoColumnDetail('Uncommitted tables', (string) $pending);

        $warnings = [];

        if (preg_match('/^\d+(\.\d+)*/', $version, $plain) && version_compare($plain[0], $tested, '>')) {
            $warnings[] = "This server runs {$server} {$version}, newer than the {$server} {$tested} this package was tested with. ".
                'Run your test suite against it before production moves.';
        } elseif (isset($plain[0]) && version_compare($plain[0], $tested, '<')) {
            $warnings[] = "This server runs {$server} {$version}, older than the {$server} {$tested} this package was tested with. ".
                'Some features may be missing. Upgrade the server if anything fails.';
        }

        if (! $this->middlewareInUse()) {
            $warnings[] = 'No route uses the "dolt" middleware yet. Web requests will not be committed until one does.';
        }

        if ($pending > 0) {
            $warnings[] = $perTransaction
                ? 'There are uncommitted changes. The next write by anyone will carry them into its own commit, under that writer\'s name. '.
                    'Run "php artisan dolt:commit" to record them first.'
                : 'There are uncommitted changes. Run "php artisan dolt:commit" to record them.';
        }

        try {
            $this->components->twoColumnDetail('Not versioned', $repository->ignored()->implode(', ') ?: 'nothing');

            if ($versioned = $repository->versionedMatching()) {
                $warnings[] = 'These tables are versioned but are on the "ignore" list: '.implode(', ', $versioned).'. '.
                    'Every request that touches them adds to the history. Run "php artisan dolt:ignore" to release them.';
            }
        } catch (DoltException $e) {
            $warnings[] = $e->getMessage();
        }

        $this->newLine();

        if ($warnings !== []) {
            foreach ($warnings as $warning) {
                $this->components->warn($warning);
            }

            return self::SUCCESS;
        }

        $this->components->info('Everything looks good.');

        return self::SUCCESS;
    }

    protected function middlewareInUse(): bool
    {
        // Middleware groups reach the router when the HTTP kernel is built,
        // which a console command does not otherwise do.
        $kernel = $this->laravel->make(Kernel::class);

        if (method_exists($kernel, 'hasMiddleware') && $kernel->hasMiddleware(HandleDoltRequests::class)) {
            return true;
        }

        $router = $this->laravel->make(Router::class);

        foreach ($router->getRoutes()->getRoutes() as $route) {
            foreach ($router->gatherRouteMiddleware($route) as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, HandleDoltRequests::class)) {
                    return true;
                }
            }
        }

        return false;
    }
}
