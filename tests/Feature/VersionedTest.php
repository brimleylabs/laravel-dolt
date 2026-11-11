<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\Fixtures\User;
use BrimleyLabs\Dolt\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class VersionedTest extends TestCase
{
    public function test_a_record_knows_its_history(): void
    {
        $post = $this->makePost('Draft', ['status' => 'draft']);
        Dolt::commit('Write the post', 'Ada Lovelace <ada@example.test>');

        $post->update(['title' => 'Final', 'status' => 'published']);
        Dolt::commit('Publish the post', 'Grace Hopper <grace@example.test>');

        $history = $post->history();

        $this->assertCount(2, $history);

        [$latest, $first] = $history->all();

        $this->assertTrue($latest->isModified());
        $this->assertSame('Publish the post', $latest->commit->message);
        $this->assertSame('Grace Hopper', $latest->commit->author->name);
        $this->assertSame(['Draft', 'Final'], $latest->changes()['title']);
        $this->assertSame(['draft', 'published'], $latest->changes()['status']);
        $this->assertSame(['id' => $post->id], $latest->key);

        $this->assertTrue($first->isAdded());
        $this->assertNull($first->before);
        $this->assertSame('Draft', $first->after['title']);
        $this->assertSame('Write the post', $first->commit->message);
        $this->assertSame('ada@example.test', $first->commit->author->email);
    }

    public function test_history_only_covers_the_one_record(): void
    {
        $first = $this->makePost('First');
        $second = $this->makePost('Second');
        Dolt::commit('Two posts');

        $second->update(['title' => 'Second, edited']);
        Dolt::commit('Edit the second');

        $this->assertCount(1, $first->history());
        $this->assertCount(2, $second->history());
        $this->assertCount(1, $second->history(limit: 1));
    }

    public function test_changes_that_are_not_committed_yet_come_first(): void
    {
        $post = $this->makePost('Draft');
        Dolt::commit('Write the post');

        $post->update(['title' => 'Unsaved edit']);

        $history = $post->history();

        $this->assertCount(2, $history);
        $this->assertNull($history[0]->commit);
        $this->assertSame(['Draft', 'Unsaved edit'], $history[0]->changes()['title']);
        $this->assertSame('Write the post', $history[1]->commit->message);
    }

    public function test_history_sees_writes_that_bypass_eloquent(): void
    {
        $post = $this->makePost('Draft');
        Dolt::commit('Write the post');

        DB::table('posts')->where('id', $post->id)->update(['title' => 'Bulk edit']);
        DB::statement("update posts set status = 'archived'");
        Dolt::commit('Bulk maintenance');

        $latest = $post->history()->first();

        $this->assertSame('Bulk maintenance', $latest->commit->message);
        $this->assertSame(['Draft', 'Bulk edit'], $latest->changes()['title']);
        $this->assertSame(['draft', 'archived'], $latest->changes()['status']);
    }

    public function test_hidden_attributes_stay_out_of_history(): void
    {
        $user = $this->makeUser();
        Dolt::commit('Add a user');

        $user->forceFill(['password' => 'changed'])->save();
        Dolt::commit('Change the password');

        $latest = $user->history()->first();

        $this->assertArrayNotHasKey('password', $latest->after);
        $this->assertArrayNotHasKey('password', $latest->before);
        $this->assertNotContains('password', $latest->changed());

        $this->assertSame(['secret', 'changed'], $user->history(withHidden: true)->first()->changes()['password']);
    }

    public function test_a_record_can_be_read_as_it_was(): void
    {
        $post = $this->makePost('Draft');
        $then = Dolt::commit('Write the post');

        $post->update(['title' => 'Final']);
        Dolt::commit('Edit');

        $old = $post->versionAt($then);

        $this->assertInstanceOf(Post::class, $old);
        $this->assertSame('Draft', $old->title);
        $this->assertSame('Final', $post->fresh()->title);
        $this->assertNull($post->versionAt('HEAD~2'));
    }

    public function test_a_record_can_be_put_back_the_way_it_was(): void
    {
        $post = $this->makePost('Draft', ['body' => 'Original body']);
        $then = Dolt::commit('Write the post');

        $post->update(['title' => 'Vandalised', 'body' => null]);
        Dolt::commit('Bad edit');

        $post->revertTo($then);
        Dolt::commit('Undo the bad edit');

        $fresh = Post::find($post->id);

        $this->assertSame('Draft', $fresh->title);
        $this->assertSame('Original body', $fresh->body);
        $this->assertSame('Undo the bad edit', $post->history()->first()->commit->message);
        $this->assertCount(3, $post->history());
    }

    public function test_reverting_to_a_point_before_the_record_existed_is_refused(): void
    {
        $start = Dolt::head();

        $post = $this->makePost();
        Dolt::commit('Write the post');

        $this->expectException(DoltException::class);

        $post->revertTo($start);
    }

    public function test_a_record_knows_the_commit_that_last_touched_it(): void
    {
        $first = $this->makePost('First');
        $second = $this->makePost('Second');
        Dolt::commit('Two posts', 'Ada Lovelace <ada@example.test>');

        $second->update(['title' => 'Second, edited']);
        Dolt::commit('Edit the second', 'Grace Hopper <grace@example.test>');

        $this->assertSame('Two posts', $first->lastCommit()->message);
        $this->assertSame('Edit the second', $second->lastCommit()->message);
        $this->assertSame('Grace Hopper', $second->lastCommit()->author->name);
        $this->assertSame(Dolt::head(), $second->lastCommit()->hash);
        $this->assertSame([$first->lastCommit()->hash], $second->lastCommit()->parents);
    }

    public function test_short_hashes_and_dates_work_for_a_record_too(): void
    {
        $post = $this->makePost('One');
        $one = Dolt::commit('One');

        $post->update(['title' => 'Two']);
        Dolt::commit('Two');

        $short = substr($one->hash, 0, 8);

        $this->assertSame(['title' => ['One', 'Two']], array_intersect_key($post->changesBetween($short)->changes(), ['title' => true]));
        $this->assertSame('One', $post->versionAt($short)->title);

        $post->delete();
        Dolt::commit('Delete');

        $this->assertSame(['One'], Post::deletedSince($short)->pluck('title')->all());
    }

    public function test_a_record_can_be_compared_between_two_points(): void
    {
        $post = $this->makePost('One');
        $one = Dolt::commit('One');

        $post->update(['title' => 'Two']);
        $two = Dolt::commit('Two');

        $post->update(['title' => 'Three']);

        $change = $post->changesBetween($one, $two);

        $this->assertTrue($change->isModified());
        $this->assertSame(['title' => ['One', 'Two']], array_intersect_key($change->changes(), ['title' => true]));

        $this->assertSame(['One', 'Three'], $post->changesBetween($one)->changes()['title']);
        $this->assertNull($this->makePost('Untouched')->changesBetween('HEAD', 'HEAD'));
    }

    public function test_deleted_records_can_be_listed_and_brought_back(): void
    {
        $keep = $this->makePost('Keep');
        $gone = $this->makePost('Gone', ['body' => 'Body text']);
        $then = Dolt::commit('Two posts');

        $gone->delete();
        Dolt::commit('Delete one');

        $deleted = Post::deletedSince($then);

        $this->assertCount(1, $deleted);
        $this->assertInstanceOf(Post::class, $deleted->first());
        $this->assertSame('Gone', $deleted->first()->title);
        $this->assertFalse($deleted->first()->exists);

        $restored = Post::restoreFrom($then, $gone->id);

        $this->assertSame($gone->id, $restored->id);
        $this->assertSame('Body text', Post::find($gone->id)->body);
        $this->assertSame(2, Post::count());
        $this->assertTrue(Post::deletedSince($then)->isEmpty());
        $this->assertNull(Post::restoreFrom($then, 9999));
        $this->assertSame('Keep', $keep->fresh()->title);
    }

    public function test_restoring_also_rewinds_a_record_that_still_exists(): void
    {
        $user = $this->makeUser('Ada Lovelace');
        $then = Dolt::commit('Add a user');

        $user->update(['name' => 'Somebody Else']);
        Dolt::commit('Rename');

        $restored = User::restoreFrom($then, $user->id);

        $this->assertSame('Ada Lovelace', $restored->name);
        $this->assertSame('Ada Lovelace', User::find($user->id)->name);
        $this->assertSame(1, User::count());
    }
}
