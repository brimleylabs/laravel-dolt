<?php

namespace BrimleyLabs\Dolt\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * The person or process a Dolt commit is credited to.
 *
 * @implements Arrayable<string, string>
 */
final class Author implements Arrayable, JsonSerializable, Stringable
{
    public readonly string $name;

    public readonly string $email;

    public function __construct(string $name, string $email)
    {
        $name = trim(preg_replace('/[<>\r\n]+/', ' ', $name) ?? '');
        $email = trim(preg_replace('/[<>\s]+/', '', $email) ?? '');

        if ($name === '' || $email === '') {
            throw new InvalidArgumentException('A Dolt author needs both a name and an email address.');
        }

        $this->name = $name;
        $this->email = $email;
    }

    /**
     * Build an author from the loose values the public API accepts.
     *
     * Those are an Author, an application user, a "Name <email>" string, or an
     * array with a name and an email, keyed or as a pair. The value often comes
     * from an application's own resolver, so anything else is turned away here.
     */
    public static function from(mixed $value): self
    {
        return match (true) {
            $value instanceof self => $value,
            $value instanceof Authenticatable => self::fromUser($value),
            is_string($value) => self::parse($value),
            is_array($value) => new self(
                (string) ($value['name'] ?? $value[0] ?? ''),
                (string) ($value['email'] ?? $value[1] ?? ''),
            ),
            default => throw new InvalidArgumentException('Unsupported author value.'),
        };
    }

    /**
     * Parse the git style "Name <email>" form.
     */
    public static function parse(string $value): self
    {
        if (preg_match('/^\s*(.+?)\s*<([^<>]+)>\s*$/', $value, $m)) {
            return new self($m[1], $m[2]);
        }

        throw new InvalidArgumentException("[{$value}] is not in the form \"Name <email>\".");
    }

    /**
     * Credit a commit to an application user.
     *
     * Users without a name or email still get a stable, readable identity.
     */
    public static function fromUser(Authenticatable $user): self
    {
        $id = (string) $user->getAuthIdentifier();

        $email = self::attribute($user, 'email');

        $name = self::attribute($user, 'name')
            ?: trim(self::attribute($user, 'first_name').' '.self::attribute($user, 'last_name'))
            ?: self::attribute($user, 'username')
            ?: strstr($email, '@', true)
            ?: "User {$id}";

        return new self($name, $email ?: "user-{$id}@users.invalid");
    }

    protected static function attribute(Authenticatable $user, string $key): string
    {
        $value = method_exists($user, 'getAttribute') ? $user->getAttribute($key) : ($user->{$key} ?? null);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function is(self $other): bool
    {
        return $this->name === $other->name && strcasecmp($this->email, $other->email) === 0;
    }

    /**
     * @return array{name: string, email: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }

    /**
     * @return array{name: string, email: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return "{$this->name} <{$this->email}>";
    }
}
