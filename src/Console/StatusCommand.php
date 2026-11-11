<?php

namespace BrimleyLabs\Dolt\Console;

class StatusCommand extends DoltCommand
{
    protected $signature = 'dolt:status {--database= : The Dolt connection to use}';

    protected $description = 'Show the current branch and the changes that are not committed yet';

    public function handle(): int
    {
        $repository = $this->repository();

        $this->components->twoColumnDetail('Branch', (string) $repository->branch());
        $this->components->twoColumnDetail('Head', substr($repository->head(), 0, 8));

        $changes = $repository->diff();

        if ($changes->isEmpty()) {
            $this->newLine();
            $this->components->info('Nothing to commit.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->renderChanges($changes);

        return self::SUCCESS;
    }
}
