<?php

namespace BrimleyLabs\Dolt\Console;

use BrimleyLabs\Dolt\Data\Activity;
use BrimleyLabs\Dolt\Data\Commit;

class LogCommand extends DoltCommand
{
    protected $signature = 'dolt:log
        {ref? : A branch, tag, commit or range such as main..feature}
        {--limit=20 : How many entries to show}
        {--all : Show every commit, instead of one line per request, job or command}
        {--database= : The Dolt connection to use}';

    protected $description = 'Show the history, newest first';

    public function handle(): int
    {
        $repository = $this->repository();
        $ref = $this->argument('ref');
        $limit = (int) $this->option('limit');

        $rows = $this->option('all')
            ? $repository->log($ref, $limit)->map(fn (Commit $commit) => [
                $commit->shortHash(),
                $commit->date->format('Y-m-d H:i'),
                $commit->author->name,
                $commit->subject(),
            ])
            : $repository->activity($ref, $limit)->map(fn (Activity $activity) => [
                $activity->head()->shortHash(),
                $activity->date()->format('Y-m-d H:i'),
                $activity->author()->name,
                $activity->subject().($activity->count() > 1 ? " ({$activity->count()} commits)" : ''),
            ]);

        if ($rows->isEmpty()) {
            $this->components->info('No commits.');

            return self::SUCCESS;
        }

        $this->table(['Commit', 'Date', 'Author', 'Message'], $rows->all());

        return self::SUCCESS;
    }
}
