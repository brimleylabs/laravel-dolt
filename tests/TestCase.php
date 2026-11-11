<?php

namespace BrimleyLabs\Dolt\Tests;

use BrimleyLabs\Dolt\Database\DoltConnection;
use BrimleyLabs\Dolt\DoltServiceProvider;
use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\Fixtures\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use PDO;

/**
 * Every test runs against its own, freshly created Dolt database.
 *
 * DOLT_TEST_DRIVER picks the server: "mysql" for Dolt, the default, or
 * "pgsql" for Doltgres. The connection is named "dolt" either way.
 */
abstract class TestCase extends Orchestra
{
    protected string $databaseName;

    /**
     * The commit mode a test class needs. Null runs the mode DOLT_TEST_COMMIT_MODE names, "transaction" by default.
     */
    protected ?string $commitMode = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['dolt.commit.mode' => $this->commitMode ?? static::env('DOLT_TEST_COMMIT_MODE', 'transaction')]);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->default('secret');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Dolt::commit('Create tables', 'Test Suite <suite@example.test>');
    }

    protected function tearDown(): void
    {
        try {
            DB::disconnect('dolt');
        } finally {
            static::admin()->exec('drop database if exists '.static::quoted($this->databaseName));

            parent::tearDown();
        }
    }

    protected function getPackageProviders($app): array
    {
        return [DoltServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->databaseName = 'ld_test_'.bin2hex(random_bytes(6));

        static::admin()->exec('create database '.static::quoted($this->databaseName));

        $app['config']->set('database.default', 'dolt');
        $app['config']->set('database.connections.dolt', static::onDoltgres() ? [
            'driver' => 'doltgres',
            'host' => static::env('DOLT_TEST_HOST', '127.0.0.1'),
            'port' => static::env('DOLT_TEST_PORT', '5432'),
            'database' => $this->databaseName,
            'username' => static::env('DOLT_TEST_USERNAME', 'postgres'),
            'password' => static::env('DOLT_TEST_PASSWORD', 'password'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ] : [
            'driver' => 'dolt',
            'host' => static::env('DOLT_TEST_HOST', '127.0.0.1'),
            'port' => static::env('DOLT_TEST_PORT', '3306'),
            'database' => $this->databaseName,
            'username' => static::env('DOLT_TEST_USERNAME', 'root'),
            'password' => static::env('DOLT_TEST_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.name', 'Test App');
        $app['config']->set('app.url', 'https://app.example.test');
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function connection(): DoltConnection
    {
        return DB::connection('dolt');
    }

    protected function makePost(string $title = 'Hello', array $attributes = []): Post
    {
        return Post::create(['title' => $title] + $attributes);
    }

    protected function makeUser(string $name = 'Ada Lovelace', string $email = 'ada@example.test'): User
    {
        return User::create(['name' => $name, 'email' => $email]);
    }

    /**
     * The database server's clock. Commits are stamped with it, and it need not match this machine's.
     */
    protected function serverNow(): CarbonImmutable
    {
        if (static::onDoltgres()) {
            return CarbonImmutable::createFromTimestampUTC(
                (float) $this->connection()->selectOne('select extract(epoch from now()) as moment')->moment
            );
        }

        return CarbonImmutable::parse($this->connection()->selectOne('select utc_timestamp(6) as moment')->moment, 'UTC');
    }

    /**
     * What the server holds for one of this session's variables.
     */
    protected function sessionVariable(string $name): string
    {
        if (static::onDoltgres()) {
            return (string) (array_values((array) $this->connection()->selectOne("show {$name}"))[0] ?? '');
        }

        return (string) $this->connection()->selectOne("select @@session.{$name} as value")->value;
    }

    protected static function onDoltgres(): bool
    {
        return static::env('DOLT_TEST_DRIVER', 'mysql') === 'pgsql';
    }

    /**
     * Skip a test that leans on something only one of the two servers offers.
     */
    protected function onlyOnDolt(string $reason): void
    {
        if (static::onDoltgres()) {
            $this->markTestSkipped('Doltgres: '.$reason);
        }
    }

    protected function onlyOnDoltgres(string $reason): void
    {
        if (! static::onDoltgres()) {
            $this->markTestSkipped('Dolt: '.$reason);
        }
    }

    protected static function quoted(string $name): string
    {
        return static::onDoltgres() ? '"'.$name.'"' : '`'.$name.'`';
    }

    protected static function admin(): PDO
    {
        if (static::onDoltgres()) {
            return new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=postgres', static::env('DOLT_TEST_HOST', '127.0.0.1'), static::env('DOLT_TEST_PORT', '5432')),
                static::env('DOLT_TEST_USERNAME', 'postgres'),
                static::env('DOLT_TEST_PASSWORD', 'password'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        }

        return new PDO(
            sprintf('mysql:host=%s;port=%s', static::env('DOLT_TEST_HOST', '127.0.0.1'), static::env('DOLT_TEST_PORT', '3306')),
            static::env('DOLT_TEST_USERNAME', 'root'),
            static::env('DOLT_TEST_PASSWORD', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    protected static function env(string $key, string $default): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) ? $value : $default;
    }
}
