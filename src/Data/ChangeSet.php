<?php

namespace BrimleyLabs\Dolt\Data;

use BrimleyLabs\Dolt\Repository;
use BrimleyLabs\Dolt\Support\Ref;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use JsonSerializable;

/**
 * Everything that differs between two points in history.
 *
 * Nothing is read until it is asked for, and the table summary is read once.
 * Row level detail is paged, because a diff can be as large as the database.
 *
 * @implements Arrayable<string, mixed>
 */
final class ChangeSet implements Arrayable, Countable, JsonSerializable
{
    /** @var Collection<string, TableChange>|null */
    protected ?Collection $tables = null;

    /**
     * @param  string  $from  A ref, or a range such as "main...feature" when $to is null.
     */
    public function __construct(
        protected Repository $repository,
        public readonly string $from,
        public readonly ?string $to = null,
    ) {
        Ref::validate($from);

        if ($to !== null) {
            Ref::validate($to);
        }
    }

    /**
     * The tables that changed, keyed by table name.
     *
     * @return Collection<string, TableChange>
     */
    public function tables(): Collection
    {
        if ($this->tables !== null) {
            return $this->tables;
        }

        $connection = $this->repository->connection();
        $arguments = $this->arguments();

        $summary = $this->named($connection->select("select * from dolt_diff_summary({$arguments})", [], false));

        try {
            $stats = collect($this->repository->reported($connection->select("select * from dolt_diff_stat({$arguments})", [], false)))
                ->keyBy(fn (object $row): string => (string) $row->table_name);
        } catch (QueryException) {
            $stats = collect();
        }

        // The summary also flags a table whose auto increment counter moved, which
        // every insert does. The schema diff only lists real definition changes.
        try {
            $altered = $this->schema()->pluck('table')->flip();
        } catch (QueryException) {
            $altered = null;
        }

        return $this->tables = collect($summary)->mapWithKeys(function ($row) use ($stats, $altered) {
            $name = $row->to_table_name !== '' && $row->to_table_name !== null
                ? $row->to_table_name
                : $row->from_table_name;

            $stat = $stats->get($name);
            $renamed = $row->diff_type === TableChange::RENAMED;

            return [$name => new TableChange(
                table: $name,
                type: (string) $row->diff_type,
                dataChanged: (bool) $row->data_change,
                schemaChanged: (bool) $row->schema_change && ($altered === null || $altered->has($name)),
                rowsAdded: isset($stat->rows_added) ? (int) $stat->rows_added : null,
                rowsModified: isset($stat->rows_modified) ? (int) $stat->rows_modified : null,
                rowsDeleted: isset($stat->rows_deleted) ? (int) $stat->rows_deleted : null,
                previousName: $renamed ? $row->from_table_name : null,
            )];
        });
    }

    public function table(string $table): ?TableChange
    {
        return $this->tables()->get($table);
    }

    /**
     * The changed rows of one table.
     *
     * @return Collection<int, RowChange>
     */
    public function rows(string $table, int $limit = 100, int $offset = 0): Collection
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $rows = $this->repository->connection()->select(
            "select * from dolt_diff({$this->arguments()}, ".Ref::tableLiteral($table).") limit {$limit} offset {$offset}",
            [],
            false,
        );

        $primaryKey = $this->repository->primaryKey($table);

        return collect($rows)->map(fn ($row) => RowChange::fromDiffRow($table, $row, $primaryKey));
    }

    /**
     * Schema changes as before and after CREATE TABLE statements.
     *
     * @return Collection<int, array{table: string, from: string|null, to: string|null}>
     */
    public function schema(): Collection
    {
        $rows = $this->named($this->repository->connection()->select("select * from dolt_schema_diff({$this->arguments()})", [], false));

        return collect($rows)->map(fn (object $row): array => [
            'table' => (string) ($row->to_table_name ?: $row->from_table_name),
            'from' => (string) $row->from_create_statement ?: null,
            'to' => (string) $row->to_create_statement ?: null,
        ]);
    }

    /**
     * The whole change as SQL statements that would replay it.
     */
    public function patch(): string
    {
        $rows = $this->repository->connection()->select("select statement from dolt_patch({$this->arguments()})", [], false);

        return implode("\n", array_map(fn ($row) => $row->statement, $rows));
    }

    /**
     * @return array{tables: int, rows_added: int, rows_modified: int, rows_deleted: int}
     */
    public function totals(): array
    {
        $tables = $this->tables();

        return [
            'tables' => $tables->count(),
            'rows_added' => (int) $tables->sum(fn (TableChange $t) => (int) $t->rowsAdded),
            'rows_modified' => (int) $tables->sum(fn (TableChange $t) => (int) $t->rowsModified),
            'rows_deleted' => (int) $tables->sum(fn (TableChange $t) => (int) $t->rowsDeleted),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->tables()->isEmpty();
    }

    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * The number of tables that changed.
     */
    public function count(): int
    {
        return $this->tables()->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'totals' => $this->totals(),
            'tables' => $this->tables()->map->toArray()->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Rows that name a table before and after a change, with both names as the application knows them.
     *
     * @param  list<object>  $rows
     * @return list<object>
     */
    protected function named(array $rows): array
    {
        return $this->repository->reported($this->repository->reported($rows, 'to_table_name'), 'from_table_name');
    }

    /**
     * Dolt will not take bound parameters here, so the refs go in as checked literals.
     */
    protected function arguments(): string
    {
        return $this->to === null
            ? Ref::literal($this->from)
            : Ref::literal($this->from).', '.Ref::literal($this->to);
    }
}
