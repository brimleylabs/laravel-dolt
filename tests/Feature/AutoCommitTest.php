<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Listeners\CommitMigrations;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\Fixtures\PublishPost;
use BrimleyLabs\Dolt\Tests\TestCase;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * One commit per job, command and migration run. Transaction mode is covered by ExactAttributionTest.
 */
class AutoCommitTest extends TestCase
{
    // Laravel only raises its command events in tests when asked to.
    use WithConsoleEvents;

    protected ?string $commitMode = 'request';

    protected const FIXTURES = __DIR__.'/../Fixtures/migrations';

    protected const FRAMEWORK = self::FIXTURES.'/2026_01_01_000000_create_framework_tables.php';

    protected const ARTICLES = self::FIXTURES.'/2026_01_02_000000_create_articles_table.php';

    protected function withoutConsoleCommits($app): void
    {
        $app['config']->set('dolt.commit.console', false);
    }

    protected function useDatabaseQueue(): void
    {
        $this->artisan('migrate', ['--path' => self::FIXTURES, '--realpath' => true])->assertExitCode(0);

        config([
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'connection' => 'dolt',
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
                'after_commit' => false,
            ],
            'queue.failed.driver' => 'null',
        ]);
    }

    protected function subjects(int $limit = 5): array
    {
        return Dolt::log(limit: $limit)->map(fn ($commit) => $commit->subject())->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    */

    public function test_a_migration_run_becomes_one_commit_that_names_the_migrations(): void
    {
        $this->artisan('migrate', ['--path' => self::FIXTURES, '--realpath' => true])->assertExitCode(0);

        $commit = Dolt::log(limit: 1)->first();

        $this->assertSame('Run 2 migrations', $commit->subject());
        $this->assertStringContainsString('2026_01_01_000000_create_framework_tables', $commit->message);
        $this->assertStringContainsString('2026_01_02_000000_create_articles_table', $commit->message);
        $this->assertSame('Test App', $commit->author->name);
        $this->assertSame('system@app.example.test', $commit->author->email);
        $this->assertFalse(Dolt::isDirty());

        // The schema change and Laravel's own bookkeeping travel together.
        $tables = Dolt::diff('HEAD~1', 'HEAD')->tables()->pluck('table')->all();

        $this->assertContains('articles', $tables);
        $this->assertContains('migrations', $tables);
    }

    public function test_a_single_migration_is_named_in_the_subject(): void
    {
        $this->artisan('migrate', ['--path' => self::ARTICLES, '--realpath' => true])->assertExitCode(0);

        $this->assertSame('Run migration 2026_01_02_000000_create_articles_table', $this->subjects(1)[0]);
    }

    public function test_a_rollback_is_committed_too(): void
    {
        $this->artisan('migrate', ['--path' => self::ARTICLES, '--realpath' => true])->assertExitCode(0);
        $this->artisan('migrate:rollback', ['--path' => self::ARTICLES, '--realpath' => true])->assertExitCode(0);

        $this->assertSame('Roll back migration 2026_01_02_000000_create_articles_table', $this->subjects(1)[0]);
        $this->assertFalse(Schema::hasTable('articles'));
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_a_migration_can_be_undone_by_reverting_its_commit(): void
    {
        $this->artisan('migrate', ['--path' => self::FRAMEWORK, '--realpath' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--path' => self::ARTICLES, '--realpath' => true])->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('articles'));
        $this->assertSame(2, DB::table('migrations')->count());

        Dolt::revert('HEAD');

        // The table and Laravel's record of the migration go back together.
        $this->assertFalse(Schema::hasTable('articles'));
        $this->assertSame(1, DB::table('migrations')->count());
    }

    public function test_a_migration_is_named_after_its_file_when_laravel_does_not_say(): void
    {
        // Before Laravel 13 the event carries the migration, which is an anonymous class, and no name.
        $listener = $this->app->make(CommitMigrations::class);

        $listener->migrated(new MigrationEnded(require self::ARTICLES, 'up'));

        Post::create(['title' => 'Something to commit']);

        $listener->finished(new MigrationsEnded('up'));

        $this->assertSame('Run migration 2026_01_02_000000_create_articles_table', $this->subjects(1)[0]);
    }

    public function test_a_migration_can_run_on_a_branch_and_arrive_with_a_merge(): void
    {
        $this->artisan('migrate', ['--path' => self::FRAMEWORK, '--realpath' => true])->assertExitCode(0);

        Dolt::createBranch('add-articles');

        Dolt::on('add-articles', function () {
            Artisan::call('migrate', ['--path' => self::ARTICLES, '--realpath' => true]);

            $this->assertTrue(Schema::hasTable('articles'));
            $this->assertSame(2, DB::table('migrations')->count());
        });

        // Live data has neither the table nor Laravel's record of the migration.
        $this->assertFalse(Schema::hasTable('articles'));
        $this->assertSame(1, DB::table('migrations')->count());
        $this->assertStringContainsString('create table', strtolower(Dolt::diff('main', 'add-articles')->patch()));

        Dolt::merge('add-articles');

        $this->assertTrue(Schema::hasTable('articles'));
        $this->assertSame(2, DB::table('migrations')->count());

        $before = Dolt::head();

        $this->artisan('migrate', ['--path' => self::ARTICLES, '--realpath' => true])->assertExitCode(0);

        $this->assertSame($before, Dolt::head());
    }

    public function test_running_with_nothing_to_migrate_adds_no_commit(): void
    {
        $this->artisan('migrate', ['--path' => self::ARTICLES, '--realpath' => true])->assertExitCode(0);

        $before = Dolt::head();

        $this->artisan('migrate', ['--path' => self::ARTICLES, '--realpath' => true])->assertExitCode(0);

        $this->assertSame($before, Dolt::head());
    }

    /*
    |--------------------------------------------------------------------------
    | Artisan commands
    |--------------------------------------------------------------------------
    */

    public function test_what_a_command_writes_is_committed_under_its_name(): void
    {
        Artisan::command('posts:import', function () {
            Post::create(['title' => 'Imported']);
        });

        $this->artisan('posts:import')->assertExitCode(0);

        $commit = Dolt::log(limit: 1)->first();

        $this->assertSame('artisan posts:import', $commit->subject());
        $this->assertSame('Test App', $commit->author->name);
        $this->assertFalse(Dolt::isDirty());
        $this->assertSame(1, Post::count());
    }

    public function test_a_command_can_describe_its_own_commit(): void
    {
        Artisan::command('posts:import', function () {
            Post::create(['title' => 'Imported']);

            Dolt::message('Import the spring catalogue');
        });

        $this->artisan('posts:import')->assertExitCode(0);

        $this->assertSame('Import the spring catalogue', $this->subjects(1)[0]);
    }

    public function test_a_command_run_by_another_command_joins_its_commit(): void
    {
        Artisan::command('posts:tidy', function () {
            Post::create(['title' => 'Tidied']);
        });

        Artisan::command('posts:import', function () {
            Post::create(['title' => 'Imported']);

            Artisan::call('posts:tidy');

            Post::create(['title' => 'Imported after tidying']);
        });

        $before = Dolt::log()->count();

        $this->artisan('posts:import')->assertExitCode(0);

        $this->assertSame($before + 1, Dolt::log()->count());
        $this->assertSame('artisan posts:import', $this->subjects(1)[0]);
        $this->assertSame(3, Post::count());
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_a_command_that_throws_inside_another_does_not_stop_the_commit(): void
    {
        Artisan::command('posts:explode', function () {
            throw new \RuntimeException('No.');
        });

        Artisan::command('posts:import', function () {
            try {
                Artisan::call('posts:explode');
            } catch (\RuntimeException) {
                //
            }

            Post::create(['title' => 'Imported']);
        });

        $this->artisan('posts:import')->assertExitCode(0);

        $this->assertSame('artisan posts:import', $this->subjects(1)[0]);
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_a_command_that_only_reads_adds_no_commit(): void
    {
        Artisan::command('posts:count', function () {
            $this->line((string) Post::count());
        });

        $before = Dolt::head();

        $this->artisan('posts:count')->assertExitCode(0);

        $this->assertSame($before, Dolt::head());
    }

    #[DefineEnvironment('withoutConsoleCommits')]
    public function test_console_commits_can_be_switched_off(): void
    {
        Artisan::command('posts:import', function () {
            Post::create(['title' => 'Imported']);
        });

        $before = Dolt::head();

        $this->artisan('posts:import')->assertExitCode(0);

        $this->assertSame($before, Dolt::head());
        $this->assertTrue(Dolt::isDirty());
    }

    /*
    |--------------------------------------------------------------------------
    | Queued jobs
    |--------------------------------------------------------------------------
    */

    public function test_every_queued_job_gets_its_own_commit(): void
    {
        $this->useDatabaseQueue();

        PublishPost::dispatch('First');
        PublishPost::dispatch('Second');

        $before = Dolt::head();

        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertFalse(Dolt::isDirty());

        $this->artisan('queue:work', ['--once' => true]);
        $this->artisan('queue:work', ['--once' => true]);

        $this->assertSame(['Job PublishPost', 'Job PublishPost'], array_slice($this->subjects(), 0, 2));
        $this->assertSame($before, Dolt::log(limit: 3)->last()->hash);
        $this->assertSame(['First', 'Second'], Post::orderBy('id')->pluck('title')->all());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_a_job_can_describe_its_own_commit(): void
    {
        $this->useDatabaseQueue();

        PublishPost::dispatch('Sale', 'Publish the spring sale');

        $this->artisan('queue:work', ['--once' => true]);

        $this->assertSame('Publish the spring sale', $this->subjects(1)[0]);
    }

    public function test_what_a_failed_job_wrote_is_committed_and_marked(): void
    {
        $this->useDatabaseQueue();

        PublishPost::dispatch('Half done', null, true);

        $this->artisan('queue:work', ['--once' => true]);

        $this->assertSame('Job PublishPost (failed)', $this->subjects(1)[0]);
        $this->assertSame(1, Post::count());
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_a_job_run_inline_belongs_to_whatever_dispatched_it(): void
    {
        $before = Dolt::head();

        PublishPost::dispatch('Inline');

        $this->assertSame(1, Post::count());
        $this->assertSame($before, Dolt::head());
        $this->assertTrue(Dolt::isDirty());

        $commit = Dolt::commit('Publish a post from the request');

        $this->assertSame(['posts'], Dolt::diff('HEAD~1', 'HEAD')->tables()->pluck('table')->all());
        $this->assertSame('Publish a post from the request', $commit->subject());
    }

    public function test_a_worker_starts_each_job_on_the_home_branch(): void
    {
        $this->useDatabaseQueue();

        Dolt::createBranch('staging');

        PublishPost::dispatch('Queued');

        Dolt::checkout('staging');

        $this->artisan('queue:work', ['--once' => true]);

        $this->assertSame('main', Dolt::branch());
        $this->assertSame(1, Post::count());
        $this->assertSame(0, Dolt::on('staging', fn () => Post::count()));
    }
}
