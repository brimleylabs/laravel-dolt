<?php

namespace BrimleyLabs\Dolt\Console;

class CommitCommand extends DoltCommand
{
    protected $signature = 'dolt:commit
        {message : What changed}
        {--author= : Who to credit, as "Name <email>"}
        {--allow-empty : Make a commit even when nothing changed}
        {--database= : The Dolt connection to use}';

    protected $description = 'Commit every pending change on the current branch';

    public function handle(): int
    {
        $repository = $this->repository();

        $commit = $repository->commit(
            (string) $this->argument('message'),
            $this->option('author') ?: $this->dolt()->systemAuthor(),
            (bool) $this->option('allow-empty'),
        );

        if ($commit === null) {
            $this->components->info('Nothing to commit.');

            return self::SUCCESS;
        }

        $this->components->info("Committed {$commit->shortHash()} on {$repository->branch()}.");

        return self::SUCCESS;
    }
}
