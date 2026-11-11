<?php

namespace BrimleyLabs\Dolt\Tests\Unit;

use BrimleyLabs\Dolt\Database\Dialects\MySqlDialect;
use BrimleyLabs\Dolt\Database\Dialects\PostgresDialect;
use PHPUnit\Framework\TestCase;

class DialectTest extends TestCase
{
    public function test_dolt_sets_every_variable_in_one_statement_with_numbers_written_in(): void
    {
        $statements = (new MySqlDialect)->setVariables([
            'dolt_transaction_commit' => 1,
            'dolt_transaction_commit_message' => "It's \"quoted\"",
            'dolt_author_name' => 'Ada',
            'dolt_force_transaction_commit' => true,
        ]);

        $this->assertSame([[
            'set @@session.dolt_transaction_commit = 1, @@session.dolt_transaction_commit_message = ?, '.
                '@@session.dolt_author_name = ?, @@session.dolt_force_transaction_commit = 1',
            ["It's \"quoted\"", 'Ada'],
        ]], $statements);
    }

    public function test_doltgres_sets_text_in_one_call_and_each_number_on_its_own(): void
    {
        $statements = (new PostgresDialect)->setVariables([
            'dolt_author_name' => 'Ada',
            'dolt_transaction_commit' => 0,
            'dolt_author_email' => 'ada@example.test',
        ]);

        $this->assertSame([
            ['set dolt_transaction_commit = 0', []],
            ["select set_config('dolt_author_name', ?, false), set_config('dolt_author_email', ?, false)", ['Ada', 'ada@example.test']],
        ], $statements);
    }

    public function test_nothing_to_set_means_no_statement(): void
    {
        $this->assertSame([], (new MySqlDialect)->setVariables([]));
        $this->assertSame([], (new PostgresDialect)->setVariables([]));
    }

    public function test_procedures_are_called_the_way_each_server_offers_them(): void
    {
        $this->assertSame('call dolt_commit(?, ?)', (new MySqlDialect)->procedure('dolt_commit', 2));
        $this->assertSame('select * from dolt_commit(?, ?)', (new PostgresDialect)->procedure('dolt_commit', 2));
        $this->assertSame('call dolt_clean()', (new MySqlDialect)->procedure('dolt_clean', 0));
    }

    public function test_names_are_quoted_safely(): void
    {
        $this->assertSame('"or""ders"', (new PostgresDialect)->quote('or"ders'));
        $this->assertSame('`orders`', (new MySqlDialect)->quote('orders'));
    }

    public function test_doltgres_table_names_lose_their_schema(): void
    {
        $this->assertSame('orders', (new PostgresDialect)->table('public.orders'));
        $this->assertSame('orders', (new MySqlDialect)->table('orders'));
    }

    public function test_each_dialect_names_the_version_it_was_tested_with(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (new MySqlDialect)->testedVersion());
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (new PostgresDialect)->testedVersion());
    }

    public function test_doltgres_numbers_shared_rows_from_the_clock(): void
    {
        $this->assertNull((new MySqlDialect)->sharedKeyDefault());
        $this->assertStringContainsString('clock_timestamp()', (string) (new PostgresDialect)->sharedKeyDefault());
    }
}
