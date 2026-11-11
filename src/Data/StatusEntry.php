<?php

namespace BrimleyLabs\Dolt\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One table with changes that are not in a Dolt commit yet.
 *
 * @implements Arrayable<string, mixed>
 */
final class StatusEntry implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $table,
        public readonly string $status,
        public readonly bool $staged = false,
    ) {}

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function fromRow(object|array $row): self
    {
        $row = (array) $row;

        return new self(
            table: (string) $row['table_name'],
            status: (string) $row['status'],
            staged: (bool) ($row['staged'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['table' => $this->table, 'status' => $this->status, 'staged' => $this->staged];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
