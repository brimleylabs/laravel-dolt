<?php

namespace BrimleyLabs\Dolt\Tests\Unit;

use BrimleyLabs\Dolt\Commits\AutoCommit;
use BrimleyLabs\Dolt\Data\Author;
use BrimleyLabs\Dolt\Data\Commit;
use BrimleyLabs\Dolt\Database\DoltConnection;
use BrimleyLabs\Dolt\DoltManager;
use Illuminate\Config\Repository as Config;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * Scopes decide the message and author that Dolt puts on each transaction commit.
 */
class AutoCommitTest extends TestCase
{
    protected Config $config;

    protected AutoCommit $commits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new Config(['dolt' => ['commit' => ['mode' => 'transaction']]]);

        // No database: nothing is opened, and whoever is signed in is Grace.
        $dolt = new class(new Application(sys_get_temp_dir())) extends DoltManager
        {
            public function opened(): array
            {
                return [];
            }

            public function author(mixed $author = null): Author
            {
                return $author !== null ? Author::from($author) : new Author('Grace Hopper', 'grace@example.test');
            }

            public function systemAuthor(): Author
            {
                return new Author('Test App', 'system@example.test');
            }
        };

        $this->commits = new AutoCommit($dolt, $this->config);
    }

    /**
     * The variables a connection opened right now would be given, or null for none.
     *
     * @return array<string, mixed>|null
     */
    protected function adopted(): ?array
    {
        $given = null;

        $connection = $this->createStub(DoltConnection::class);
        $connection->method('setSessionVariables')->willReturnCallback(function (array $variables) use (&$given, $connection) {
            $given = $variables;

            return $connection;
        });

        $this->commits->adopt($connection);

        // The scope line is checked on its own below. Here the message reads as a person would see it.
        if (isset($given['dolt_transaction_commit_message'])) {
            [$given['dolt_transaction_commit_message']] = Commit::splitScope((string) $given['dolt_transaction_commit_message']);
        }

        return $given;
    }

    protected function scopeOnSession(): ?string
    {
        $given = null;

        $connection = $this->createStub(DoltConnection::class);
        $connection->method('setSessionVariables')->willReturnCallback(function (array $variables) use (&$given, $connection) {
            $given = $variables;

            return $connection;
        });

        $this->commits->adopt($connection);

        return Commit::splitScope((string) ($given['dolt_transaction_commit_message'] ?? ''))[1];
    }

    public function test_every_scope_marks_its_commits_with_an_id_of_its_own(): void
    {
        $outer = $this->commits->begin('POST /import');
        $first = $this->scopeOnSession();

        $this->commits->label('Import the spring catalogue');

        $this->assertSame($first, $this->scopeOnSession());
        $this->assertMatchesRegularExpression('/^[a-z0-9]{12}$/', (string) $first);

        $this->commits->begin('artisan posts:tidy');

        $this->assertNotSame($first, $this->scopeOnSession());

        $this->commits->end($outer);
        $this->commits->begin('POST /import');

        $this->assertNotSame($first, $this->scopeOnSession());
    }

    public function test_a_scope_puts_its_message_and_author_on_the_session(): void
    {
        $this->commits->begin('POST /posts');

        $this->assertSame([
            'dolt_transaction_commit' => 1,
            'dolt_transaction_commit_message' => 'POST /posts',
            'dolt_author_name' => 'Grace Hopper',
            'dolt_author_email' => 'grace@example.test',
        ], $this->adopted());
    }

    public function test_scopes_nest_and_the_outer_one_comes_back(): void
    {
        $outer = $this->commits->begin('POST /import');
        $inner = $this->commits->begin('artisan posts:tidy', 'Ann Example <ann@example.test>');

        $this->assertSame('artisan posts:tidy', $this->adopted()['dolt_transaction_commit_message']);
        $this->assertSame('Ann Example', $this->adopted()['dolt_author_name']);

        $this->commits->end($inner);

        $this->assertSame('POST /import', $this->adopted()['dolt_transaction_commit_message']);
        $this->assertSame('Grace Hopper', $this->adopted()['dolt_author_name']);

        $this->commits->end($outer);

        $this->assertNull($this->adopted());
        $this->assertFalse($this->commits->active());
    }

    public function test_ending_an_outer_scope_closes_the_ones_inside_it(): void
    {
        $outer = $this->commits->begin('Outer');
        $this->commits->begin('Inner, never ended');

        $this->commits->end($outer);

        $this->assertFalse($this->commits->active());

        // A token from a scope already closed changes nothing.
        $this->commits->begin('Next');
        $this->commits->end(5);

        $this->assertSame('Next', $this->adopted()['dolt_transaction_commit_message']);
    }

    public function test_a_label_renames_the_current_scope(): void
    {
        $this->commits->begin('POST /invoices/1042/approve');

        $this->assertTrue($this->commits->label('Approve invoice 1042'));
        $this->assertSame('Approve invoice 1042', $this->adopted()['dolt_transaction_commit_message']);
    }

    public function test_a_message_is_only_worked_out_when_needed_and_only_once(): void
    {
        $calls = 0;

        $this->commits->begin(function () use (&$calls) {
            $calls++;

            return 'Described';
        });

        $this->commits->refreshAuthor();
        $this->commits->label('Relabelled');

        $this->assertSame(1, $calls);
        $this->assertSame('Relabelled', $this->adopted()['dolt_transaction_commit_message']);
    }

    public function test_an_empty_message_is_named_update(): void
    {
        $this->commits->begin('   ');

        $this->assertSame('Update', $this->adopted()['dolt_transaction_commit_message']);
    }

    public function test_request_mode_sends_nothing_to_the_server(): void
    {
        $this->config->set('dolt.commit.mode', 'request');

        $this->commits->begin('POST /posts');

        $this->assertNull($this->adopted());
        $this->assertFalse($this->commits->label('Ignored'));
        $this->assertFalse($this->commits->perTransaction());
    }

    public function test_reset_closes_every_scope(): void
    {
        $this->commits->begin('One');
        $this->commits->begin('Two');

        $this->commits->reset();

        $this->assertFalse($this->commits->active());
        $this->assertNull($this->adopted());
    }

    public function test_an_unknown_mode_is_transaction_mode(): void
    {
        $this->config->set('dolt.commit.mode', 'sometimes');

        $this->assertSame(AutoCommit::TRANSACTION, $this->commits->mode());
    }
}
