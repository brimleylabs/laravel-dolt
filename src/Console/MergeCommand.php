<?php

namespace BrimleyLabs\Dolt\Console;

use BrimleyLabs\Dolt\Data\ConflictSummary;
use BrimleyLabs\Dolt\Exceptions\MergeConflictException;
use BrimleyLabs\Dolt\Repository;

class MergeCommand extends DoltCommand
{
    protected $signature = 'dolt:merge
        {source : The branch to merge into the current branch}
        {--message= : The merge commit message}
        {--ours : On a conflict keep the current branch\'s rows}
        {--theirs : On a conflict take the incoming branch\'s rows}
        {--squash : Bring the changes in as one commit, without the branch history}
        {--dry-run : Only report the conflicts the merge would hit}
        {--database= : The Dolt connection to use}';

    protected $description = 'Merge a branch into the current branch';

    public function handle(): int
    {
        $repository = $this->repository();
        $source = (string) $this->argument('source');

        if ($this->option('dry-run')) {
            $conflicts = $repository->previewMerge($source);

            if ($conflicts->isEmpty()) {
                $this->components->info("{$source} merges into {$repository->branch()} without conflicts.");

                return self::SUCCESS;
            }

            $this->renderConflicts($conflicts->all());

            return self::FAILURE;
        }

        $strategy = match (true) {
            (bool) $this->option('ours') => Repository::OURS,
            (bool) $this->option('theirs') => Repository::THEIRS,
            default => Repository::ABORT,
        };

        try {
            $result = $repository->merge(
                $source,
                $this->option('message') ?: null,
                $this->dolt()->systemAuthor(),
                $strategy,
                (bool) $this->option('squash'),
            );
        } catch (MergeConflictException $e) {
            $this->components->error("{$source} conflicts with {$e->target}. Nothing was merged.");
            $this->renderConflicts($e->conflicts->all());
            $this->components->bulletList(['Run again with --ours or --theirs to settle every conflict one way.']);

            return self::FAILURE;
        }

        if ($result->upToDate()) {
            $this->components->info("{$result->target} already has everything in {$source}.");

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Merged %s into %s%s.',
            $source,
            $result->target,
            $result->hash ? ' as '.substr($result->hash, 0, 8) : '',
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, ConflictSummary>  $conflicts
     */
    protected function renderConflicts(array $conflicts): void
    {
        $this->table(
            ['Table', 'Row conflicts', 'Schema conflicts'],
            array_map(fn (ConflictSummary $c) => [$c->table, $c->dataConflicts, $c->schemaConflicts], $conflicts),
        );
    }
}
