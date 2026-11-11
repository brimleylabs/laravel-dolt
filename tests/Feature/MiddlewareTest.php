<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One commit per request. Transaction mode is covered by ExactAttributionTest.
 */
class MiddlewareTest extends TestCase
{
    protected ?string $commitMode = 'request';

    protected function defineWebRoutes($router): void
    {
        $router->middleware('dolt')->group(function () use ($router) {
            $router->get('/posts', fn () => Post::orderBy('id')->pluck('title'));

            $router->get('/posts/{post}', fn (Post $post) => $post->title);

            $router->post('/posts', function (Request $request) {
                return response()->json(Post::create(['title' => $request->input('title', 'Hello')]), 201);
            });

            $router->post('/two-posts', function () {
                Post::create(['title' => 'One']);
                Post::create(['title' => 'Two']);

                return 'ok';
            });

            $router->post('/fail', function () {
                Post::create(['title' => 'Written before the failure']);

                abort(422);
            });

            $router->post('/crash', function () {
                Post::create(['title' => 'Written before the crash']);

                throw new \RuntimeException('No.');
            });

            $router->post('/named', function () {
                Post::create(['title' => 'Sale']);

                Dolt::message('Publish the spring sale');

                return 'ok';
            });

            $router->post('/later', function () {
                Post::create(['title' => 'During']);

                app()->terminating(fn () => Post::create(['title' => 'After']));

                return 'ok';
            });

            $router->post('/deferred', function () {
                Post::create(['title' => 'During']);

                \Illuminate\Support\defer(fn () => Post::create(['title' => 'Deferred']));

                return 'ok';
            });
        });

        $router->post('/staging/posts', fn () => Post::create(['title' => 'On staging']))->middleware('dolt:staging');
    }

    public function test_a_request_that_writes_becomes_one_commit(): void
    {
        $this->post('/two-posts')->assertOk();

        $log = Dolt::log();

        $this->assertSame(['POST /two-posts', 'Create tables'], $log->take(2)->pluck('message')->all());
        $this->assertSame('Test App', $log->first()->author->name);
        $this->assertFalse(Dolt::isDirty());
        $this->assertSame(2, Post::count());
    }

    public function test_the_commit_is_credited_to_the_signed_in_user(): void
    {
        $user = $this->makeUser('Grace Hopper', 'grace@example.test');
        Dolt::commit('Add a user');

        $this->actingAs($user)->post('/posts', ['title' => 'By Grace'])->assertCreated();

        $commit = Dolt::log(limit: 1)->first();

        $this->assertSame('POST /posts', $commit->message);
        $this->assertSame('Grace Hopper', $commit->author->name);
        $this->assertSame('grace@example.test', $commit->author->email);
    }

    public function test_a_request_that_only_reads_makes_no_commit(): void
    {
        $this->makePost();
        Dolt::commit('Add a post');

        $head = Dolt::head();

        $this->get('/posts')->assertOk()->assertExactJson(['Hello']);

        $this->assertSame($head, Dolt::head());
    }

    public function test_a_failed_request_is_marked_in_its_commit(): void
    {
        $this->post('/fail')->assertStatus(422);

        $this->assertSame('POST /fail [422]', Dolt::log(limit: 1)->first()->message);
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_a_request_that_dies_without_a_response_still_commits_what_it_wrote(): void
    {
        $this->withoutExceptionHandling();

        try {
            $this->post('/crash');

            $this->fail('The request should have thrown.');
        } catch (\RuntimeException) {
            //
        }

        $this->assertSame('POST /crash (failed)', Dolt::log(limit: 1)->first()->message);
        $this->assertSame(1, Post::count());
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_the_application_can_name_the_commit(): void
    {
        $this->post('/named')->assertOk();

        $this->assertSame('Publish the spring sale', Dolt::log(limit: 1)->first()->message);

        // The name is used once.
        $this->post('/posts')->assertCreated();

        $this->assertSame('POST /posts', Dolt::log(limit: 1)->first()->message);
    }

    public function test_the_application_can_describe_every_request(): void
    {
        Dolt::describeRequestUsing(fn (Request $request) => 'Web: '.$request->path());

        $this->post('/posts')->assertCreated();

        $this->assertSame('Web: posts', Dolt::log(limit: 1)->first()->message);
    }

    public function test_a_route_can_be_pinned_to_a_branch(): void
    {
        Dolt::createBranch('staging');

        $this->post('/staging/posts')->assertCreated();

        $this->assertSame('main', Dolt::branch());
        $this->assertSame(0, Post::count());
        $this->assertSame('Create tables', Dolt::log(limit: 1)->first()->message);

        $this->assertSame(['On staging'], Dolt::on('staging', fn () => Post::pluck('title')->all()));
        $this->assertSame('POST /staging/posts', Dolt::log('staging', limit: 1)->first()->message);
    }

    public function test_requests_follow_the_workspace_in_the_session(): void
    {
        $user = $this->makeUser('Grace Hopper', 'grace@example.test');
        Dolt::commit('Add a user');

        $workspace = Dolt::workspaces()->open('Spring Sale');

        $this->actingAs($user)
            ->withSession(['dolt.workspace' => 'spring-sale'])
            ->post('/posts', ['title' => 'Only in the workspace'])
            ->assertCreated();

        $this->assertSame('main', Dolt::branch());
        $this->assertSame(0, Post::count());

        $this->get('/posts')->assertExactJson(['Only in the workspace']);

        $latest = $workspace->commits()->first();

        $this->assertSame('POST /posts', $latest->message);
        $this->assertSame('Grace Hopper', $latest->author->name);
        $this->assertSame(1, $workspace->changes()->table('posts')->rowsAdded);
    }

    public function test_route_model_binding_reads_from_the_workspace(): void
    {
        $post = $this->makePost('Live title');
        Dolt::commit('Add a post');

        Dolt::workspaces()->open('Rewrite')->run(fn () => Post::whereKey($post->id)->update(['title' => 'Workspace title']));

        $this->get("/posts/{$post->id}")->assertSee('Live title');

        $this->withSession(['dolt.workspace' => 'rewrite'])
            ->get("/posts/{$post->id}")
            ->assertSee('Workspace title');
    }

    public function test_a_workspace_that_is_gone_falls_back_to_live_data(): void
    {
        $this->withSession(['dolt.workspace' => 'published-yesterday'])
            ->post('/posts', ['title' => 'Live after all'])
            ->assertCreated()
            ->assertSessionMissing('dolt.workspace');

        $this->assertSame(['Live after all'], Post::pluck('title')->all());
        $this->assertSame('POST /posts', Dolt::log(limit: 1)->first()->message);
    }

    public function test_the_application_can_choose_the_branch_itself(): void
    {
        Dolt::createBranch('tenant-acme');
        Dolt::resolveBranchUsing(fn (Request $request) => $request->header('X-Tenant-Branch'));

        $this->post('/posts', ['title' => 'Acme post'], ['X-Tenant-Branch' => 'tenant-acme'])->assertCreated();

        $this->assertSame(0, Post::count());
        $this->assertSame(['Acme post'], Dolt::on('tenant-acme', fn () => Post::pluck('title')->all()));

        $this->post('/posts', ['title' => 'Main post'])->assertCreated();

        $this->assertSame(['Main post'], Post::pluck('title')->all());
    }

    public function test_writes_made_after_the_response_get_their_own_commit(): void
    {
        $this->post('/later')->assertOk();

        $this->assertSame(
            ['POST /later (after response)', 'POST /later', 'Create tables'],
            Dolt::log()->take(3)->pluck('message')->all(),
        );
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_deferred_functions_are_committed_too(): void
    {
        if (! function_exists('Illuminate\Support\defer')) {
            $this->markTestSkipped('This Laravel version has no defer().');
        }

        $this->post('/deferred')->assertOk();

        $this->assertEqualsCanonicalizing(['During', 'Deferred'], Post::pluck('title')->all());
        $this->assertSame('POST /deferred (after response)', Dolt::log(limit: 1)->first()->message);
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_writes_made_after_the_response_stay_in_the_workspace(): void
    {
        $workspace = Dolt::workspaces()->open('Spring Sale');

        $this->withSession(['dolt.workspace' => 'spring-sale'])->post('/later')->assertOk();

        $this->assertSame('main', Dolt::branch());
        $this->assertSame(0, Post::count());
        $this->assertSame(2, $workspace->changes()->table('posts')->rowsAdded);
        $this->assertSame('POST /later (after response)', $workspace->commits()->first()->message);
    }

    public function test_transaction_mode_commits_every_write_as_it_happens(): void
    {
        config(['dolt.commit.mode' => 'transaction']);

        $user = $this->makeUser('Grace Hopper', 'grace@example.test');
        Dolt::commit('Add a user');

        $this->actingAs($user)->post('/two-posts')->assertOk();

        $log = Dolt::log()->take(3);

        $this->assertSame(['POST /two-posts', 'POST /two-posts', 'Add a user'], $log->pluck('message')->all());
        $this->assertSame('Grace Hopper', $log[0]->author->name);
        $this->assertSame('Grace Hopper', $log[1]->author->name);
        $this->assertFalse(Dolt::isDirty());

        // The session goes back to normal once the request is over.
        $this->makePost('Outside a request');

        $this->assertTrue(Dolt::isDirty());
    }

    public function test_a_request_inside_a_transaction_is_left_alone(): void
    {
        $head = Dolt::head();

        DB::beginTransaction();

        try {
            $this->post('/posts')->assertCreated();

            $this->assertSame(1, Post::count());
        } finally {
            DB::rollBack();
        }

        $this->assertSame($head, Dolt::head());
        $this->assertSame(0, Post::count());
    }
}
