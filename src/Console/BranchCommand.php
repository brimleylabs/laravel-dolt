<?php

namespace BrimleyLabs\Dolt\Console;

use BrimleyLabs\Dolt\Data\Branch;
use BrimleyLabs\Dolt\Exceptions\DoltException;

class BranchCommand extends DoltCommand
{
    protected $signature = 'dolt:branch
        {name? : The branch to create or delete. Leave out to list branches}
        {--from= : Where a new branch starts: a branch, tag or commit}
        {--delete : Delete the branch}
        {--force : Delete even when the branch holds unmerged work}
        {--database= : The Dolt connection to use}';

    protected $description = 'List, create or delete branches';

    public function handle(): int
    {
        $repository = $this->repository();
        $name = $this->argument('name');

        if ($name === null) {
            $current = $repository->branch();

            $this->table(
                ['', 'Branch', 'Head', 'Last commit', 'By', 'Message'],
                $repository->branches()->map(fn (Branch $branch) => [
                    $branch->name === $current ? '*' : '',
                    $branch->name,
                    substr($branch->hash, 0, 8),
                    $branch->latestCommitDate?->format('Y-m-d H:i') ?? '',
                    $branch->latestCommitter ?? '',
                    strtok((string) $branch->latestCommitMessage, "\n") ?: '',
                ])->all(),
            );

            return self::SUCCESS;
        }

        if ($this->option('delete')) {
            try {
                $repository->deleteBranch($name, (bool) $this->option('force'));
            } catch (DoltException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->components->info("Deleted branch {$name}.");

            return self::SUCCESS;
        }

        $branch = $repository->createBranch($name, $this->option('from') ?: null);

        $this->components->info("Created branch {$branch->name} at ".substr($branch->hash, 0, 8).'.');

        return self::SUCCESS;
    }
}
