<?php

namespace BrimleyLabs\Dolt\Data;

use BrimleyLabs\Dolt\Support\Moment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class Tag implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $name,
        public readonly string $hash,
        public readonly ?string $tagger = null,
        public readonly ?string $email = null,
        public readonly ?CarbonImmutable $date = null,
        public readonly ?string $message = null,
    ) {}

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function fromRow(object|array $row, ?string $timezone = null): self
    {
        $row = (array) $row;

        $date = Moment::read($row, 'date');

        return new self(
            name: (string) $row['tag_name'],
            hash: (string) $row['tag_hash'],
            tagger: $row['tagger'] ?? null,
            email: $row['email'] ?? null,
            date: $date && $timezone ? $date->setTimezone($timezone) : $date,
            message: $row['message'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'hash' => $this->hash,
            'tagger' => $this->tagger,
            'email' => $this->email,
            'date' => $this->date?->toIso8601String(),
            'message' => $this->message,
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
