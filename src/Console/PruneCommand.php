<?php

namespace BrimleyLabs\Dolt\Console;

use Carbon\CarbonImmutable;

class PruneCommand extends DoltCommand
{
    protected $signature = 'dolt:prune
        {--hours= : Discard previews with no activity for this many hours}
        {--database= : The Dolt connection to use}';

    protected $description = 'Discard previews nobody applied';

    public function handle(): int
    {
        $hours = $this->option('hours') ?? config('dolt.previews.expire_after_hours', 24);

        if (! is_numeric($hours) || (float) $hours <= 0) {
            $this->components->error('The number of hours must be greater than zero.');

            return self::FAILURE;
        }

        $removed = $this->dolt()
            ->previews($this->option('database') ?: null)
            ->prune(CarbonImmutable::now()->subMinutes((int) round((float) $hours * 60)));

        $this->components->info($removed === 1 ? 'Discarded 1 preview.' : "Discarded {$removed} previews.");

        return self::SUCCESS;
    }
}
