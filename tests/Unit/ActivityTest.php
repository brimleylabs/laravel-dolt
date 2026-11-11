<?php

namespace BrimleyLabs\Dolt\Tests\Unit;

use BrimleyLabs\Dolt\Data\Activity;
use BrimleyLabs\Dolt\Data\Author;
use BrimleyLabs\Dolt\Data\Commit;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ActivityTest extends TestCase
{
    protected function commit(string $hash, string $message, ?string $scope = null, string $parent = ''): Commit
    {
        $author = new Author('Ada Lovelace', 'ada@example.test');

        return new Commit($hash, $message, $author, $author, CarbonImmutable::now(), $parent !== '' ? [$parent] : [], $scope);
    }

    public function test_the_scope_line_is_taken_off_the_message(): void
    {
        $this->assertSame(['POST /posts', 'a1b2c3d4e5f6'], Commit::splitScope("POST /posts\n\nDolt-Scope: a1b2c3d4e5f6"));
        $this->assertSame(["Run 2 migrations\n\nfirst\nsecond", 'abc'], Commit::splitScope("Run 2 migrations\n\nfirst\nsecond\n\nDolt-Scope: abc\n"));
        $this->assertSame(['Close the March books', null], Commit::splitScope('Close the March books'));

        // Only a last line of that exact shape counts.
        $this->assertSame(['Notes: Dolt-Scope: abc is mentioned here', null], Commit::splitScope('Notes: Dolt-Scope: abc is mentioned here'));
    }

    public function test_a_commit_read_from_the_log_keeps_its_scope_apart(): void
    {
        $commit = Commit::fromLogRow([
            'commit_hash' => 'h1',
            'message' => "POST /posts\n\nDolt-Scope: a1b2c3d4e5f6",
            'author' => 'Ada Lovelace',
            'author_email' => 'ada@example.test',
            'committer' => 'root',
            'email' => 'root@localhost',
            'date' => '2031-03-02 14:42:00',
        ]);

        $this->assertSame('POST /posts', $commit->message);
        $this->assertSame('POST /posts', $commit->subject());
        $this->assertSame('a1b2c3d4e5f6', $commit->scope);
        $this->assertSame('a1b2c3d4e5f6', $commit->toArray()['scope']);
    }

    public function test_commits_are_gathered_by_scope_even_when_requests_overlap(): void
    {
        // Newest first. Two requests overlapped, so their commits are interleaved.
        $activities = Activity::gather([
            $this->commit('h5', 'POST /orders', 'b', 'h4'),
            $this->commit('h4', 'POST /invoices', 'a', 'h3'),
            $this->commit('h3', 'POST /orders', 'b', 'h2'),
            $this->commit('h2', 'POST /invoices', 'a', 'h1'),
            $this->commit('h1', 'Merge feature into main', null, 'h0'),
            $this->commit('h0', 'Close the March books', null),
        ]);

        $this->assertSame(['POST /orders', 'POST /invoices', 'Merge feature into main', 'Close the March books'], $activities->map->subject()->all());
        $this->assertSame([2, 2, 1, 1], $activities->map->count()->all());

        $orders = $activities->first();

        $this->assertSame('h5', $orders->head()->hash);
        $this->assertSame(['h5', 'h3'], array_map(fn ($commit) => $commit->hash, $orders->commits));
        $this->assertSame('h2', $orders->base());
        $this->assertSame('Ada Lovelace', $orders->author()->name);
    }

    public function test_an_activity_without_parents_points_before_itself(): void
    {
        $activity = Activity::gather([$this->commit('h0', 'First')])->first();

        $this->assertSame('h0~1', $activity->base());
        $this->assertNull($activity->scope());
        $this->assertCount(1, $activity->toArray()['commits']);
    }
}
