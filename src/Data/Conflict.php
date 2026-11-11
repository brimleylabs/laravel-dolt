<?php

namespace BrimleyLabs\Dolt\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One row that both sides of a merge changed in different ways.
 *
 * @implements Arrayable<string, mixed>
 */
final class Conflict implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $base  The row at the common ancestor. Null when both sides added it.
     * @param  array<string, mixed>|null  $ours  The row on the branch being merged into. Null when it was deleted there.
     * @param  array<string, mixed>|null  $theirs  The row on the branch being merged. Null when it was deleted there.
     */
    public function __construct(
        public readonly string $table,
        public readonly string $id,
        public readonly ?array $base,
        public readonly ?array $ours,
        public readonly ?array $theirs,
        public readonly ?string $ourChange = null,
        public readonly ?string $theirChange = null,
    ) {}

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function fromRow(string $table, object|array $row): self
    {
        $row = (array) $row;
        $sides = ['base' => [], 'our' => [], 'their' => []];

        foreach ($row as $column => $value) {
            if (in_array($column, ['our_diff_type', 'their_diff_type', 'dolt_conflict_id', 'from_root_ish'], true)) {
                continue;
            }

            foreach (array_keys($sides) as $side) {
                if (str_starts_with($column, $side.'_')) {
                    $sides[$side][substr($column, strlen($side) + 1)] = $value;

                    continue 2;
                }
            }
        }

        $ourChange = $row['our_diff_type'] ?? null;
        $theirChange = $row['their_diff_type'] ?? null;
        $hasBase = array_filter($sides['base'], fn ($v) => $v !== null) !== [];

        return new self(
            table: $table,
            id: (string) ($row['dolt_conflict_id'] ?? ''),
            base: $hasBase ? $sides['base'] : null,
            ours: $ourChange === 'removed' ? null : $sides['our'],
            theirs: $theirChange === 'removed' ? null : $sides['their'],
            ourChange: $ourChange,
            theirChange: $theirChange,
        );
    }

    /**
     * Columns where the two sides disagree.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        $columns = array_unique(array_merge(array_keys($this->ours ?? []), array_keys($this->theirs ?? [])));

        return array_values(array_filter(
            $columns,
            fn (string $column) => ($this->ours[$column] ?? null) !== ($this->theirs[$column] ?? null),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'id' => $this->id,
            'base' => $this->base,
            'ours' => $this->ours,
            'theirs' => $this->theirs,
            'our_change' => $this->ourChange,
            'their_change' => $this->theirChange,
            'columns' => $this->columns(),
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
