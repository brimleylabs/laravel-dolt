<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Commits\AutoCommit;
use BrimleyLabs\Dolt\Events\CommitFailed;
use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\Fixtures\PublishPost;
use BrimleyLabs\Dolt\Tests\Fixtures\User;
use BrimleyLabs\Dolt\Tests\TestCase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use RuntimeException;

/**
 * Transaction mode: every commit belongs to the request, job or command whose
 * transaction it was, even when other sessions write to the branch meanwhile.
 */
class ExactAttributionTest extends TestCase
{
    // Laravel only raises its command events in tests when asked to.
    use WithConsoleEvents;

    protected ?string $commitMode = 'transaction';

    protected const FIXTURES = __DIR__.'/../Fixtures/migrations';

    protected function defineWebRoutes($router): void
    {
        $router->middleware('dolt')->group(function () use ($router) {
            $router->post('/posts', fn () => Post::create(['title' => request('title', 'Hello')]));

            $router->post('/two-posts', function () {
                Post::create(['title' => 'One']);
                Post::create(['title' => 'Two']);

                return 'ok';
            });

            $router->post('/two-posts-at-once', function () {
                DB::transaction(function () {
                    Post::create(['title' => 'One']);
                    Post::create(['title' => 'Two']);
                });

                return 'ok';
            });

            $router->post('/relabel', function () {
                Post::create(['title' => 'Before']);

                Dolt::message('Publish the spring sale');

                Post::create(['title' => 'After']);

                return 'ok';
            });

            $router->post('/sign-in/{id}', function (string $id) {
                Post::create(['title' => 'As a guest']);

                Auth::login(User::findOrFail($id));

                Post::create(['title' => 'Signed in']);

                return 'ok';
            });

            $router->post('/second-connection', function () {
                DB::connection('second')->table('posts')->insert(['title' => 'Through the second connection']);

                return 'ok';
            });
        });
    }

    /**
     * @return list<string>
     */
    protected function subjects(int $limit = 5): array
    {
        return Dolt::log(limit: $limit)->map(fn ($commit) => $commit->subject())->all();
    }

    /**
     * @return list<string>
     */
    protected function titlesAt(string $hash): array
    {
        return Dolt::asOf($hash, fn () => Post::orderBy('title')->pluck('title')->all());
    }

    /**
     * A database session of its own, as another PHP process would have.
     */
    protected function otherSession(string $author, string $email, string $message): PDO
    {
        $config = config('database.connections.dolt');

        $pdo = static::onDoltgres()
            ? new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$this->databaseName}", $config['username'], $config['password'])
            : new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$this->databaseName}", $config['username'], $config['password']);

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $statements = $this->connection()->dialect()->setVariables([
            'dolt_transaction_commit' => 1,
            'dolt_transaction_commit_message' => $message,
            'dolt_author_name' => $author,
            'dolt_author_email' => $email,
        ]);

        foreach ($statements as [$sql, $bindings]) {
            $statement = $pdo->prepare($sql);
            $statement->execute($bindings);
            $statement->closeCursor();
        }

        return $pdo;
    }

    public function test_overlapping_writers_each_get_their_own_commit(): void
    {
        $ada = $this->makeUser('Ada Lovelace', 'ada@example.test');
        Dolt::commit('Add a user');

        // Grace's session is in the middle of saving when Ada's request arrives.
        $grace = $this->otherSession('Grace Hopper', 'grace@example.test', 'Grace saves a post');
        $grace->beginTransaction();
        $grace->exec("insert into posts (title) values ('By Grace')");

        $this->actingAs($ada)->post('/posts', ['title' => 'By Ada'])->assertCreated();

        $grace->commit();

        [$graces, $adas] = Dolt::log(limit: 2)->all();

        $this->assertSame('Grace saves a post', $graces->subject());
        $this->assertSame('Grace Hopper', $graces->author->name);
        $this->assertSame('POST /posts', $adas->subject());
        $this->assertSame('Ada Lovelace', $adas->author->name);
        $this->assertSame('ada@example.test', $adas->author->email);

        // Ada's commit holds Ada's row and nothing of Grace's.
        $this->assertSame(['By Ada'], $this->titlesAt($adas->hash));
        $this->assertSame(['By Ada', 'By Grace'], $this->titlesAt($graces->hash));
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_each_write_outside_a_transaction_is_a_commit_of_its_own(): void
    {
        $this->post('/two-posts')->assertOk();

        $this->assertSame(['POST /two-posts', 'POST /two-posts', 'Create tables'], $this->subjects(3));

        $this->post('/two-posts-at-once')->assertOk();

        $this->assertSame(['POST /two-posts-at-once', 'POST /two-posts'], $this->subjects(2));
        $this->assertSame('Test App', Dolt::log(limit: 1)->first()->author->name);
        $this->assertSame(4, Post::count());
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_a_request_reads_as_one_entry_in_the_activity_view(): void
    {
        $ada = $this->makeUser('Ada Lovelace', 'ada@example.test');
        Dolt::commit('Add a user');

        $this->actingAs($ada)->post('/two-posts')->assertOk();
        $this->post('/posts', ['title' => 'By a guest'])->assertCreated();

        [$guest, $twoPosts, $user] = Dolt::activity(limit: 3)->all();

        $this->assertSame('POST /posts', $guest->subject());
        $this->assertSame(1, $guest->count());
        $this->assertSame('POST /two-posts', $twoPosts->subject());
        $this->assertSame(2, $twoPosts->count());
        $this->assertSame('Ada Lovelace', $twoPosts->author()->name);
        $this->assertNotNull($twoPosts->scope());
        $this->assertSame('Add a user', $user->subject());

        // Everything one request changed, as one diff.
        $this->assertSame(2, Dolt::diff($twoPosts->base(), $twoPosts->head()->hash)->table('posts')->rowsAdded);

        // The log itself still lists every commit, with the message as written.
        $this->assertSame(['POST /posts', 'POST /two-posts', 'POST /two-posts'], Dolt::log(limit: 3)->map(fn ($commit) => $commit->message)->all());
    }

    public function test_dolt_log_shows_one_line_per_request_unless_asked_for_every_commit(): void
    {
        $this->post('/two-posts')->assertOk();

        $this->artisan('dolt:log')
            ->expectsOutputToContain('POST /two-posts (2 commits)')
            ->assertExitCode(0);

        $this->artisan('dolt:log', ['--all' => true])
            ->doesntExpectOutputToContain('(2 commits)')
            ->expectsOutputToContain('POST /two-posts')
            ->assertExitCode(0);
    }

    public function test_a_message_names_the_transactions_after_it(): void
    {
        $this->post('/relabel')->assertOk();

        $this->assertSame(['Publish the spring sale', 'POST /relabel', 'Create tables'], $this->subjects(3));
    }

    public function test_signing_in_during_a_request_changes_the_author_of_what_follows(): void
    {
        $grace = $this->makeUser('Grace Hopper', 'grace@example.test');
        Dolt::commit('Add a user');

        $this->post("/sign-in/{$grace->id}")->assertOk();

        $this->assertSame(['Grace Hopper', 'Test App'], Dolt::log(limit: 2)->map(fn ($commit) => $commit->author->name)->all());
    }

    public function test_a_connection_opened_during_a_request_takes_its_author_and_message(): void
    {
        config(['database.connections.second' => config('database.connections.dolt')]);

        $ada = $this->makeUser('Ada Lovelace', 'ada@example.test');
        Dolt::commit('Add a user');

        $this->actingAs($ada)->post('/second-connection')->assertOk();

        $commit = Dolt::log(limit: 1)->first();

        $this->assertSame('POST /second-connection', $commit->subject());
        $this->assertSame('Ada Lovelace', $commit->author->name);
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_the_session_goes_back_to_normal_after_a_request(): void
    {
        $this->post('/posts')->assertCreated();

        $this->assertSame(0, (int) $this->sessionVariable('dolt_transaction_commit'));
        $this->assertSame('', $this->sessionVariable('dolt_author_name'));

        // What is written outside a request waits for an explicit commit.
        $this->makePost('Outside');

        $this->assertTrue(Dolt::isDirty());
    }

    public function test_scopes_nest_and_the_outer_one_comes_back(): void
    {
        $commits = $this->app->make(AutoCommit::class);

        $outer = $commits->begin('Outer');
        $this->makePost('A');

        $inner = $commits->begin('Inner', 'Grace Hopper <grace@example.test>');
        $this->makePost('B');
        $commits->end($inner);

        $this->makePost('C');
        $commits->end($outer);

        $log = Dolt::log(limit: 3);

        $this->assertSame(['Outer', 'Inner', 'Outer'], $log->map(fn ($commit) => $commit->subject())->all());
        $this->assertSame(['Test App', 'Grace Hopper', 'Test App'], $log->map(fn ($commit) => $commit->author->name)->all());
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_migrations_are_committed_under_their_own_names(): void
    {
        $before = Dolt::head();

        $this->artisan('migrate', ['--path' => self::FIXTURES, '--realpath' => true])->assertExitCode(0);

        $commits = Dolt::log("{$before}..HEAD", 100);
        $subjects = $commits->map(fn ($commit) => $commit->subject())->unique()->values()->all();

        $this->assertContains('Run migration 2026_01_01_000000_create_framework_tables', $subjects);
        $this->assertContains('Run migration 2026_01_02_000000_create_articles_table', $subjects);
        $this->assertNotContains('Transaction commit', $subjects);
        $this->assertSame(['Test App'], $commits->map(fn ($commit) => $commit->author->name)->unique()->values()->all());
        $this->assertFalse(Dolt::isDirty());

        // Laravel's record of each migration travels with that migration's name.
        $this->assertSame(
            'Run migration 2026_01_02_000000_create_articles_table',
            $commits->first(fn ($commit) => in_array('migrations', Dolt::diff("{$commit->hash}~1", $commit->hash)->tables()->pluck('table')->all(), true))->subject(),
        );
    }

    public function test_a_command_is_committed_under_its_name(): void
    {
        Artisan::command('posts:import', function () {
            Post::create(['title' => 'Imported']);
        });

        $this->artisan('posts:import')->assertExitCode(0);

        $commit = Dolt::log(limit: 1)->first();

        $this->assertSame('artisan posts:import', $commit->subject());
        $this->assertSame('Test App', $commit->author->name);
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_a_queued_job_is_committed_under_its_name(): void
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

        PublishPost::dispatch('Queued');

        $this->artisan('queue:work', ['--once' => true]);

        $commit = Dolt::log(limit: 1)->first();

        $this->assertSame('Job PublishPost', $commit->subject());
        $this->assertSame('Test App', $commit->author->name);
        $this->assertSame(['Queued'], Post::pluck('title')->all());
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_a_workspace_run_is_committed_as_it_goes(): void
    {
        $workspace = Dolt::workspaces()->open('Spring Sale');

        $workspace->run(fn () => $this->makePost('In the workspace'), author: 'Grace Hopper <grace@example.test>');

        $commits = $workspace->commits();

        $this->assertSame(['Changes in Spring Sale', 'Open workspace: Spring Sale'], $commits->map(fn ($commit) => $commit->subject())->all());
        $this->assertSame('Grace Hopper', $commits->first()->author->name);
        $this->assertSame(0, Post::count());
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_version_control_calls_make_only_the_commits_they_promise(): void
    {
        $commits = $this->app->make(AutoCommit::class);
        $scope = $commits->begin('Busy request');

        try {
            $start = Dolt::head();

            $this->makePost('Written');
            Dolt::reset($start, force: true);

            $this->assertSame($start, Dolt::head());
            $this->assertSame(0, Post::count());

            Dolt::createBranch('feature');
            Dolt::on('feature', fn () => $this->makePost('On feature'));

            $result = Dolt::merge('feature', 'Squash the feature', 'Ann Example <ann@example.test>', squash: true);

            $head = Dolt::log(limit: 1)->first();

            $this->assertSame($result->hash, $head->hash);
            $this->assertSame('Squash the feature', $head->subject());
            $this->assertSame('Ann Example', $head->author->name);
            $this->assertSame($start, Dolt::log(limit: 2)->last()->hash);

            Dolt::revert('HEAD', 'Ann Example <ann@example.test>');

            // The squash and the revert, and nothing in between.
            $this->assertSame(2, Dolt::log("{$start}..HEAD", 10)->count());
            $this->assertSame(0, Post::count());
            $this->assertFalse(Dolt::isDirty());
        } finally {
            $commits->end($scope);
        }
    }

    public function test_a_commit_that_fails_is_tried_again_then_reported(): void
    {
        config(['dolt.commit.mode' => 'request']);

        Event::fake([CommitFailed::class]);

        $calls = 0;

        Dolt::resolveAuthorUsing(function () use (&$calls) {
            $calls++;

            throw new RuntimeException('The directory is down.');
        });

        $this->makePost('Waiting');

        $this->assertSame([], $this->app->make(AutoCommit::class)->flush('Save the post'));

        $this->assertSame(2, $calls);
        $this->assertTrue(Dolt::isDirty());

        Event::assertDispatched(CommitFailed::class, fn (CommitFailed $event) => $event->message === 'Save the post'
            && $event->connection === 'dolt'
            && $event->branch === 'main'
            && $event->exception->getMessage() === 'The directory is down.');
    }
}
