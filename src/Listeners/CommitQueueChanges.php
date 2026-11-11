<?php

namespace BrimleyLabs\Dolt\Listeners;

use BrimleyLabs\Dolt\Commits\AutoCommit;
use BrimleyLabs\Dolt\DoltManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Gives every queued job its own commits, so background writes are never
 * swept into the commit of whichever request happens to come next.
 */
class CommitQueueChanges
{
    protected ?int $scope = null;

    public function __construct(protected DoltManager $dolt, protected AutoCommit $commits) {}

    public function processing(JobProcessing $event): void
    {
        // A job run inline belongs to the request or command that dispatched it.
        if ($event->connectionName === 'sync') {
            return;
        }

        // A worker killed in the middle of a job never reported its end.
        if ($this->scope !== null) {
            $this->commits->end($this->scope);
        }

        $this->scope = $this->commits->begin('Job '.$this->name($event->job));
    }

    public function processed(JobProcessed $event): void
    {
        $this->finish($event->connectionName, 'Job '.$this->name($event->job));
    }

    public function failed(JobExceptionOccurred $event): void
    {
        $this->finish($event->connectionName, 'Job '.$this->name($event->job).' (failed)');
    }

    protected function finish(?string $queueConnection, string $message): void
    {
        if ($queueConnection === 'sync') {
            return;
        }

        try {
            $this->commits->flush(fn () => $this->dolt->pullMessage() ?? $message);
        } finally {
            if ($this->scope !== null) {
                $this->commits->end($this->scope);
                $this->scope = null;
            }

            $this->dolt->pullMessage();
        }
    }

    protected function name(object $job): string
    {
        $name = method_exists($job, 'resolveName') ? (string) $job->resolveName() : $job::class;

        return class_basename($name) ?: $name;
    }
}
