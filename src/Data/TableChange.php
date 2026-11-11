<?php

namespace BrimleyLabs\Dolt\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * What changed in one table between two points in history.
 *
 * @implements Arrayable<string, mixed>
 */
final class TableChange implements Arrayable, JsonSerializable
{
    public const ADDED = 'added';

    public const DROPPED = 'dropped';

    public const MODIFIED = 'modified';

    public const RENAMED = 'renamed';

    public function __construct(
        public readonly string $table,
        public readonly string $type,
        public readonly bool $dataChanged,
        public readonly bool $schemaChanged,
        public readonly ?int $rowsAdded = null,
        public readonly ?int $rowsModified = null,
        public readonly ?int $rowsDeleted = null,
        public readonly ?string $previousName = null,
    ) {}

    /**
     * Total rows touched, when Dolt can count them for this table.
     */
    public function rowsChanged(): ?int
    {
        if ($this->rowsAdded === null && $this->rowsModified === null && $this->rowsDeleted === null) {
            return null;
        }

        return (int) $this->rowsAdded + (int) $this->rowsModified + (int) $this->rowsDeleted;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'type' => $this->type,
            'data_changed' => $this->dataChanged,
            'schema_changed' => $this->schemaChanged,
            'rows_added' => $this->rowsAdded,
            'rows_modified' => $this->rowsModified,
            'rows_deleted' => $this->rowsDeleted,
            'previous_name' => $this->previousName,
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
