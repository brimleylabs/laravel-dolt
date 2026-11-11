<?php

namespace BrimleyLabs\Dolt\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * How many conflicts a merge has in one table.
 *
 * @implements Arrayable<string, mixed>
 */
final class ConflictSummary implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $table,
        public readonly int $dataConflicts = 0,
        public readonly int $schemaConflicts = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'data_conflicts' => $this->dataConflicts,
            'schema_conflicts' => $this->schemaConflicts,
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
