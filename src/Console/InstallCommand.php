<?php

namespace BrimleyLabs\Dolt\Console;

class InstallCommand extends DoltCommand
{
    protected $signature = 'dolt:install';

    protected $description = 'Publish the Dolt config file and check the connection';

    public function handle(): int
    {
        $this->callSilent('vendor:publish', ['--tag' => 'dolt-config']);

        $this->components->info('Published config/dolt.php.');

        $this->components->bulletList([
            'Set DB_CONNECTION=dolt in your .env file, or DB_CONNECTION=doltgres on Postgres. The connection reuses your DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD values.',
            'Add the "dolt" middleware to the routes that write to the database, for example the "web" group.',
            'Add the Versioned trait to the models whose history you want to read.',
            'Run "php artisan dolt:ignore" if this database already holds sessions, cache or jobs tables. New databases need nothing.',
        ]);

        return $this->call('dolt:doctor');
    }
}
