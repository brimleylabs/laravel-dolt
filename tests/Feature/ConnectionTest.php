<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Database\DoltConnection;
use BrimleyLabs\Dolt\Exceptions\BranchNotFoundException;
use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class ConnectionTest extends TestCase
{
    public function test_the_dolt_driver_resolves_a_dolt_connection(): void
    {
        $connection = DB::connection('dolt');

        $this->assertInstanceOf(DoltConnection::class, $connection);
        $this->assertSame(static::onDoltgres() ? 'pgsql' : 'mysql', $connection->getDriverName());
        $this->assertSame($this->databaseName, $connection->getDatabaseName());
    }

    public function test_it_reports_the_server_version(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', Dolt::version());
    }

    public function test_it_starts_on_the_servers_default_branch(): void
    {
        $this->assertSame('main', Dolt::branch());
        $this->assertSame('main', Dolt::defaultBranch());
    }

    public function test_a_non_dolt_connection_is_refused(): void
    {
        config(['database.connections.plain' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        $this->expectException(DoltException::class);

        Dolt::connection('plain');
    }

    public function test_checkout_moves_queries_to_another_branch(): void
    {
        Dolt::createBranch('feature');
        Dolt::checkout('feature');

        $this->makePost('Only on feature');
        Dolt::commit('Add a post');

        $this->assertSame('feature', Dolt::branch());
        $this->assertSame(1, Post::count());

        Dolt::checkout('main');

        $this->assertSame(0, Post::count());
    }

    public function test_checkout_can_create_the_branch(): void
    {
        Dolt::checkout('brand-new', create: true);

        $this->assertSame('brand-new', Dolt::branch());
        $this->assertTrue(Dolt::branchExists('brand-new'));
    }

    public function test_checking_out_a_missing_branch_throws_and_stays_put(): void
    {
        try {
            Dolt::checkout('nope');
            $this->fail('Expected BranchNotFoundException.');
        } catch (BranchNotFoundException $e) {
            $this->assertSame('nope', $e->branch);
        }

        $this->assertSame('main', Dolt::branch());
        $this->assertSame(0, Post::count());
    }

    public function test_on_runs_a_callback_on_a_branch_and_returns(): void
    {
        Dolt::createBranch('feature');

        $count = Dolt::on('feature', function () {
            $this->makePost('Inside');
            Dolt::commit('Inside');

            return Post::count();
        });

        $this->assertSame(1, $count);
        $this->assertSame('main', Dolt::branch());
        $this->assertSame(0, Post::count());
    }

    public function test_on_returns_to_the_branch_when_the_callback_throws(): void
    {
        Dolt::createBranch('feature');

        try {
            Dolt::on('feature', fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('main', Dolt::branch());
    }

    public function test_the_branch_survives_a_reconnect(): void
    {
        Dolt::createBranch('feature');
        Dolt::checkout('feature');
        $this->makePost('On feature');
        Dolt::commit('Add a post');

        $this->connection()->reconnect();

        $this->assertSame(1, Post::count());
        $this->assertSame('feature', $this->connection()->selectOne('select active_branch() as b')->b);
    }

    public function test_a_branch_can_be_set_in_the_connection_config(): void
    {
        Dolt::createBranch('staging');

        config(['database.connections.staging' => ['branch' => 'staging'] + config('database.connections.dolt')]);

        $this->assertSame('staging', Dolt::connection('staging')->branch());
        $this->assertSame('staging', DB::connection('staging')->selectOne('select active_branch() as b')->b);

        DB::disconnect('staging');
    }

    public function test_a_branch_can_be_given_in_the_database_name(): void
    {
        Dolt::createBranch('staging');

        config(['database.connections.staging' => ['database' => $this->databaseName.'/staging'] + config('database.connections.dolt')]);

        $this->assertSame($this->databaseName, DB::connection('staging')->getDatabaseName());
        $this->assertSame('staging', DB::connection('staging')->selectOne('select active_branch() as b')->b);

        DB::disconnect('staging');
    }

    public function test_branches_cannot_be_switched_inside_a_transaction(): void
    {
        Dolt::createBranch('feature');

        DB::beginTransaction();

        try {
            $this->expectException(DoltException::class);

            Dolt::checkout('feature');
        } finally {
            DB::rollBack();
        }
    }

    public function test_session_variables_are_applied_and_cleared(): void
    {
        $connection = $this->connection();

        $connection->setSessionVariables(['dolt_author_name' => 'Grace', 'dolt_transaction_commit' => 1]);

        $this->assertSame('Grace', $this->sessionVariable('dolt_author_name'));
        $this->assertSame(1, (int) $this->sessionVariable('dolt_transaction_commit'));

        $connection->setSessionVariables(['dolt_author_name' => null, 'dolt_transaction_commit' => null]);

        $this->assertSame('', $this->sessionVariable('dolt_author_name'));
        $this->assertSame(0, (int) $this->sessionVariable('dolt_transaction_commit'));
    }

    public function test_writes_are_tracked_until_committed(): void
    {
        $connection = $this->connection();

        $this->assertFalse($connection->hasPendingChanges());

        $this->makePost();

        $this->assertTrue($connection->hasPendingChanges());

        Dolt::commit('Add a post');

        $this->assertFalse($connection->hasPendingChanges());
    }
}
