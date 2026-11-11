<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Testing\RefreshDoltDatabase;
use BrimleyLabs\Dolt\Testing\RefreshDoltDatabaseState;
use BrimleyLabs\Dolt\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RefreshDoltDatabaseTest extends TestCase
{
    use RefreshDoltDatabase;

    protected function migrateFreshUsing(): array
    {
        return ['--path' => __DIR__.'/../Fixtures/migrations', '--realpath' => true];
    }

    protected function baseline(): array
    {
        return RefreshDoltDatabaseState::$baselines['dolt:'.$this->databaseName];
    }

    public function test_the_database_is_migrated_and_the_starting_point_remembered(): void
    {
        $baseline = $this->baseline();

        $this->assertTrue(Schema::hasTable('articles'));
        $this->assertTrue(Schema::hasTable('sessions'));
        $this->assertSame(['main'], array_keys($baseline['branches']));
        $this->assertSame($baseline['hash'], Dolt::log(limit: 2)->last()->hash);
    }

    public function test_everything_a_test_did_is_put_back(): void
    {
        $baseline = $this->baseline();

        // Committed data, a schema change, a branch, a tag, pending work and a session row.
        DB::table('articles')->insert(['title' => 'Committed']);
        Dolt::commit('Add an article');
        Dolt::tag('v1');

        $workspace = Dolt::workspaces()->open('Spring Sale');
        $workspace->run(fn () => DB::table('articles')->insert(['title' => 'Draft']), 'Draft an article');

        DB::table('articles')->insert(['title' => 'Pending']);
        DB::table('sessions')->insert(['id' => 'abc', 'payload' => 'x', 'last_activity' => 1]);

        Dolt::checkout($workspace->branch);
        DB::beginTransaction();

        $this->refreshDoltDatabase();

        $this->assertSame('main', Dolt::branch());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($baseline['hash'], Dolt::head());
        $this->assertFalse(Dolt::isDirty());
        $this->assertSame(0, DB::table('articles')->count());
        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertFalse(Schema::hasTable('posts'));
        $this->assertSame(['main'], Dolt::branches()->pluck('name')->all());
        $this->assertSame([], Dolt::tags()->all());
        $this->assertTrue(Schema::hasTable('sessions'));
        $this->assertContains('sessions', Dolt::ignored()->all());
    }

    public function test_a_database_is_only_migrated_once(): void
    {
        $migrations = DB::table('migrations')->count();
        $baseline = $this->baseline();

        $this->refreshDoltDatabase();
        $this->refreshDoltDatabase();

        $this->assertSame($baseline, $this->baseline());
        $this->assertSame($migrations, DB::table('migrations')->count());
    }
}
