<?php

namespace BrimleyLabs\Dolt\Listeners;

use BrimleyLabs\Dolt\Commits\AutoCommit;
use BrimleyLabs\Dolt\DoltManager;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationEvent;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationStarted;
use ReflectionClass;

/**
 * Records migrations under their own names, credited to the system author.
 *
 * In "request" mode a migration run is one commit that names the migrations in
 * it. The schema change and the row Laravel adds to its migrations table land
 * in the same commit, so reverting that commit takes both back together.
 *
 * In "transaction" mode each migration is labelled on its own. Dolt commits
 * every DDL statement by itself, so a migration there is several commits with
 * the same message. Doltgres runs a migration in one transaction.
 */
class CommitMigrations
{
    /** @var list<string> */
    protected array $names = [];

    protected ?int $scope = null;

    public function __construct(protected DoltManager $dolt, protected AutoCommit $commits) {}

    public function starting(MigrationStarted $event): void
    {
        if (! $this->commits->perTransaction()) {
            return;
        }

        // Laravel logs a migration in its table after MigrationEnded, so a scope runs until the next one starts.
        $this->endScope();

        $verb = $event->method === 'down' ? 'Roll back' : 'Run';

        $this->scope = $this->commits->begin("{$verb} migration {$this->name($event)}", $this->dolt->systemAuthor());
    }

    public function migrated(MigrationEnded $event): void
    {
        $this->names[] = $this->name($event);
    }

    public function finished(MigrationsEnded $event): void
    {
        [$names, $this->names] = [$this->names, []];

        if ($names === []) {
            $this->endScope();

            return;
        }

        $verb = $event->method === 'down' ? 'Roll back' : 'Run';
        $count = count($names);

        $subject = $count === 1
            ? "{$verb} migration {$names[0]}"
            : "{$verb} {$count} migrations";

        $message = $count === 1 ? $subject : $subject."\n\n".implode("\n", $names);

        try {
            $this->commits->flush($message, $this->dolt->systemAuthor());
        } finally {
            $this->endScope();
        }
    }

    /**
     * The migration's file name. Laravel 13 passes it along. Before that the
     * event only carries the migration, which is usually an anonymous class.
     */
    protected function name(MigrationEvent $event): string
    {
        if (isset($event->name)) {
            return (string) $event->name;
        }

        $file = (new ReflectionClass($event->migration))->getFileName();

        return $file ? basename($file, '.php') : $event->migration::class;
    }

    protected function endScope(): void
    {
        if ($this->scope !== null) {
            $this->commits->end($this->scope);
            $this->scope = null;
        }
    }
}
