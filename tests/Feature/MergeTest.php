<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Data\Conflict;
use BrimleyLabs\Dolt\Events\Merged;
use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Exceptions\MergeConflictException;
use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Repository;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\Fixtures\User;
use BrimleyLabs\Dolt\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class MergeTest extends TestCase
{
    public function test_it_merges_a_branch_with_a_merge_commit(): void
    {
        Event::fake([Merged::class]);

        Dolt::createBranch('feature');
        Dolt::on('feature', function () {
            $this->makePost('From feature');
            Dolt::commit('Feature work');
        });

        $result = Dolt::merge('feature', 'Bring in the feature', 'Ada Lovelace <ada@example.test>');

        $this->assertSame('feature', $result->source);
        $this->assertSame('main', $result->target);
        $this->assertFalse($result->upToDate());
        $this->assertSame(['From feature'], Post::pluck('title')->all());

        $head = Dolt::find('HEAD');

        $this->assertSame('Bring in the feature', $head->message);
        $this->assertSame('Ada Lovelace', $head->author->name);
        $this->assertTrue($head->isMerge());
        $this->assertSame($head->hash, $result->hash);
        $this->assertFalse(Dolt::isDirty());

        Event::assertDispatched(Merged::class);
    }

    public function test_both_sides_keep_their_changes_when_they_do_not_collide(): void
    {
        $post = $this->makePost('Shared', ['body' => 'original']);
        Dolt::commit('Shared post');

        Dolt::createBranch('feature');
        Dolt::on('feature', function () use ($post) {
            Post::find($post->id)->update(['status' => 'published']);
            Dolt::commit('Publish');
        });

        $post->update(['body' => 'edited on main']);
        Dolt::commit('Edit body');

        Dolt::merge('feature');

        $merged = $post->fresh();

        $this->assertSame('published', $merged->status);
        $this->assertSame('edited on main', $merged->body);
    }

    public function test_merging_a_branch_with_nothing_new_is_reported(): void
    {
        Event::fake([Merged::class]);

        Dolt::createBranch('feature');

        $head = Dolt::head();
        $result = Dolt::merge('feature');

        $this->assertTrue($result->upToDate());
        $this->assertSame($head, Dolt::head());

        Event::assertNotDispatched(Merged::class);
    }

    public function test_a_fast_forward_is_possible_when_asked_for(): void
    {
        Dolt::createBranch('feature');
        $tip = Dolt::on('feature', function () {
            $this->makePost();

            return Dolt::commit('Feature work')->hash;
        });

        $result = Dolt::merge('feature', fastForward: true);

        $this->assertTrue($result->fastForward);
        $this->assertSame($tip, Dolt::head());
    }

    public function test_a_squash_brings_the_changes_without_the_history(): void
    {
        Dolt::createBranch('feature');
        Dolt::on('feature', function () {
            $this->makePost('One');
            Dolt::commit('One');
            $this->makePost('Two');
            Dolt::commit('Two');
        });

        Dolt::merge('feature', 'Squashed feature', squash: true);

        $this->assertSame(2, Post::count());

        $head = Dolt::find('HEAD');

        $this->assertSame('Squashed feature', $head->message);
        $this->assertFalse($head->isMerge());
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_a_conflict_aborts_by_default_and_leaves_the_branch_untouched(): void
    {
        $post = $this->conflictingBranches();
        $head = Dolt::head();

        try {
            Dolt::merge('feature');
            $this->fail('Expected a MergeConflictException.');
        } catch (MergeConflictException $e) {
            $this->assertSame('feature', $e->source);
            $this->assertSame('main', $e->target);
            $this->assertSame('posts', $e->conflicts->first()->table);
            $this->assertSame(1, $e->conflicts->first()->dataConflicts);
        }

        $this->assertSame($head, Dolt::head());
        $this->assertSame('main title', $post->fresh()->title);
        $this->assertFalse(Dolt::isDirty());
        if (! static::onDoltgres()) {
            $this->assertSame(1, (int) $this->connection()->selectOne('select @@autocommit as a')->a);
        }

        // The connection is fully usable afterwards.
        $this->makePost('Still works');
        $this->assertNotNull(Dolt::commit('Still works'));
    }

    public function test_conflicts_can_be_settled_in_favour_of_the_current_branch(): void
    {
        $post = $this->conflictingBranches();

        $result = Dolt::merge('feature', onConflict: Repository::OURS);

        $this->assertSame(1, $result->conflictsResolved);
        $this->assertSame('main title', $post->fresh()->title);
        $this->assertSame($result->hash, Dolt::head());
        $this->assertTrue(Dolt::find('HEAD')->isMerge());

        // The feature branch's other, non conflicting work still came across.
        $this->assertTrue(User::where('email', 'feature@example.test')->exists());
    }

    public function test_conflicts_can_be_settled_in_favour_of_the_incoming_branch(): void
    {
        $post = $this->conflictingBranches();

        $result = Dolt::merge('feature', onConflict: Repository::THEIRS);

        $this->assertSame(1, $result->conflictsResolved);
        $this->assertSame('feature title', $post->fresh()->title);
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_conflicts_can_be_settled_per_table(): void
    {
        $post = $this->conflictingBranches();

        Dolt::merge('feature', onConflict: ['posts' => Repository::THEIRS, '*' => Repository::ABORT]);

        $this->assertSame('feature title', $post->fresh()->title);
    }

    public function test_a_per_table_plan_without_an_answer_aborts(): void
    {
        $post = $this->conflictingBranches();

        try {
            Dolt::merge('feature', onConflict: ['users' => Repository::THEIRS]);
            $this->fail('Expected a MergeConflictException.');
        } catch (MergeConflictException) {
            $this->assertSame('main title', $post->fresh()->title);
        }
    }

    public function test_conflicts_can_be_settled_row_by_row(): void
    {
        $post = $this->conflictingBranches();
        $seen = [];

        Dolt::merge('feature', onConflict: function (Conflict $conflict) use (&$seen) {
            $seen[] = $conflict;

            return ['title' => $conflict->ours['title'].' + '.$conflict->theirs['title']];
        });

        $this->assertCount(1, $seen);
        $this->assertSame('posts', $seen[0]->table);
        $this->assertSame('original title', $seen[0]->base['title']);
        $this->assertSame('main title', $seen[0]->ours['title']);
        $this->assertSame('feature title', $seen[0]->theirs['title']);
        $this->assertContains('title', $seen[0]->columns());
        $this->assertSame('main title + feature title', $post->fresh()->title);
        $this->assertFalse(Dolt::isDirty());
    }

    public function test_a_row_callback_can_pick_a_side_or_abort(): void
    {
        $post = $this->conflictingBranches();

        try {
            Dolt::merge('feature', onConflict: fn () => null);
            $this->fail('Expected a MergeConflictException.');
        } catch (MergeConflictException) {
            $this->assertSame('main title', $post->fresh()->title);
        }

        Dolt::merge('feature', onConflict: fn () => Repository::THEIRS);

        $this->assertSame('feature title', $post->fresh()->title);
    }

    public function test_a_merge_can_be_previewed_without_changing_anything(): void
    {
        $post = $this->conflictingBranches();
        $head = Dolt::head();

        $summary = Dolt::previewMerge('feature');

        $this->assertSame(['posts'], $summary->pluck('table')->all());
        $this->assertSame(1, $summary->first()->dataConflicts);

        $rows = Dolt::previewConflicts('feature', 'posts');

        $this->assertCount(1, $rows);
        $this->assertSame('main title', $rows->first()->ours['title']);
        $this->assertSame('feature title', $rows->first()->theirs['title']);

        $this->assertSame($head, Dolt::head());
        $this->assertSame('main title', $post->fresh()->title);
    }

    public function test_a_clean_merge_previews_as_no_conflicts(): void
    {
        Dolt::createBranch('feature');
        Dolt::on('feature', function () {
            $this->makePost();
            Dolt::commit('Feature work');
        });

        $this->assertTrue(Dolt::previewMerge('feature')->isEmpty());
    }

    public function test_uncommitted_changes_block_a_merge_with_a_clear_message(): void
    {
        $post = $this->makePost('Shared');
        Dolt::commit('Shared');

        Dolt::createBranch('feature');
        Dolt::on('feature', function () use ($post) {
            Post::find($post->id)->update(['title' => 'Feature']);
            Dolt::commit('Feature');
        });

        $post->update(['title' => 'Uncommitted on main']);

        try {
            Dolt::merge('feature');
            $this->fail('Expected the merge to be refused.');
        } catch (DoltException $e) {
            $this->assertStringContainsString('uncommitted changes', $e->getMessage());
        }

        $this->assertSame('Uncommitted on main', $post->fresh()->title);
    }

    public function test_the_merge_base_is_where_two_branches_parted(): void
    {
        $base = Dolt::head();

        Dolt::createBranch('feature');
        Dolt::on('feature', function () {
            $this->makePost();
            Dolt::commit('Feature');
        });

        $this->makeUser();
        Dolt::commit('Main');

        $this->assertSame($base, Dolt::mergeBase('main', 'feature'));
    }

    /**
     * One post changed differently on main and on "feature". Returns the post.
     */
    protected function conflictingBranches(): Post
    {
        $post = $this->makePost('original title');
        Dolt::commit('Shared post');

        Dolt::createBranch('feature');
        Dolt::on('feature', function () use ($post) {
            Post::find($post->id)->update(['title' => 'feature title']);
            $this->makeUser('Feature User', 'feature@example.test');
            Dolt::commit('Feature edits the title');
        });

        $post->update(['title' => 'main title']);
        Dolt::commit('Main edits the title');

        return $post;
    }
}
