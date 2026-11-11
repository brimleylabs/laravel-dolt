<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Data\Commit;
use BrimleyLabs\Dolt\Events\Committed;
use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class CommitTest extends TestCase
{
    public function test_it_commits_pending_changes(): void
    {
        Event::fake([Committed::class]);

        $this->makePost();

        $commit = Dolt::commit('Add the first post', 'Ada Lovelace <ada@example.test>');

        $this->assertInstanceOf(Commit::class, $commit);
        $this->assertSame('Add the first post', $commit->message);
        $this->assertSame('Ada Lovelace', $commit->author->name);
        $this->assertSame('ada@example.test', $commit->author->email);
        $this->assertSame($commit->hash, Dolt::head());
        $this->assertFalse(Dolt::isDirty());

        Event::assertDispatched(Committed::class, fn (Committed $e) => $e->commit->hash === $commit->hash && $e->branch === 'main' && $e->connection === 'dolt');
    }

    public function test_it_returns_null_when_there_is_nothing_to_commit(): void
    {
        $head = Dolt::head();

        $this->assertNull(Dolt::commit('Nothing'));
        $this->assertSame($head, Dolt::head());
    }

    public function test_an_empty_commit_can_be_forced(): void
    {
        $commit = Dolt::commit('Marker', allowEmpty: true);

        $this->assertNotNull($commit);
        $this->assertSame('Marker', $commit->message);
    }

    public function test_the_author_defaults_to_the_signed_in_user(): void
    {
        $this->actingAs($this->makeUser('Grace Hopper', 'grace@example.test'));

        $commit = Dolt::commit('Add a user');

        $this->assertSame('Grace Hopper', $commit->author->name);
        $this->assertSame('grace@example.test', $commit->author->email);
    }

    public function test_the_author_falls_back_to_the_system_author(): void
    {
        $this->makePost();

        $commit = Dolt::commit('Add a post');

        $this->assertSame('Test App', $commit->author->name);
        $this->assertSame('system@app.example.test', $commit->author->email);
    }

    public function test_the_author_can_be_resolved_by_the_application(): void
    {
        Dolt::resolveAuthorUsing(fn () => ['name' => 'Import Bot', 'email' => 'bot@example.test']);

        $this->makePost();

        $this->assertSame('Import Bot', Dolt::commit('Import')->author->name);
    }

    public function test_status_lists_uncommitted_tables(): void
    {
        $this->assertTrue(Dolt::status()->isEmpty());

        $this->makePost();

        $this->assertTrue(Dolt::isDirty());
        $this->assertSame(['posts'], Dolt::status()->pluck('table')->all());
    }

    public function test_the_log_is_newest_first(): void
    {
        $this->makePost('One');
        Dolt::commit('First');
        $this->makePost('Two');
        Dolt::commit('Second');

        $log = Dolt::log();

        $this->assertSame(['Second', 'First', 'Create tables'], $log->take(3)->pluck('message')->all());
        $this->assertSame(['First'], Dolt::log(limit: 1, offset: 1)->pluck('message')->all());
        $this->assertSame('UTC', $log->first()->date->getTimezone()->getName());
        $this->assertEqualsWithDelta(time(), $log->first()->date->getTimestamp(), 120);
    }

    public function test_commit_dates_follow_the_configured_timezone(): void
    {
        config(['dolt.timezone' => 'America/Chicago']);

        $commit = Dolt::log(limit: 1)->first();

        $this->assertSame('America/Chicago', $commit->date->getTimezone()->getName());
        $this->assertEqualsWithDelta(time(), $commit->date->getTimestamp(), 120);
    }

    public function test_a_commit_can_be_found_by_hash(): void
    {
        $this->makePost();
        $commit = Dolt::commit('Findable');

        $this->assertSame('Findable', Dolt::find($commit->hash)->message);
        $this->assertSame('Create tables', Dolt::find('HEAD~1')->message);
        $this->assertNull(Dolt::find('does-not-exist'));
    }

    public function test_reset_throws_uncommitted_changes_away(): void
    {
        $this->makePost('Keep');
        Dolt::commit('Keep');

        $this->makePost('Drop');
        Post::where('title', 'Keep')->update(['title' => 'Changed']);

        Dolt::reset();

        $this->assertSame(['Keep'], Post::pluck('title')->all());
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_reset_can_move_the_branch_back(): void
    {
        $start = Dolt::head();

        $this->makePost();
        $mistake = Dolt::commit('Mistake');

        $previous = Dolt::reset($start, force: true);

        $this->assertSame($start, Dolt::head());
        $this->assertSame(0, Post::count());

        // The move can be taken back.
        $this->assertSame($mistake->hash, $previous);

        Dolt::reset($previous);

        $this->assertSame($mistake->hash, Dolt::head());
        $this->assertSame(1, Post::count());
    }

    public function test_reset_will_not_drop_commits_from_the_default_branch_unless_forced(): void
    {
        $start = Dolt::head();

        $this->makePost();
        Dolt::commit('Somebody else\'s work');
        $this->makePost();
        $last = Dolt::commit('More work');

        try {
            Dolt::reset($start);

            $this->fail('The reset should have been refused.');
        } catch (DoltException $e) {
            $this->assertStringContainsString('Moving [main] back would drop 2 commits', $e->getMessage());
        }

        $this->assertSame($last->hash, Dolt::head());
        $this->assertSame(2, Post::count());

        // Throwing away uncommitted changes drops no commits and needs no force.
        $this->makePost('Unsaved');
        Dolt::reset();

        $this->assertSame(2, Post::count());
    }

    public function test_reset_moves_other_branches_back_freely(): void
    {
        Dolt::createBranch('draft');

        Dolt::on('draft', function () {
            $start = Dolt::head();

            $this->makePost();
            Dolt::commit('Draft work');

            Dolt::reset($start);

            $this->assertSame($start, Dolt::head());
        });
    }

    public function test_revert_undoes_one_commit_with_a_new_commit(): void
    {
        $this->makePost('Good');
        Dolt::commit('Good');

        $this->makePost('Bad');
        $bad = Dolt::commit('Bad');

        $this->makePost('Later');
        Dolt::commit('Later');

        $revert = Dolt::revert($bad, 'Ada Lovelace <ada@example.test>');

        $this->assertNotNull($revert);
        $this->assertNotSame($bad->hash, $revert->hash);
        $this->assertSame($revert->hash, Dolt::head());
        $this->assertEqualsCanonicalizing(['Good', 'Later'], Post::pluck('title')->all());
    }

    public function test_version_control_is_refused_inside_a_transaction(): void
    {
        DB::beginTransaction();

        try {
            $this->makePost();

            $this->expectException(DoltException::class);

            Dolt::commit('Too early');
        } finally {
            DB::rollBack();
        }
    }

    public function test_tags_name_a_commit(): void
    {
        $this->makePost();
        $commit = Dolt::commit('Release');

        $tag = Dolt::tag('v1.0', message: 'First release');

        $this->assertSame('v1.0', $tag->name);
        $this->assertSame($commit->hash, $tag->hash);
        $this->assertSame($commit->hash, Dolt::resolve('v1.0'));
        $this->assertSame(['v1.0'], Dolt::tags()->pluck('name')->all());

        Dolt::deleteTag('v1.0');

        $this->assertTrue(Dolt::tags()->isEmpty());
    }

    public function test_branches_can_be_listed_created_renamed_and_deleted(): void
    {
        $branch = Dolt::createBranch('feature');

        $this->assertSame('feature', $branch->name);
        $this->assertSame(Dolt::head(), $branch->hash);
        $this->assertSame(['feature', 'main'], Dolt::branches()->pluck('name')->all());

        Dolt::renameBranch('feature', 'renamed');

        $this->assertTrue(Dolt::branchExists('renamed'));
        $this->assertFalse(Dolt::branchExists('feature'));

        Dolt::deleteBranch('renamed');

        $this->assertSame(['main'], Dolt::branches()->pluck('name')->all());
    }

    public function test_a_branch_can_start_from_an_earlier_commit(): void
    {
        $start = Dolt::head();

        $this->makePost();
        Dolt::commit('Later');

        $this->assertSame($start, Dolt::createBranch('old', $start)->hash);
    }

    public function test_the_default_and_current_branch_cannot_be_deleted(): void
    {
        Dolt::checkout('feature', create: true);

        try {
            Dolt::deleteBranch('feature');
            $this->fail('Deleting the current branch should be refused.');
        } catch (DoltException) {
            $this->assertTrue(Dolt::branchExists('feature'));
        }

        $this->expectException(DoltException::class);

        Dolt::deleteBranch('main');
    }

    public function test_a_moment_in_time_resolves_to_the_commit_that_was_current(): void
    {
        $first = Dolt::head();

        usleep(1_100_000);
        $between = $this->serverNow();
        usleep(1_100_000);

        $this->makePost();
        $second = Dolt::commit('Later')->hash;

        $this->assertSame($first, Dolt::resolve($between));
        $this->assertSame($second, Dolt::resolve($this->serverNow()->addMinute()));
    }
}
