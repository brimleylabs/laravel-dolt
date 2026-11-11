<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Http\Middleware\HandleDoltRequests;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

class ConsoleTest extends TestCase
{
    /**
     * @return array{int, string}
     */
    protected function dolt(string $command, array $parameters = []): array
    {
        $code = Artisan::call("dolt:{$command}", $parameters);

        return [$code, Artisan::output()];
    }

    protected function conflictingBranch(): void
    {
        $post = $this->makePost('Original');
        Dolt::commit('Add a post');

        Dolt::createBranch('feature');

        Dolt::on('feature', function () use ($post) {
            Post::whereKey($post->id)->update(['title' => 'Theirs']);
            Dolt::commit('Rename on feature');
        });

        Post::whereKey($post->id)->update(['title' => 'Ours']);
        Dolt::commit('Rename on main');
    }

    public function test_status_shows_the_branch_and_pending_changes(): void
    {
        [$code, $output] = $this->dolt('status');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('main', $output);
        $this->assertStringContainsString('Nothing to commit.', $output);

        $this->makePost();

        [$code, $output] = $this->dolt('status');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('posts', $output);
        $this->assertStringNotContainsString('Nothing to commit.', $output);
        $this->assertTrue(Dolt::isDirty());
    }

    public function test_log_lists_commits_newest_first(): void
    {
        $this->makePost();
        Dolt::commit('Add a post', 'Ada Lovelace <ada@example.test>');

        [$code, $output] = $this->dolt('log');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Add a post', $output);
        $this->assertStringContainsString('Ada Lovelace', $output);
        $this->assertStringContainsString('Create tables', $output);
        $this->assertLessThan(strpos($output, 'Create tables'), strpos($output, 'Add a post'));

        [, $output] = $this->dolt('log', ['--limit' => 1]);

        $this->assertStringNotContainsString('Create tables', $output);
    }

    public function test_commit_records_pending_changes(): void
    {
        $this->makePost();

        [$code, $output] = $this->dolt('commit', ['message' => 'Add a post', '--author' => 'Ada Lovelace <ada@example.test>']);

        $commit = Dolt::log(limit: 1)->first();

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Committed {$commit->shortHash()} on main.", $output);
        $this->assertSame('Add a post', $commit->subject());
        $this->assertSame('Ada Lovelace', $commit->author->name);

        [, $output] = $this->dolt('commit', ['message' => 'Again']);

        $this->assertStringContainsString('Nothing to commit.', $output);
        $this->assertSame($commit->hash, Dolt::head());

        [, $output] = $this->dolt('commit', ['message' => 'Mark the release', '--allow-empty' => true]);

        $this->assertStringContainsString('Committed', $output);
        $this->assertSame('Test App', Dolt::log(limit: 1)->first()->author->name);
    }

    public function test_branch_lists_creates_and_deletes(): void
    {
        [$code, $output] = $this->dolt('branch', ['name' => 'staging']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Created branch staging at '.substr(Dolt::head(), 0, 8).'.', $output);
        $this->assertTrue(Dolt::branchExists('staging'));

        [, $output] = $this->dolt('branch');

        $this->assertStringContainsString('main', $output);
        $this->assertStringContainsString('staging', $output);
        $this->assertStringContainsString('*', $output);

        [, $output] = $this->dolt('branch', ['name' => 'staging', '--delete' => true]);

        $this->assertStringContainsString('Deleted branch staging.', $output);
        $this->assertFalse(Dolt::branchExists('staging'));
    }

    public function test_a_branch_with_unmerged_work_is_only_deleted_with_force(): void
    {
        Dolt::createBranch('feature');

        Dolt::on('feature', function () {
            Post::create(['title' => 'Only on the branch']);
            Dolt::commit('Add a post on feature');
        });

        [$code, $output] = $this->dolt('branch', ['name' => 'feature', '--delete' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('[feature] holds work that is not merged.', $output);
        $this->assertTrue(Dolt::branchExists('feature'));

        [$code] = $this->dolt('branch', ['name' => 'feature', '--delete' => true, '--force' => true]);

        $this->assertSame(0, $code);
        $this->assertFalse(Dolt::branchExists('feature'));
    }

    public function test_commands_take_the_short_hashes_that_log_prints(): void
    {
        $this->makePost('Hello world');
        $commit = Dolt::commit('Add a post');

        $short = substr($commit->hash, 0, 8);

        [, $output] = $this->dolt('log');

        $this->assertStringContainsString($short, $output);

        [$code, $output] = $this->dolt('diff', ['from' => $short.'~1', 'to' => $short, '--table' => 'posts']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Hello world', $output);

        [$code] = $this->dolt('branch', ['name' => 'before', '--from' => $short.'~1']);

        $this->assertSame(0, $code);
        $this->assertSame($commit->parents[0], Dolt::findBranch('before')->hash);
    }

    public function test_a_branch_can_start_from_an_earlier_commit(): void
    {
        $start = Dolt::head();

        $this->makePost();
        Dolt::commit('Add a post');

        $this->dolt('branch', ['name' => 'before', '--from' => $start]);

        $this->assertSame($start, Dolt::findBranch('before')->hash);
    }

    public function test_diff_shows_tables_and_rows(): void
    {
        [, $output] = $this->dolt('diff');

        $this->assertStringContainsString('No changes.', $output);

        $this->makePost('Hello world');
        Dolt::commit('Add a post');

        [$code, $output] = $this->dolt('diff', ['from' => 'HEAD~1', 'to' => 'HEAD']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('posts', $output);

        [, $output] = $this->dolt('diff', ['from' => 'HEAD~1', 'to' => 'HEAD', '--table' => 'posts']);

        $this->assertStringContainsString('added', $output);
        $this->assertStringContainsString('title', $output);
        $this->assertStringContainsString('Hello world', $output);
    }

    public function test_merge_brings_a_branch_in(): void
    {
        Dolt::createBranch('feature');

        Dolt::on('feature', function () {
            Post::create(['title' => 'From the branch']);
            Dolt::commit('Add a post on feature');
        });

        [$code, $output] = $this->dolt('merge', ['source' => 'feature', '--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('feature merges into main without conflicts.', $output);
        $this->assertSame(0, Post::count());

        [$code, $output] = $this->dolt('merge', ['source' => 'feature', '--message' => 'Bring in the feature']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Merged feature into main as '.substr(Dolt::head(), 0, 8).'.', $output);
        $this->assertSame(1, Post::count());
        $this->assertSame('Bring in the feature', Dolt::log(limit: 1)->first()->subject());

        [, $output] = $this->dolt('merge', ['source' => 'feature']);

        $this->assertStringContainsString('main already has everything in feature.', $output);
    }

    public function test_merge_reports_conflicts_and_can_settle_them(): void
    {
        $this->conflictingBranch();

        $before = Dolt::head();

        [$code, $output] = $this->dolt('merge', ['source' => 'feature', '--dry-run' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('posts', $output);

        [$code, $output] = $this->dolt('merge', ['source' => 'feature']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('feature conflicts with main. Nothing was merged.', $output);
        $this->assertSame($before, Dolt::head());
        $this->assertSame('Ours', Post::first()->title);

        [$code] = $this->dolt('merge', ['source' => 'feature', '--theirs' => true]);

        $this->assertSame(0, $code);
        $this->assertSame('Theirs', Post::first()->title);
    }

    public function test_workspaces_are_listed_with_what_they_would_change(): void
    {
        [, $output] = $this->dolt('workspaces');

        $this->assertStringContainsString('No open workspaces.', $output);

        $workspace = Dolt::workspaces()->open('Spring Sale', owner: 'Ada Lovelace <ada@example.test>');
        $workspace->run(fn () => Post::create(['title' => 'Draft']), 'Draft a post');

        [$code, $output] = $this->dolt('workspaces');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('spring-sale', $output);
        $this->assertStringContainsString('Spring Sale', $output);
        $this->assertStringContainsString('Ada Lovelace', $output);
        $this->assertStringContainsString('yes', $output);

        [, $output] = $this->dolt('workspaces', ['--previews' => true]);

        $this->assertStringContainsString('No open previews.', $output);
    }

    public function test_prune_discards_old_previews_only(): void
    {
        Dolt::preview(fn () => Post::create(['title' => 'Proposed']), 'A proposal');

        [$code, $output] = $this->dolt('prune');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Discarded 0 previews.', $output);
        $this->assertCount(1, Dolt::previews()->all());

        [$code, $output] = $this->dolt('prune', ['--hours' => 0]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('greater than zero', $output);
    }

    public function test_doctor_reports_the_setup(): void
    {
        [$code, $output] = $this->dolt('doctor');

        $this->assertSame(0, $code);
        $this->assertStringContainsString($this->databaseName, $output);
        $this->assertStringContainsString(Dolt::version(), $output);
        $this->assertStringContainsString(config('dolt.commit.mode') === 'request' ? 'one per request' : 'one per transaction', $output);
        $this->assertStringContainsString('Test App <system@app.example.test>', $output);
        $this->assertStringContainsString('No route uses the "dolt" middleware yet.', $output);
    }

    public function test_doctor_sees_the_middleware_in_a_route_group(): void
    {
        $this->app->make(HttpKernel::class)->appendMiddlewareToGroup('web', 'dolt');

        Route::middleware('web')->get('/posts', fn () => 'ok');

        [, $output] = $this->dolt('doctor');

        $this->assertStringNotContainsString('No route uses the "dolt" middleware yet.', $output);
    }

    public function test_doctor_sees_the_middleware_on_every_request(): void
    {
        $this->app->make(HttpKernel::class)->pushMiddleware(HandleDoltRequests::class);

        [, $output] = $this->dolt('doctor');

        $this->assertStringNotContainsString('No route uses the "dolt" middleware yet.', $output);
    }

    public function test_doctor_explains_a_connection_that_is_not_dolt(): void
    {
        config(['database.connections.plain' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        [$code, $output] = $this->dolt('doctor', ['--database' => 'plain']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('DB_CONNECTION=dolt', $output);
    }

    public function test_install_publishes_the_config_and_runs_the_checks(): void
    {
        $path = config_path('dolt.php');

        @unlink($path);

        try {
            [$code, $output] = $this->dolt('install');

            $this->assertSame(0, $code);
            $this->assertFileExists($path);
            $this->assertStringContainsString('Published config/dolt.php.', $output);
            $this->assertStringContainsString('DB_CONNECTION=dolt', $output);
            $this->assertStringContainsString(static::onDoltgres() ? 'Doltgres version' : 'Dolt version', $output);
        } finally {
            @unlink($path);
        }
    }
}
