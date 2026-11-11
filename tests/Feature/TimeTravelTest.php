<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Exceptions\InvalidRefException;
use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\Fixtures\User;
use BrimleyLabs\Dolt\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Throwable;

class TimeTravelTest extends TestCase
{
    public function test_a_query_can_read_a_table_as_it_was(): void
    {
        $post = $this->makePost('Old title');
        $then = Dolt::commit('Old');

        $post->update(['title' => 'New title']);
        $this->makePost('Second');
        Dolt::commit('New');

        $this->assertSame(['Old title'], DB::table('posts')->asOf($then)->pluck('title')->all());
        $this->assertSame(['Old title'], DB::table('posts')->asOf($then->hash)->pluck('title')->all());
        $this->assertSame(['Old title'], DB::table('posts')->asOf('HEAD~1')->pluck('title')->all());
        $this->assertSame(2, DB::table('posts')->count());
    }

    public function test_eloquent_models_can_be_read_as_they_were(): void
    {
        $post = $this->makePost('Old title', ['status' => 'draft']);
        Dolt::commit('Old');
        Dolt::tag('before-edit');

        $post->update(['title' => 'New title', 'status' => 'published']);
        Dolt::commit('New');

        $old = Post::asOf('before-edit')->where('status', 'draft')->first();

        $this->assertInstanceOf(Post::class, $old);
        $this->assertSame('Old title', $old->title);
        $this->assertSame('Old title', Post::asOf('before-edit')->find($post->id)->title);
        $this->assertSame(0, Post::asOf('before-edit')->where('status', 'published')->count());
        $this->assertSame('New title', Post::find($post->id)->title);
    }

    public function test_a_moment_in_time_works_as_a_ref(): void
    {
        $post = $this->makePost('Old title');
        Dolt::commit('Old');

        usleep(1_100_000);
        $between = $this->serverNow();
        usleep(1_100_000);

        $post->update(['title' => 'New title']);
        Dolt::commit('New');

        $this->assertSame('Old title', Post::asOf($between)->find($post->id)->title);
    }

    public function test_a_date_written_as_text_works_as_a_ref(): void
    {
        $post = $this->makePost('Old title');
        Dolt::commit('Old');

        usleep(1_100_000);
        $between = $this->serverNow();
        usleep(1_100_000);

        $post->update(['title' => 'New title']);
        Dolt::commit('New');

        $this->assertSame('Old title', Post::asOf($between->format('Y-m-d H:i:s'))->find($post->id)->title);
        $this->assertSame('Old title', Post::asOf($between->format('Y-m-d\TH:i:sP'))->find($post->id)->title);
        $this->assertSame('Old title', Dolt::asOf($between->format('Y-m-d H:i:s'), fn () => Post::find($post->id)->title));
        $this->assertSame('New title', Post::asOf($between->addDay()->format('Y-m-d'))->find($post->id)->title);
        $this->assertSame(['posts'], Dolt::diff($between->format('Y-m-d H:i:s'), 'HEAD')->tables()->pluck('table')->all());
    }

    public function test_a_date_before_the_first_commit_is_refused(): void
    {
        $this->expectException(InvalidRefException::class);

        Post::asOf('2000-01-01')->get();
    }

    public function test_a_short_hash_works_wherever_a_commit_does(): void
    {
        $post = $this->makePost('Old title');
        $old = Dolt::commit('Old');

        $post->update(['title' => 'New title']);
        $new = Dolt::commit('New');

        $short = substr($new->hash, 0, 8);

        $this->assertSame($new->hash, Dolt::connection()->normalize($short));
        $this->assertSame($new->hash.'~1', Dolt::connection()->normalize($short.'~1'));
        $this->assertSame($new->hash, Dolt::find($short)->hash);
        $this->assertSame('Old title', Post::asOf(substr($old->hash, 0, 8))->find($post->id)->title);
        $this->assertSame('Old title', Post::asOf($short.'~1')->find($post->id)->title);
        $this->assertSame(1, Dolt::diff($short.'~1', $short)->table('posts')->rowsModified);
        $this->assertSame(1, Dolt::diff(substr($old->hash, 0, 8).'..'.$short)->table('posts')->rowsModified);

        Dolt::revert($short);

        $this->assertSame('Old title', Post::find($post->id)->title);
    }

    public function test_a_branch_or_tag_wins_over_a_short_hash_of_the_same_name(): void
    {
        $post = $this->makePost('Old title');
        $old = Dolt::commit('Old');

        $post->update(['title' => 'New title']);
        $new = Dolt::commit('New');

        // A branch named like the start of the newest commit, pointing at the older one.
        $name = substr($new->hash, 0, 8);

        Dolt::createBranch($name, $old);

        $this->assertSame($name, Dolt::connection()->normalize($name));
        $this->assertSame('Old title', Post::asOf($name)->find($post->id)->title);
    }

    public function test_as_of_keeps_aliases_and_can_be_called_twice(): void
    {
        $this->makePost('Old title');
        $then = Dolt::commit('Old');

        Post::query()->update(['title' => 'New title']);
        Dolt::commit('New');

        $this->assertSame(['Old title'], DB::table('posts as p')->asOf($then)->pluck('p.title')->all());
        $this->assertSame(['New title'], DB::table('posts')->asOf($then)->asOf('HEAD')->pluck('title')->all());
    }

    public function test_an_unknown_ref_is_refused(): void
    {
        $this->expectException(InvalidRefException::class);

        DB::table('posts')->asOf('never-existed')->get();
    }

    public function test_the_whole_connection_can_read_the_past(): void
    {
        $this->makePost('Old post');
        $this->makeUser('Old User', 'old@example.test');
        $then = Dolt::commit('Old');

        Post::query()->delete();
        User::query()->update(['name' => 'New User']);
        Dolt::commit('New');

        $seen = Dolt::asOf($then, function () {
            $this->assertNull(Dolt::branch());

            return [Post::pluck('title')->all(), User::pluck('name')->all()];
        });

        $this->assertSame([['Old post'], ['Old User']], $seen);
        $this->assertSame('main', Dolt::branch());
        $this->assertSame(0, Post::count());
        $this->assertSame('New User', User::value('name'));
    }

    public function test_the_past_is_read_only(): void
    {
        $then = Dolt::head();

        $this->makePost();
        Dolt::commit('New');

        Dolt::asOf($then, function () {
            try {
                $this->makePost('Rewrite history');
                $this->fail('Writing to a past revision should fail.');
            } catch (Throwable $e) {
                $this->assertStringContainsStringIgnoringCase('read-only', $e->getMessage());
            }

            try {
                Dolt::commit('Nope');
                $this->fail('Committing while reading the past should be refused.');
            } catch (DoltException) {
                // expected
            }
        });

        $this->assertSame(1, Post::count());
    }

    public function test_the_connection_returns_to_the_present_when_the_callback_throws(): void
    {
        $then = Dolt::head();

        $this->makePost();
        Dolt::commit('New');

        try {
            Dolt::asOf($then, fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('main', Dolt::branch());
        $this->assertSame(1, Post::count());

        $this->makePost('Writable again');
        $this->assertNotNull(Dolt::commit('Writable again'));
    }

    public function test_the_past_can_be_read_while_on_another_branch(): void
    {
        $then = Dolt::head();

        Dolt::checkout('feature', create: true);
        $this->makePost('On feature');
        Dolt::commit('Feature');

        $this->assertSame(0, Dolt::asOf($then, fn () => Post::count()));
        $this->assertSame('feature', Dolt::branch());
        $this->assertSame(1, Post::count());
    }
}
