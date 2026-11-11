<?php

namespace BrimleyLabs\Dolt\Console;

use BrimleyLabs\Dolt\Data\RowChange;
use BrimleyLabs\Dolt\Support\Ref;

class DiffCommand extends DoltCommand
{
    protected $signature = 'dolt:diff
        {from? : Where to compare from. Leave out for uncommitted changes. Accepts a range such as main...feature}
        {to? : Where to compare to. Defaults to the current, uncommitted state}
        {--table= : Show the changed rows of one table}
        {--limit=50 : How many rows to show with --table}
        {--database= : The Dolt connection to use}';

    protected $description = 'Show what changed between two points in history';

    public function handle(): int
    {
        $changes = $this->repository()->diff($this->argument('from') ?? Ref::HEAD, $this->argument('to'));

        if ($changes->isEmpty()) {
            $this->components->info('No changes.');

            return self::SUCCESS;
        }

        $table = $this->option('table');

        if (! is_string($table) || $table === '') {
            $this->renderChanges($changes);

            return self::SUCCESS;
        }

        $rows = $changes->rows($table, (int) $this->option('limit'));

        if ($rows->isEmpty()) {
            $this->components->info("No row changes in {$table}.");

            return self::SUCCESS;
        }

        $this->table(
            ['Change', 'Row', 'Column', 'Before', 'After'],
            $rows->flatMap(fn (RowChange $row) => $this->lines($row))->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return list<array{string, string, string, string, string}>
     */
    protected function lines(RowChange $row): array
    {
        $key = implode(', ', array_map(fn ($value) => $this->show($value), $row->key));
        $columns = $row->isModified()
            ? array_keys($row->changes())
            : array_keys($row->after ?? $row->before ?? []);

        $lines = [];

        foreach ($columns as $column) {
            $lines[] = [
                $row->type,
                $key,
                (string) $column,
                $this->show($row->before[$column] ?? null),
                $this->show($row->after[$column] ?? null),
            ];
        }

        return $lines;
    }

    protected function show(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }
}
