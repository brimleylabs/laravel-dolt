<?php

namespace BrimleyLabs\Dolt\Tests\Feature;

use BrimleyLabs\Dolt\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImplicitDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->onlyOnDolt('filling required columns is a MySQL habit, and Postgres applications never relied on it.');
    }

    protected function looseConnection(array $overrides = []): string
    {
        config(['database.connections.loose' => array_merge(
            config('database.connections.dolt'),
            ['strict' => false],
            $overrides,
        )]);

        DB::purge('loose');

        return 'loose';
    }

    protected function fillsDefaults(string $connection): bool
    {
        // The schema grammar is built the first time the schema builder is asked for.
        return DB::connection($connection)->getSchemaBuilder()->getConnection()->getSchemaGrammar()->usesImplicitDefaults();
    }

    protected function createLegacyTable(string $connection): void
    {
        Schema::connection($connection)->create('legacy', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->char('code', 4);
            $table->text('notes');
            $table->integer('position');
            $table->boolean('active');
            $table->decimal('price', 8, 2);
            $table->enum('state', ['open', 'closed']);
            $table->json('options');
            $table->dateTime('seen_at');
            $table->date('born_on');
            $table->string('nickname')->nullable();
            $table->string('role')->default('viewer');
            $table->timestamp('joined_at')->useCurrent();
        });
    }

    public function test_a_strict_connection_keeps_required_columns_required(): void
    {
        Schema::create('legacy', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        $this->expectException(QueryException::class);

        DB::table('legacy')->insert(['id' => 1]);
    }

    public function test_a_loose_connection_fills_required_columns_like_mysql_does(): void
    {
        $connection = $this->looseConnection();

        $this->createLegacyTable($connection);

        DB::connection($connection)->table('legacy')->insert(['id' => 1]);

        $row = DB::connection($connection)->table('legacy')->first();

        $this->assertSame('', $row->name);
        $this->assertSame('', $row->code);
        $this->assertSame('', $row->notes);
        $this->assertSame(0, (int) $row->position);
        $this->assertSame(0, (int) $row->active);
        $this->assertSame('0.00', $row->price);
        $this->assertSame('open', $row->state);
        $this->assertSame('null', $row->options);
        $this->assertSame('0000-00-00 00:00:00', $row->seen_at);
        $this->assertSame('0000-00-00', $row->born_on);
        $this->assertNull($row->nickname);
        $this->assertSame('viewer', $row->role);
        $this->assertNotSame('0000-00-00 00:00:00', $row->joined_at);
    }

    public function test_columns_added_or_changed_later_are_filled_too(): void
    {
        $connection = $this->looseConnection();

        Schema::connection($connection)->create('legacy', function (Blueprint $table) {
            $table->id();
            $table->string('summary')->nullable();
        });

        Schema::connection($connection)->table('legacy', function (Blueprint $table) {
            $table->string('slug');
            $table->text('summary')->nullable(false)->change();
        });

        DB::connection($connection)->table('legacy')->insert(['id' => 1]);

        $row = DB::connection($connection)->table('legacy')->first();

        $this->assertSame('', $row->slug);
        $this->assertSame('', $row->summary);
    }

    public function test_the_behaviour_can_be_forced_either_way(): void
    {
        $forcedOff = $this->looseConnection(['implicit_defaults' => false]);

        $this->assertFalse($this->fillsDefaults($forcedOff));

        $forcedOn = $this->looseConnection(['strict' => true, 'implicit_defaults' => true]);

        $this->assertTrue($this->fillsDefaults($forcedOn));
        $this->assertFalse($this->fillsDefaults('dolt'));
    }
}
