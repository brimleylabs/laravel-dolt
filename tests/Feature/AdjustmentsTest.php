<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Events\StatementAdjusted;
use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Support\Adjustments;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Statements the server cannot run as written are never changed quietly unless asked.
 */
class AdjustmentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Adjustments::forget();

        Dolt::ignore('telescope_%');
    }

    protected function createTelescopeTables(): void
    {
        // The shape of the Telescope migration.
        Schema::create('telescope_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
        });

        Schema::create('telescope_entries_tags', function (Blueprint $table) {
            $table->uuid('entry_uuid');
            $table->string('tag');
            $table->foreign('entry_uuid')->references('uuid')->on('telescope_entries')->onDelete('cascade');
        });
    }

    public function test_a_left_out_foreign_key_is_logged_and_announced(): void
    {
        Log::spy();
        Event::fake([StatementAdjusted::class]);

        $this->createTelescopeTables();

        $this->assertSame([], Schema::getForeignKeys('telescope_entries_tags'));

        Event::assertDispatched(StatementAdjusted::class, fn (StatementAdjusted $event) => $event->kind === 'cascade_foreign_key'
            && $event->connection === 'dolt'
            && str_contains($event->message, 'from telescope_entries_tags (entry_uuid) to telescope_entries (uuid)')
            && str_contains($event->message, 'The key was left out.'));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_starts_with($message, '[dolt] The foreign key from telescope_entries_tags'));
    }

    public function test_a_left_out_foreign_key_can_be_allowed_quietly(): void
    {
        config(['dolt.adjustments' => 'allow']);

        Log::spy();
        Event::fake([StatementAdjusted::class]);

        $this->createTelescopeTables();

        Event::assertDispatchedTimes(StatementAdjusted::class, 1);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_left_out_foreign_key_can_be_refused(): void
    {
        config(['dolt.adjustments' => 'fail']);

        $this->expectException(DoltException::class);
        $this->expectExceptionMessage('Dolt refuses those on tables shared between branches.');

        $this->createTelescopeTables();
    }

    public function test_a_row_lock_left_off_on_doltgres_is_announced(): void
    {
        Log::spy();
        Event::fake([StatementAdjusted::class]);

        $this->makePost('Locked');

        $this->assertSame(['Locked'], Post::lockForUpdate()->pluck('title')->all());
        $this->assertSame(['Locked'], Post::sharedLock()->pluck('title')->all());

        if (! static::onDoltgres()) {
            // Dolt takes the lock as written.
            Event::assertNotDispatched(StatementAdjusted::class);

            return;
        }

        Event::assertDispatchedTimes(StatementAdjusted::class, 2);
        Event::assertDispatched(StatementAdjusted::class, fn (StatementAdjusted $event) => $event->kind === 'row_lock'
            && str_contains($event->message, 'A query on posts asks for a row lock'));

        // Warned once, not on every query.
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_doltgres_is_asked_what_it_supports_without_harming_an_open_transaction(): void
    {
        $this->onlyOnDoltgres('Dolt supports both.');

        DB::transaction(function () {
            $this->assertFalse($this->connection()->supports('row_locks'));
            $this->assertFalse($this->connection()->supports('ilike'));

            // The refused probes did not end the transaction.
            $this->makePost('Inside');
        });

        $this->assertSame(['Inside'], Post::pluck('title')->all());
    }

    public function test_the_workarounds_step_aside_for_a_doltgres_that_has_caught_up(): void
    {
        $this->onlyOnDoltgres('Dolt needs no workarounds here.');

        Event::fake([StatementAdjusted::class]);

        $connection = $this->connection();

        $this->assertStringNotContainsString('for update', Post::lockForUpdate()->toSql());
        $this->assertStringNotContainsString('ilike', Post::where('title', 'ilike', 'a%')->toSql());

        // As a newer Doltgres would answer.
        (fn () => $this->features = ['row_locks' => true, 'ilike' => true])->call($connection);

        $this->assertStringContainsString('for update', Post::lockForUpdate()->toSql());
        $this->assertStringContainsString('ilike', Post::where('title', 'ilike', 'a%')->toSql());

        Event::assertDispatchedTimes(StatementAdjusted::class, 1);
    }

    public function test_the_doctor_names_the_tested_version_and_the_workarounds_in_use(): void
    {
        $this->artisan('dolt:doctor')
            ->expectsOutputToContain('Tested with')
            ->assertExitCode(0);

        if (static::onDoltgres()) {
            $this->artisan('dolt:doctor')
                ->expectsOutputToContain('not supported yet, left off')
                ->expectsOutputToContain('not supported yet, rewritten with lower()')
                ->assertExitCode(0);
        }
    }

    public function test_a_row_lock_can_be_refused_on_doltgres(): void
    {
        $this->onlyOnDoltgres('Dolt locks rows.');

        config(['dolt.adjustments' => 'fail']);

        $this->expectException(DoltException::class);
        $this->expectExceptionMessage('Doltgres does not lock rows yet.');

        Post::lockForUpdate()->get();
    }
}
