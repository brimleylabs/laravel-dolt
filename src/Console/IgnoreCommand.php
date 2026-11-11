<?php

namespace BrimleyLabs\Dolt\Console;

use BrimleyLabs\Dolt\Exceptions\DoltException;

class IgnoreCommand extends DoltCommand
{
    protected $signature = 'dolt:ignore
        {pattern?* : Table names or patterns. Defaults to the "ignore" list in config/dolt.php}
        {--list : Only show what is kept out of version control}
        {--database= : The Dolt connection to use}';

    protected $description = 'Keep tables such as sessions, cache and jobs out of version control';

    public function handle(): int
    {
        $repository = $this->repository();

        try {
            if ($this->option('list')) {
                return $this->show($repository->ignored()->all());
            }

            $result = $repository->ignore($this->argument('pattern') ?: null);
        } catch (DoltException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $result->changed()) {
            $this->components->info('Nothing to change.');

            return $this->show($repository->ignored()->all());
        }

        if ($result->added !== []) {
            $this->components->info('No longer versioned: '.implode(', ', $result->added).'.');
        }

        if ($result->released !== []) {
            $this->components->info('Released from version control, rows kept: '.implode(', ', $result->released).'.');

            if ($repository->branches()->count() > 1) {
                $this->components->warn(
                    'Branches made before this change still carry their own copy of these tables. '.
                    'Merge '.$repository->defaultBranch().' into them, or call sync() on each workspace, before publishing them.'
                );
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $patterns
     */
    protected function show(array $patterns): int
    {
        if ($patterns === []) {
            $this->components->info('Every table is versioned.');

            return self::SUCCESS;
        }

        $this->components->bulletList($patterns);

        return self::SUCCESS;
    }
}
