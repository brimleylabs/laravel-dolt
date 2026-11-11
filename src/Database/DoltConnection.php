<?php

namespace BrimleyLabs\Dolt\Database;

use BrimleyLabs\Dolt\Database\Dialects\Dialect;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;

/**
 * A Laravel database connection that knows it is talking to Dolt.
 *
 * Dolt speaks MySQL and Doltgres speaks Postgres. Each has its own connection
 * class built on Laravel's, and both keep this contract, so the rest of the
 * package and your own code can type hint one name for either.
 *
 * @mixin Connection
 */
interface DoltConnection extends ConnectionInterface
{
    /**
     * The SQL spellings of the server behind this connection.
     */
    public function dialect(): Dialect;

    /**
     * The branch this connection returns to after a temporary switch.
     */
    public function homeBranch(): string;

    /**
     * The branch queries run on, or null while the connection is reading a past revision.
     */
    public function currentBranch(): ?string;

    /**
     * Switch the connection to a branch. Null returns to the home branch.
     */
    public function checkout(?string $branch = null, bool $defer = false): static;

    /**
     * Point the whole connection at one commit, read only. Null returns to the branch.
     */
    public function readRevision(?string $hash): static;

    public function isReadingRevision(): bool;

    public function revision(): ?string;

    /**
     * Set Dolt session variables on every session this connection opens.
     *
     * @param  array<string, scalar|null>  $variables
     */
    public function setSessionVariables(array $variables): static;

    /**
     * @return array<string, scalar|null>
     */
    public function sessionVariables(): array;

    /**
     * Run a callback with Dolt's transaction commit switched off.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withoutTransactionCommit(callable $callback): mixed;

    /**
     * Run a Dolt stored procedure and return its result rows.
     *
     * @param  list<scalar|null>  $arguments
     * @return list<object>
     */
    public function call(string $procedure, array $arguments = []): array;

    /**
     * Run a statement on the write session without marking the connection as changed.
     */
    public function control(string $sql): void;

    /**
     * Whether this connection has written since its last Dolt commit.
     */
    public function hasPendingChanges(): bool;

    public function forgetPendingChanges(): static;
}
