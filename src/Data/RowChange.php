<?php

namespace BrimleyLabs\Dolt\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One row that was added, modified or removed between two points in history.
 *
 * @implements Arrayable<string, mixed>
 */
final class RowChange implements Arrayable, JsonSerializable
{
    public const ADDED = 'added';

    public const MODIFIED = 'modified';

    public const REMOVED = 'removed';

    /**
     * @param  array<string, mixed>|null  $before  Column values before the change. Null when the row was added.
     * @param  array<string, mixed>|null  $after  Column values after the change. Null when the row was removed.
     * @param  array<string, mixed>  $key  Primary key values that identify the row.
     * @param  Commit|null  $commit  The commit that made the change. Null for changes that are not committed yet.
     */
    public function __construct(
        public readonly string $table,
        public readonly string $type,
        public readonly ?array $before,
        public readonly ?array $after,
        public readonly array $key = [],
        public readonly ?Commit $commit = null,
    ) {}

    /**
     * Build a change from a row of dolt_diff() or a dolt_diff_<table> system table.
     *
     * @param  object|array<string, mixed>  $row
     * @param  list<string>  $primaryKey
     * @param  list<string>  $hidden  Columns to leave out of the result.
     */
    public static function fromDiffRow(string $table, object|array $row, array $primaryKey = [], array $hidden = [], ?Commit $commit = null): self
    {
        $row = (array) $row;
        $type = (string) ($row['diff_type'] ?? self::MODIFIED);

        $before = $after = [];
        $meta = ['to_commit', 'to_commit_date', 'from_commit', 'from_commit_date'];

        foreach ($row as $column => $value) {
            if (in_array($column, $meta, true) || str_starts_with($column, '__dolt_')) {
                continue;
            }

            if (str_starts_with($column, 'to_')) {
                $after[substr($column, 3)] = $value;
            } elseif (str_starts_with($column, 'from_')) {
                $before[substr($column, 5)] = $value;
            }
        }

        $source = $type === self::REMOVED ? $before : $after;
        $key = [];

        foreach ($primaryKey ?: (array_key_exists('id', $source) ? ['id'] : []) as $column) {
            $key[$column] = $source[$column] ?? null;
        }

        foreach ($hidden as $column) {
            unset($before[$column], $after[$column]);
        }

        return new self(
            table: $table,
            type: $type,
            before: $type === self::ADDED ? null : $before,
            after: $type === self::REMOVED ? null : $after,
            key: $key,
            commit: $commit,
        );
    }

    /**
     * Columns whose value differs between the two sides.
     *
     * @return list<string>
     */
    public function changed(): array
    {
        if ($this->before === null) {
            return array_keys($this->after ?? []);
        }

        if ($this->after === null) {
            return array_keys($this->before);
        }

        $columns = array_unique(array_merge(array_keys($this->before), array_keys($this->after)));

        return array_values(array_filter(
            $columns,
            fn (string $column) => ($this->before[$column] ?? null) !== ($this->after[$column] ?? null),
        ));
    }

    /**
     * Only the columns that changed, as [column => [before, after]].
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function changes(): array
    {
        $changes = [];

        foreach ($this->changed() as $column) {
            $changes[$column] = [$this->before[$column] ?? null, $this->after[$column] ?? null];
        }

        return $changes;
    }

    public function isAdded(): bool
    {
        return $this->type === self::ADDED;
    }

    public function isModified(): bool
    {
        return $this->type === self::MODIFIED;
    }

    public function isRemoved(): bool
    {
        return $this->type === self::REMOVED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'type' => $this->type,
            'key' => $this->key,
            'before' => $this->before,
            'after' => $this->after,
            'changed' => $this->changed(),
            'commit' => $this->commit?->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
