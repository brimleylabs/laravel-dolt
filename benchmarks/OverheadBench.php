<?php

namespace BrimleyLabs\Dolt\Benchmarks;

use BrimleyLabs\Dolt\Facades\Dolt;
use BrimleyLabs\Dolt\Tests\Fixtures\Post;
use BrimleyLabs\Dolt\Tests\TestCase;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * What the package adds to a request, measured end to end through the HTTP kernel.
 *
 *   vendor/bin/phpunit benchmarks/OverheadBench.php
 *
 * Takes the same DOLT_TEST_* settings as the test suite. BENCH_REQUESTS sets
 * how many requests each case sends per action (200 by default). Results are
 * printed and written to build/overhead-<server>.md.
 *
 * The cases take turns in rounds, so a server that speeds up or slows down
 * during the run affects every case the same way.
 */
class OverheadBench extends TestCase
{
    protected const ROUNDS = 10;

    protected function defineWebRoutes($router): void
    {
        $read = fn () => Post::orderByDesc('id')->limit(10)->pluck('title');
        $write = fn () => Post::create(['title' => 'Benchmark'])->id;
        $writeThree = function () {
            Post::create(['title' => 'One']);
            Post::create(['title' => 'Two']);

            return Post::create(['title' => 'Three'])->id;
        };

        $router->get('/plain/read', $read);
        $router->post('/plain/write', $write);
        $router->post('/plain/write-three', $writeThree);

        $router->middleware('dolt')->group(function () use ($router, $read, $write, $writeThree) {
            $router->get('/dolt/read', $read);
            $router->post('/dolt/write', $write);
            $router->post('/dolt/write-three', $writeThree);
        });
    }

    public function test_measure_the_overhead(): void
    {
        $requests = (int) static::env('BENCH_REQUESTS', '200');
        $server = static::onDoltgres() ? 'Doltgres' : 'Dolt';

        // The same database through Laravel's own driver, with nothing from this package in the way.
        $plain = config('database.connections.dolt');
        $plain['driver'] = static::onDoltgres() ? 'pgsql' : 'mysql';
        config(['database.connections.plain' => $plain, 'dolt.connection' => 'dolt']);

        for ($i = 0; $i < 10; $i++) {
            $this->makePost("Seed {$i}");
        }

        $seed = Dolt::commit('Seed')->hash;

        $cases = [
            'Laravel driver, no package' => ['plain', null, '/plain'],
            'dolt driver, no middleware' => ['dolt', null, '/plain'],
            'dolt middleware, transaction mode' => ['dolt', 'transaction', '/dolt'],
            'dolt middleware, request mode' => ['dolt', 'request', '/dolt'],
        ];

        $samples = [];

        foreach (['read' => 'get', 'write' => 'post', 'write-three' => 'post'] as $action => $method) {
            $use = function (array $case) {
                [$connection, $mode] = $case;

                config(['database.default' => $connection, 'dolt.commit.mode' => $mode ?? 'transaction']);
            };

            foreach ($cases as $case) {
                $use($case);
                $this->send(10, fn () => $this->{$method}("{$case[2]}/{$action}")->assertSuccessful());
            }

            for ($round = 0; $round < self::ROUNDS; $round++) {
                foreach ($cases as $label => $case) {
                    $use($case);

                    $samples[$label][$action] = [
                        ...$samples[$label][$action] ?? [],
                        ...$this->send(intdiv($requests, self::ROUNDS), fn () => $this->{$method}("{$case[2]}/{$action}")->assertSuccessful()),
                    ];
                }
            }

            // Each action starts from the same data, with nothing pending.
            DB::purge('plain');
            Dolt::reset($seed, force: true);
        }

        $report = $this->report($server, $requests, $samples);

        fwrite(STDOUT, "\n".$report."\n");

        @mkdir(dirname(__DIR__).'/build', 0777, true);
        file_put_contents(dirname(__DIR__).'/build/overhead-'.strtolower($server).'.md', $report);

        $this->assertNotEmpty($samples);
    }

    /**
     * @return list<float> milliseconds per request
     */
    protected function send(int $requests, Closure $request): array
    {
        $samples = [];

        for ($i = 0; $i < $requests; $i++) {
            $start = hrtime(true);
            $request();
            $samples[] = (hrtime(true) - $start) / 1e6;
        }

        return $samples;
    }

    /**
     * @param  array<string, array<string, list<float>>>  $samples
     */
    protected function report(string $server, int $requests, array $samples): string
    {
        $version = DB::connection('dolt')->selectOne('select dolt_version() as v')->v;

        $lines = [
            "### {$server} {$version}, PHP ".PHP_VERSION.', Laravel '.app()->version().", {$requests} requests per case",
            '',
            '| Case | Read: median / p95 ms | Write one row | Write three rows |',
            '| --- | --- | --- | --- |',
        ];

        foreach ($samples as $label => $actions) {
            $cells = array_map(function (array $times) {
                sort($times);

                return sprintf('%.2f / %.2f', $times[intdiv(count($times), 2)], $times[(int) floor(count($times) * 0.95) - 1]);
            }, $actions);

            $lines[] = "| {$label} | ".implode(' | ', $cells).' |';
        }

        return implode("\n", $lines);
    }
}
