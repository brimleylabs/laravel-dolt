<?php

namespace BrimleyLabs\Dolt\Console;

use BrimleyLabs\Dolt\Workspaces\Workspace;

class WorkspacesCommand extends DoltCommand
{
    protected $signature = 'dolt:workspaces
        {--previews : List previews instead of workspaces}
        {--database= : The Dolt connection to use}';

    protected $description = 'List open workspaces and what each one would change';

    public function handle(): int
    {
        $database = $this->option('database') ?: null;

        $manager = $this->option('previews')
            ? $this->dolt()->previews($database)
            : $this->dolt()->workspaces($database);

        $workspaces = $manager->all();

        if ($workspaces->isEmpty()) {
            $this->components->info("No open {$manager->label()}s.");

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Title', 'Owner', 'Opened', 'Tables changed', 'Can publish'],
            $workspaces->map(fn (Workspace $workspace) => [
                $workspace->name,
                $workspace->title(),
                $workspace->owner()->name ?? '',
                $workspace->openedAt()?->format('Y-m-d H:i') ?? '',
                $workspace->changes()->count(),
                $workspace->canPublish() ? 'yes' : 'conflicts',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
