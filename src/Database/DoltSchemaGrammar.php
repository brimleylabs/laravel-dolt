<?php

namespace BrimleyLabs\Dolt\Database;

use BrimleyLabs\Dolt\Database\Concerns\RespectsSharedTables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Fluent;

/**
 * The MySQL schema grammar with two accommodations for Dolt.
 *
 * Foreign keys that touch a table shared between branches lose their cascade rules.
 *
 * With strict mode off, MySQL fills a required column that an insert leaves out
 * with the default for its type. Dolt rejects the insert instead. Applications
 * written for the MySQL behaviour set "strict" to false on their connection,
 * and for those this grammar writes the same default into the column itself.
 */
class DoltSchemaGrammar extends MySqlGrammar
{
    use RespectsSharedTables;

    /**
     * What MySQL would store for each column type when an insert leaves it out.
     */
    protected const IMPLICIT_DEFAULTS = [
        'char' => "''",
        'string' => "''",
        'uuid' => "''",
        'ipAddress' => "''",
        'macAddress' => "''",
        'set' => "''",
        'tinyText' => "('')",
        'text' => "('')",
        'mediumText' => "('')",
        'longText' => "('')",
        'binary' => "('')",
        'json' => "('null')",
        'jsonb' => "('null')",
        'bigInteger' => '0',
        'integer' => '0',
        'mediumInteger' => '0',
        'smallInteger' => '0',
        'tinyInteger' => '0',
        'boolean' => '0',
        'float' => '0',
        'double' => '0',
        'decimal' => '0',
        'year' => '0',
        'date' => "'0000-00-00'",
        'dateTime' => "'0000-00-00 00:00:00'",
        'dateTimeTz' => "'0000-00-00 00:00:00'",
        'timestamp' => "'0000-00-00 00:00:00'",
        'timestampTz' => "'0000-00-00 00:00:00'",
        'time' => "'00:00:00'",
        'timeTz' => "'00:00:00'",
    ];

    protected bool $implicitDefaults = false;

    public function useImplicitDefaults(bool $value = true): static
    {
        $this->implicitDefaults = $value;

        return $this;
    }

    public function usesImplicitDefaults(): bool
    {
        return $this->implicitDefaults;
    }

    /**
     * @param  Fluent<string, mixed>  $column
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column)
    {
        $default = parent::modifyDefault($blueprint, $column);

        if ($default !== null || ! $this->implicitDefaults) {
            return $default;
        }

        $implicit = $this->implicitDefault($column);

        return $implicit === null ? null : ' default '.$implicit;
    }

    /**
     * The default MySQL would fall back on for a required column, as SQL.
     *
     * @param  Fluent<string, mixed>  $column
     */
    protected function implicitDefault(Fluent $column): ?string
    {
        if ($column->get('nullable') || $column->get('autoIncrement')) {
            return null;
        }

        foreach (['virtualAs', 'virtualAsJson', 'storedAs', 'storedAsJson'] as $generated) {
            if ($column->get($generated) !== null) {
                return null;
            }
        }

        $type = $column->get('type');

        if ($type === 'enum') {
            $first = array_values((array) $column->get('allowed'))[0] ?? null;

            return $first === null ? null : $this->quoteString((string) $first);
        }

        return self::IMPLICIT_DEFAULTS[$type] ?? null;
    }
}
