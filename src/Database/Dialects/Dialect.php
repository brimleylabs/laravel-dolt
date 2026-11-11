<?php

namespace BrimleyLabs\Dolt\Database\Dialects;

/**
 * The SQL that differs between the Dolt servers.
 *
 * Dolt speaks MySQL and Doltgres speaks Postgres. Both offer the same version
 * control features under the same names, and everything in this package is
 * written once against those names. The few places where the two spell a
 * statement differently are gathered here.
 */
abstract class Dialect
{
    /**
     * The Laravel driver family: "mysql" or "pgsql".
     */
    abstract public function name(): string;

    /**
     * The newest server version this package was tested with.
     */
    abstract public function testedVersion(): string;

    /**
     * Quote a table, column or database name.
     */
    abstract public function quote(string $identifier): string;

    /**
     * The statement that runs a Dolt procedure with the given number of arguments.
     */
    abstract public function procedure(string $name, int $arguments): string;

    /**
     * The statement that points a session at a database or at one of its revisions.
     */
    public function useDatabase(string $database): string
    {
        return 'use '.$this->quote($database);
    }

    /**
     * The statements, with their bindings, that set session variables in as few round trips as the server allows.
     *
     * Numbers are written into the statement because Dolt refuses a numeric
     * variable sent as text. Names must already be checked.
     *
     * @param  array<string, bool|int|string>  $variables
     * @return list<array{0: string, 1: list<string>}>
     */
    abstract public function setVariables(array $variables): array;

    /**
     * A boolean written into a statement.
     */
    abstract public function boolean(bool $value): string;

    /**
     * A placeholder for a piece of text written into one of Dolt's own tables.
     */
    public function text(): string
    {
        return '?';
    }

    /**
     * The statement that lets a merge stop on its conflicts instead of failing.
     */
    abstract public function beginMerge(): string;

    /**
     * The statement that puts the session back after a merge.
     */
    abstract public function endMerge(): string;

    /**
     * A table name as Dolt reports it, without the schema it lives in.
     */
    public function table(string $reported): string
    {
        return $reported;
    }

    /**
     * The table name a table function wants for a table.
     */
    public function tableArgument(string $table): string
    {
        return $table;
    }

    /**
     * The condition and bindings that narrow an information schema query to the tables of this database.
     *
     * @return array{0: string, 1: list<string>}
     */
    abstract public function ownTables(string $database): array;

    /**
     * The query and bindings that list the primary key columns of a table, in order, as "name".
     *
     * @return array{0: string, 1: list<string>}
     */
    abstract public function primaryKey(string $database, string $table): array;

    /**
     * How a table at a past revision is named in a from clause, before quoting.
     */
    abstract public function revisionTable(string $database, string $revision, string $table): string;

    /**
     * Extra select columns that carry a date as seconds since the epoch, or an empty string.
     *
     * Dolt reports dates in UTC. Doltgres reports them in the server's own
     * time zone without saying which, so there the exact moment is read too.
     *
     * Each one is named "<prefix><column>_epoch".
     *
     * @param  list<string>  $columns
     */
    public function epochColumns(array $columns, string $alias = '', string $prefix = ''): string
    {
        return '';
    }

    /**
     * The condition and binding that keep commits made at or before a moment.
     *
     * @return array{0: string, 1: string|float}
     */
    abstract public function notAfter(string $column, \DateTimeInterface $moment): array;

    /**
     * The query that lists the sequences Dolt reports next to tables, or null when there are none.
     */
    public function sequences(): ?string
    {
        return null;
    }

    /**
     * The default that numbers the rows of a shared table without a counter, or null
     * when a counter is safe there.
     *
     * Doltgres keeps a counter per branch and versions it, so a shared table
     * that used one would hand out the same number on two branches and leave a
     * change behind on every insert.
     */
    public function sharedKeyDefault(): ?string
    {
        return null;
    }

    /**
     * Whether the status tables list tables that dolt_ignore keeps out of version control.
     */
    public function reportsIgnoredTables(): bool
    {
        return false;
    }

    /**
     * Whether untracked tables have to be cleaned away before a hard reset instead of after it.
     */
    public function cleansBeforeReset(): bool
    {
        return false;
    }
}
