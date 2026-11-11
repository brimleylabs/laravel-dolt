<?php

namespace BrimleyLabs\Dolt\Listeners;

use BrimleyLabs\Dolt\DoltManager;
use Throwable;

/**
 * Long running workers reuse database sessions. Each unit of work starts on the home branch.
 */
class ResetConnections
{
    public function __construct(protected DoltManager $dolt) {}

    public function handle(object $event): void
    {
        if (($event->connectionName ?? null) === 'sync') {
            return;
        }

        foreach ($this->dolt->opened() as $repository) {
            $connection = $repository->connection();

            if ($connection->transactionLevel() > 0) {
                continue;
            }

            try {
                if ($connection->isReadingRevision()) {
                    $connection->readRevision(null);
                }

                $connection->checkout(null, defer: true);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
