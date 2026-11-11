<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Exceptions\InvalidRefException;
use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IgnoreTest extends TestCase
{
    protected function defineWebRoutes($router): void
    {
        $router->middleware('dolt')->group(function () use ($router) {
            $router->get('/visit', function () {
                session(['seen' => true]);

                return 'ok';
            });

            $router->post('/posts', function () {
                session(['wrote' => true]);

                return response()->json(Post::create(['title' => 'Hello']), 201);
            });
        });
    }

    protected function migrateFixtures(): void
    {
        $this->artisan('migrate', ['--path' => __DIR__.'/../Fixtures/migrations', '--realpath' => true])->assertExitCode(0);
    }

    protected function createJobsTable(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue');
            $table->text('payload');
        });
    }

    public function test_a_new_database_never_versions_the_framework_tables(): void
    {
        $this->migrateFixtures();

        $this->assertTrue(Schema::hasTable('sessions'));
        $this->assertTrue(Schema::hasTable('articles'));
        $this->assertFalse(Dolt::isDirty());

        foreach (['sessions', 'cache', 'jobs', 'telescope_%'] as $pattern) {
            $this->assertContains($pattern, Dolt::ignored()->all());
        }

        $this->assertSame([], Dolt::versionedMatching());

        $log = Dolt::log();
        $kept = $log->first(fn ($commit) => $commit->subject() === 'Keep tables out of version control');

        $this->assertNotNull($kept);

        if (config('dolt.commit.mode') === 'request') {
            $this->assertSame(['Run 2 migrations', 'Keep tables out of version control'], $log->take(2)->map(fn ($commit) => $commit->subject())->all());
        } else {
            $this->assertSame('Run migration 2026_01_02_000000_create_articles_table', $log->first()->subject());
        }

        // Only the tables that hold real data made it into history.
        $created = Dolt::diff($kept->hash, 'HEAD')->tables()->pluck('table')->all();

        $this->assertContains('articles', $created);
        $this->assertNotContains('sessions', $created);
        $this->assertNotContains('jobs', $created);
    }

    public function test_writes_to_ignored_tables_never_reach_history(): void
    {
        $this->migrateFixtures();

        $before = Dolt::head();

        DB::table('sessions')->insert(['id' => 'abc', 'payload' => 'x', 'last_activity' => 1]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => 1, 'created_at' => 1]);

        $this->assertFalse(Dolt::isDirty());
        $this->assertNull(Dolt::commit('Nothing worth recording'));
        $this->assertSame($before, Dolt::head());
        $this->assertSame(1, DB::table('sessions')->count());
    }

    public function test_ignored_tables_are_shared_with_every_workspace(): void
    {
        $this->migrateFixtures();

        DB::table('sessions')->insert(['id' => 'live', 'payload' => 'x', 'last_activity' => 1]);

        $workspace = Dolt::workspaces()->open('Spring Sale');

        $seen = $workspace->run(function () {
            DB::table('sessions')->insert(['id' => 'from-workspace', 'payload' => 'y', 'last_activity' => 2]);
            Post::create(['title' => 'Draft']);

            return DB::table('sessions')->orderBy('id')->pluck('id')->all();
        }, 'Draft a post');

        $this->assertSame(['from-workspace', 'live'], $seen);
        $this->assertSame(['from-workspace', 'live'], DB::table('sessions')->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, Post::count());
        $this->assertSame(['posts'], $workspace->changes()->tables()->pluck('table')->all());

        $workspace->publish();

        $this->assertSame(1, Post::count());
        $this->assertSame(2, DB::table('sessions')->count());
    }

    public function test_the_second_run_changes_nothing(): void
    {
        $this->migrateFixtures();

        $before = Dolt::head();
        $result = Dolt::ignore();

        $this->assertFalse($result->changed());
        $this->assertNull($result->commit);
        $this->assertSame($before, Dolt::head());
    }

    public function test_tables_that_are_already_versioned_are_left_alone_until_asked(): void
    {
        $this->createJobsTable();
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}']);
        Dolt::commit('Create jobs');

        $result = Dolt::ignore('jobs', untrack: false);

        $this->assertFalse($result->changed());
        $this->assertSame(['jobs'], $result->skipped);
        $this->assertSame(['jobs'], Dolt::versionedMatching('jobs'));
        $this->assertNotContains('jobs', Dolt::ignored()->all());
    }

    public function test_a_versioned_table_can_be_released_and_keeps_its_rows(): void
    {
        $this->createJobsTable();
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}']);
        Dolt::commit('Create jobs');

        $result = Dolt::ignore('jobs', author: 'Ada Lovelace <ada@example.test>');

        $this->assertTrue($result->changed());
        $this->assertSame(['jobs'], $result->added);
        $this->assertSame(['jobs'], $result->released);
        $this->assertSame('Ada Lovelace', $result->commit->author->name);
        $this->assertSame('Keep tables out of version control', strtok($result->commit->message, "\n"));

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertFalse(Dolt::isDirty());
        $this->assertSame([], Dolt::versionedMatching('jobs'));

        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}']);

        $this->assertFalse(Dolt::isDirty());
        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_pending_work_is_not_swept_into_the_commit(): void
    {
        $this->makePost('Not committed yet');

        $result = Dolt::ignore('audit_scratch');

        $this->assertNotNull($result->commit);
        $this->assertSame(['posts'], Dolt::status()->pluck('table')->all());
        $this->assertSame(0, Dolt::asOf('HEAD', fn () => Post::count()));
    }

    public function test_a_workspace_opened_before_the_change_publishes_after_a_sync(): void
    {
        $this->createJobsTable();
        Dolt::commit('Create jobs');

        $workspace = Dolt::workspaces()->open('Spring Sale');
        $workspace->run(fn () => Post::create(['title' => 'Draft']), 'Draft a post');

        Dolt::ignore('jobs');
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}']);

        try {
            $workspace->publish(keep: true);
            $this->fail('Publishing should ask for a sync first.');
        } catch (DoltException $e) {
            $this->assertStringContainsString('sync', $e->getMessage());
        }

        $this->assertSame('main', Dolt::branch());
        $this->assertSame(0, Post::count());

        $workspace->sync();
        $workspace->publish();

        $this->assertSame(1, Post::count());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_the_list_is_always_kept_on_the_default_branch(): void
    {
        Dolt::createBranch('staging');
        Dolt::checkout('staging');

        Dolt::ignore('scratch_%');

        $this->assertSame('staging', Dolt::branch());

        Dolt::checkout('main');

        $this->assertContains('scratch_%', Dolt::ignored()->all());
    }

    public function test_patterns_are_checked(): void
    {
        $this->expectException(InvalidRefException::class);

        Dolt::ignore("jobs'; drop table users; --");
    }

    public function test_an_empty_list_switches_the_feature_off(): void
    {
        config(['dolt.ignore' => []]);

        $before = Dolt::head();

        $this->migrateFixtures();

        $this->assertSame([], Dolt::ignored()->all());
        $this->assertContains('sessions', Dolt::diff($before, 'HEAD')->tables()->pluck('table')->all());
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_cascading_foreign_keys_between_shared_tables_are_left_out(): void
    {
        $this->migrateFixtures();

        // The shape of the Telescope migration, which Dolt would otherwise refuse.
        Schema::create('telescope_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
        });

        Schema::create('telescope_entries_tags', function (Blueprint $table) {
            $table->uuid('entry_uuid');
            $table->string('tag');
            $table->foreign('entry_uuid')->references('uuid')->on('telescope_entries')->onDelete('cascade');
        });

        $this->assertSame([], Schema::getForeignKeys('telescope_entries_tags'));
        $this->assertFalse(Dolt::isDirty());

        // Versioned tables keep their cascade rules.
        Schema::create('authors', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained()->cascadeOnDelete();
        });

        $this->assertSame('cascade', Schema::getForeignKeys('books')[0]['on_delete']);
    }

    public function test_database_sessions_do_not_add_commits(): void
    {
        $this->migrateFixtures();

        config(['session.driver' => 'database', 'session.connection' => 'dolt']);

        $before = Dolt::head();

        $this->get('/visit')->assertOk();

        $this->assertSame($before, Dolt::head());
        $this->assertSame(1, DB::table('sessions')->count());
        $this->assertFalse(Dolt::isDirty());

        $this->post('/posts')->assertCreated();

        $commit = Dolt::log(limit: 1)->first();

        $this->assertNotSame($before, $commit->hash);
        $this->assertSame(['posts'], Dolt::diff('HEAD~1', 'HEAD')->tables()->pluck('table')->all());
    }

    public function test_the_command_releases_tables_and_reports_what_it_did(): void
    {
        $this->createJobsTable();
        Dolt::commit('Create jobs');

        $this->artisan('dolt:doctor')
            ->expectsOutputToContain('php artisan dolt:ignore')
            ->assertExitCode(0);

        $this->artisan('dolt:ignore')
            ->expectsOutputToContain('Released from version control, rows kept: jobs.')
            ->assertExitCode(0);

        $this->assertSame([], Dolt::versionedMatching());

        $this->artisan('dolt:ignore')
            ->expectsOutputToContain('Nothing to change.')
            ->assertExitCode(0);

        $this->artisan('dolt:ignore', ['--list' => true])
            ->expectsOutputToContain('sessions')
            ->assertExitCode(0);
    }

    /**
     * A table numbered from the clock whose next number is known: the sequence stands in for an unlucky clock.
     */
    protected function createClashingTable(): void
    {
        DB::statement('create sequence scratch_draws');
        DB::statement('create table scratch_rows (id bigint primary key default (nextval(\'scratch_draws\') + (extract(epoch from clock_timestamp()) * 0)::bigint), note text)');
        DB::table('scratch_rows')->insert(['id' => 1, 'note' => 'Taken']);
    }

    public function test_a_row_that_draws_a_taken_number_is_inserted_again_on_doltgres(): void
    {
        $this->onlyOnDoltgres('Dolt numbers shared rows with its own counter.');

        $this->createClashingTable();

        // The first draw is 1, which is taken. The second is 2.
        $id = DB::table('scratch_rows')->insertGetId(['note' => 'Second try']);

        $this->assertSame(2, $id);
        $this->assertSame(['Taken', 'Second try'], DB::table('scratch_rows')->orderBy('id')->pluck('note')->all());
    }

    public function test_a_clash_inside_a_transaction_is_not_hidden_on_doltgres(): void
    {
        $this->onlyOnDoltgres('Dolt numbers shared rows with its own counter.');

        $this->createClashingTable();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn () => DB::table('scratch_rows')->insert(['note' => 'Inside a transaction']));
    }

    public function test_a_number_the_application_chose_twice_still_fails_on_doltgres(): void
    {
        $this->onlyOnDoltgres('Dolt numbers shared rows with its own counter.');

        $this->createClashingTable();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('scratch_rows')->insert(['id' => 1, 'note' => 'Same number again']);
    }
}
