<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Events\WorkspaceDiscarded;
use BrimleyLabs\Dolt\Events\WorkspaceOpened;
use BrimleyLabs\Dolt\Events\WorkspacePublished;
use BrimleyLabs\Dolt\Exceptions\MergeConflictException;
use BrimleyLabs\Dolt\Exceptions\WorkspaceException;
use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Repository;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\Fixtures\User;
use BrimleyLabs\Dolt\Tests\TestCase;
use BrimleyLabs\Dolt\Workspaces\Workspace;
use Illuminate\Support\Facades\Event;
use RuntimeException;

class WorkspaceTest extends TestCase
{
    public function test_a_workspace_opens_as_a_branch_of_live_data(): void
    {
        Event::fake([WorkspaceOpened::class]);

        $this->makePost('Live');
        Dolt::commit('Live post');

        $workspace = Dolt::workspaces()->open('Spring Sale', owner: 'Ada Lovelace <ada@example.test>');

        $this->assertSame('spring-sale', $workspace->name);
        $this->assertSame('workspace/spring-sale', $workspace->branch);
        $this->assertSame('Spring Sale', $workspace->title());
        $this->assertSame('Ada Lovelace', $workspace->owner()->name);
        $this->assertNotNull($workspace->openedAt());
        $this->assertTrue($workspace->exists());
        $this->assertTrue(Dolt::branchExists('workspace/spring-sale'));
        $this->assertFalse($workspace->hasChanges());
        $this->assertSame('main', Dolt::branch());

        Event::assertDispatched(WorkspaceOpened::class, fn (WorkspaceOpened $e) => $e->workspace->is($workspace));
    }

    public function test_a_workspace_can_be_found_again_with_its_details(): void
    {
        Dolt::workspaces()->open('Spring Sale', owner: 'Ada Lovelace <ada@example.test>');

        $found = Dolt::workspaces()->find('spring-sale');

        $this->assertInstanceOf(Workspace::class, $found);
        $this->assertSame('Spring Sale', $found->title());
        $this->assertSame('ada@example.test', $found->owner()->email);
        $this->assertNotNull($found->openedAt());
        $this->assertSame(['name', 'branch', 'title', 'owner', 'opened_at'], array_keys($found->toArray()));

        $this->assertNull(Dolt::workspaces()->find('missing'));
        $this->assertTrue(Dolt::workspaces()->exists('spring-sale'));
        $this->assertSame(['spring-sale'], Dolt::workspaces()->all()->pluck('name')->all());
        $this->assertTrue(Dolt::workspaces()->firstOrOpen('Spring Sale')->is($found));
    }

    public function test_names_are_checked_and_cannot_be_reused(): void
    {
        Dolt::workspaces()->open('Spring Sale');

        try {
            Dolt::workspaces()->open('Spring Sale');
            $this->fail('A second workspace with the same name should be refused.');
        } catch (WorkspaceException) {
            // expected
        }

        $this->expectException(WorkspaceException::class);

        Dolt::workspaces()->open('Bad', name: 'no spaces; drop table');
    }

    public function test_changes_made_in_a_workspace_do_not_touch_live_data(): void
    {
        $live = $this->makePost('Live');
        Dolt::commit('Live post');

        $workspace = Dolt::workspaces()->open('Spring Sale');

        $returned = $workspace->run(function () use ($live) {
            $this->assertSame('workspace/spring-sale', Dolt::branch());

            $live->update(['title' => 'Live, edited']);
            $this->makePost('New in workspace');

            return 'done';
        }, author: 'Ada Lovelace <ada@example.test>');

        $this->assertSame('done', $returned);
        $this->assertSame('main', Dolt::branch());
        $this->assertSame(['Live'], Post::pluck('title')->all());
        $this->assertFalse(Dolt::isDirty());

        $changes = $workspace->changes();

        $this->assertTrue($workspace->hasChanges());
        $this->assertSame(['posts'], $changes->tables()->keys()->all());
        $this->assertSame(1, $changes->table('posts')->rowsAdded);
        $this->assertSame(1, $changes->table('posts')->rowsModified);
        $this->assertCount(2, $changes->rows('posts'));

        // Transaction mode commits the edit and the new post as they happen.
        $this->assertSame(
            config('dolt.commit.mode') === 'request'
                ? ['Changes in Spring Sale', 'Open workspace: Spring Sale']
                : ['Changes in Spring Sale', 'Changes in Spring Sale', 'Open workspace: Spring Sale'],
            $workspace->commits()->pluck('message')->all(),
        );
        $this->assertSame('Ada Lovelace', $workspace->commits()->first()->author->name);
    }

    public function test_publishing_makes_the_workspace_live_and_removes_it(): void
    {
        Event::fake([WorkspacePublished::class]);

        $workspace = Dolt::workspaces()->open('Spring Sale');
        $workspace->run(fn () => $this->makePost('From the workspace'));

        // Live data moved on in the meantime, without colliding.
        $this->makeUser();
        Dolt::commit('Add a user');

        $this->assertTrue($workspace->canPublish());

        $result = $workspace->publish(author: 'Grace Hopper <grace@example.test>');

        $this->assertSame(Dolt::head(), $result->hash);
        $this->assertSame(['From the workspace'], Post::pluck('title')->all());
        $this->assertFalse($workspace->exists());
        $this->assertFalse(Dolt::branchExists('workspace/spring-sale'));

        $head = Dolt::log(limit: 1)->first();

        $this->assertSame('Publish workspace: Spring Sale', $head->message);
        $this->assertSame('Grace Hopper', $head->author->name);
        $this->assertTrue($head->isMerge());

        Event::assertDispatched(WorkspacePublished::class, fn (WorkspacePublished $e) => $e->result->hash === $result->hash);
    }

    public function test_publishing_picks_up_changes_that_were_not_committed_yet(): void
    {
        $workspace = Dolt::workspaces()->open('Spring Sale');

        Dolt::on($workspace->branch, fn () => $this->makePost('Uncommitted in the workspace'));
        $this->makeUser();

        $workspace->publish();

        $this->assertSame(['Uncommitted in the workspace'], Post::pluck('title')->all());
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_a_published_workspace_can_be_kept(): void
    {
        $workspace = Dolt::workspaces()->open('Long Running');
        $workspace->run(fn () => $this->makePost('Round one'));

        $workspace->publish(keep: true);

        $this->assertTrue($workspace->exists());
        $this->assertFalse($workspace->hasChanges());

        $workspace->run(fn () => $this->makePost('Round two'));
        $workspace->publish();

        $this->assertEqualsCanonicalizing(['Round one', 'Round two'], Post::pluck('title')->all());
    }

    public function test_discarding_throws_the_work_away(): void
    {
        Event::fake([WorkspaceDiscarded::class]);

        $workspace = Dolt::workspaces()->open('Bad Idea');
        $workspace->run(fn () => $this->makePost('Never mind'));

        $head = Dolt::head();

        $workspace->discard();

        $this->assertFalse($workspace->exists());
        $this->assertSame(0, Post::count());
        $this->assertSame($head, Dolt::head());

        Event::assertDispatched(WorkspaceDiscarded::class, fn (WorkspaceDiscarded $e) => $e->workspace->title() === 'Bad Idea');
    }

    public function test_conflicts_are_reported_before_publishing(): void
    {
        $post = $this->makePost('Original');
        Dolt::commit('Original');

        $workspace = Dolt::workspaces()->open('Rewrite');
        $workspace->run(fn () => Post::whereKey($post->id)->update(['title' => 'Workspace title']));

        $post->update(['title' => 'Live title']);
        Dolt::commit('Live edit');

        $this->assertFalse($workspace->canPublish());
        $this->assertSame(['posts'], $workspace->conflicts()->pluck('table')->all());

        $conflict = $workspace->conflictsIn('posts')->first();

        $this->assertSame('Original', $conflict->base['title']);
        $this->assertSame('Live title', $conflict->ours['title']);
        $this->assertSame('Workspace title', $conflict->theirs['title']);

        try {
            $workspace->publish();
            $this->fail('Publishing over a conflict should be refused.');
        } catch (MergeConflictException) {
            $this->assertTrue($workspace->exists());
            $this->assertSame('Live title', Post::value('title'));
            $this->assertSame('main', Dolt::branch());
        }

        $workspace->publish(onConflict: Repository::THEIRS);

        $this->assertSame('Workspace title', Post::value('title'));
        $this->assertFalse($workspace->exists());
    }

    public function test_a_workspace_can_catch_up_with_live_data(): void
    {
        $workspace = Dolt::workspaces()->open('Spring Sale');
        $workspace->run(fn () => $this->makePost('Workspace post'));

        $this->makeUser('Live User', 'live@example.test');
        Dolt::commit('Add a user');

        $result = $workspace->sync();

        $this->assertNotNull($result->hash);
        $this->assertSame('main', Dolt::branch());

        $seen = Dolt::on($workspace->branch, fn () => [Post::count(), User::count()]);

        $this->assertSame([1, 1], $seen);
        $this->assertSame(['posts'], $workspace->changes()->tables()->keys()->all());
    }

    public function test_idle_workspaces_can_be_pruned(): void
    {
        Dolt::workspaces()->open('Stale');
        Dolt::workspaces()->open('Also Stale');

        $this->assertSame(0, Dolt::workspaces()->prune($this->serverNow()->subHour()));
        $this->assertSame(2, Dolt::workspaces()->prune($this->serverNow()->addHour()));
        $this->assertTrue(Dolt::workspaces()->all()->isEmpty());
        $this->assertSame(['main'], Dolt::branches()->pluck('name')->all());
    }

    public function test_a_preview_holds_changes_until_they_are_applied(): void
    {
        $post = $this->makePost('Original');
        Dolt::commit('Original');

        $preview = Dolt::preview(function () use ($post) {
            Post::whereKey($post->id)->update(['title' => 'Rewritten by the agent']);
            $this->makePost('Added by the agent');

            return ['rows' => 2];
        }, 'Agent: tidy up titles', 'Agent Smith <agent@example.test>');

        $this->assertSame(['rows' => 2], $preview->result);
        $this->assertStringStartsWith('preview/', $preview->branch);
        $this->assertSame('Agent: tidy up titles', $preview->title());
        $this->assertSame(['Original'], Post::pluck('title')->all());
        $this->assertSame(1, $preview->changes()->table('posts')->rowsAdded);
        $this->assertSame(1, $preview->changes()->table('posts')->rowsModified);
        $this->assertTrue(Dolt::workspaces()->all()->isEmpty());
        $this->assertCount(1, Dolt::previews()->all());

        $before = Dolt::log()->count();

        $preview->apply();

        $this->assertEqualsCanonicalizing(['Rewritten by the agent', 'Added by the agent'], Post::pluck('title')->all());
        $this->assertTrue(Dolt::previews()->all()->isEmpty());
        $this->assertSame(['main'], Dolt::branches()->pluck('name')->all());

        // The whole preview is one commit on live data, credited to whoever made it.
        $commit = Dolt::log(limit: 1)->first();

        $this->assertSame($before + 1, Dolt::log()->count());
        $this->assertSame('Agent: tidy up titles', $commit->message);
        $this->assertSame('Agent Smith', $commit->author->name);
        $this->assertCount(1, $commit->parents);
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_whoever_approves_a_preview_can_sign_it(): void
    {
        $preview = Dolt::preview(fn () => $this->makePost('Proposed'), 'A proposal', 'Agent Smith <agent@example.test>');

        $preview->apply('Approved by Ada', 'Ada Lovelace <ada@example.test>');

        $commit = Dolt::log(limit: 1)->first();

        $this->assertSame('Approved by Ada', $commit->message);
        $this->assertSame('Ada Lovelace', $commit->author->name);
        $this->assertSame(1, Post::count());
    }

    public function test_an_open_preview_can_be_found_again_by_name(): void
    {
        $preview = Dolt::preview(fn () => $this->makePost('Proposed'), 'A proposal', 'Agent Smith <agent@example.test>');

        $found = Dolt::previews()->get($preview->name);

        $this->assertTrue($found->is($preview));
        $this->assertSame('A proposal', $found->title());
        $this->assertSame('Agent Smith', $found->owner()->name);

        $found->apply();

        $this->assertSame(1, Post::count());
        $this->assertNull(Dolt::previews()->find($preview->name));
    }

    public function test_a_preview_can_be_thrown_away(): void
    {
        $head = Dolt::head();

        $preview = Dolt::preview(fn () => $this->makePost('Hallucinated'));

        $preview->discard();

        $this->assertSame(0, Post::count());
        $this->assertSame($head, Dolt::head());
        $this->assertSame(['main'], Dolt::branches()->pluck('name')->all());
    }

    public function test_a_preview_that_fails_leaves_nothing_behind(): void
    {
        try {
            Dolt::preview(function () {
                $this->makePost('Half done');

                throw new RuntimeException('The agent crashed.');
            });

            $this->fail('The exception should reach the caller.');
        } catch (RuntimeException $e) {
            $this->assertSame('The agent crashed.', $e->getMessage());
        }

        $this->assertSame('main', Dolt::branch());
        $this->assertSame(0, Post::count());
        $this->assertFalse(Dolt::isDirty());
        $this->assertSame(['main'], Dolt::branches()->pluck('name')->all());
    }
}
