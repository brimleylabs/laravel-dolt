<?php

namespace BrimleyLabs\Dolt\Listeners;

use BrimleyLabs\Dolt\Commits\AutoCommit;
use BrimleyLabs\Dolt\DoltManager;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

/**
 * Commits what an artisan command wrote, under the command's name.
 *
 * A command that runs other commands through Artisan::call() is recorded under
 * the outer command's name, so a migration that calls a command does not split
 * the migration run.
 */
class CommitConsoleChanges
{
    /**
     * The commands running right now, outermost first.
     *
     * @var list<string>
     */
    protected array $running = [];

    protected ?int $scope = null;

    public function __construct(protected DoltManager $dolt, protected AutoCommit $commits) {}

    public function starting(CommandStarting $event): void
    {
        $command = trim((string) $event->command);

        if ($this->running === []) {
            $this->scope = $this->commits->begin($this->message($command));
        }

        $this->running[] = $command;
    }

    public function handle(CommandFinished $event): void
    {
        $command = trim((string) $event->command);

        // A command that threw never reports finishing, so everything above this one is dropped with it.
        $position = array_search($command, array_reverse($this->running, true), true);

        $this->running = $position === false ? $this->running : array_slice($this->running, 0, $position);

        if ($this->running !== []) {
            return;
        }

        try {
            $this->commits->flush(fn () => $this->dolt->pullMessage() ?? $this->message($command));
        } finally {
            if ($this->scope !== null) {
                $this->commits->end($this->scope);
                $this->scope = null;
            }

            $this->dolt->pullMessage();
        }
    }

    protected function message(string $command): string
    {
        return $command !== '' ? "artisan {$command}" : 'artisan';
    }
}
