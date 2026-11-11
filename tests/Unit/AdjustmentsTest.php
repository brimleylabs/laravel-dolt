<?php

namespace BrimleyLabs\Dolt\Tests\Unit;

use BrimleyLabs\Dolt\Events\StatementAdjusted;
use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Support\Adjustments;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

class AdjustmentsTest extends TestCase
{
    /** @var list<string> */
    protected array $warnings = [];

    /** @var list<StatementAdjusted> */
    protected array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        Adjustments::forget();

        $container = new Container;
        $container->instance('config', new Config(['dolt' => ['adjustments' => 'warn']]));

        $events = new Dispatcher($container);
        $events->listen(StatementAdjusted::class, fn (StatementAdjusted $event) => $this->events[] = $event);
        $container->instance('events', $events);

        $container->instance('log', new class($this->warnings)
        {
            /** @param  list<string>  $warnings */
            public function __construct(protected array &$warnings) {}

            public function warning(string $message): void
            {
                $this->warnings[] = $message;
            }
        });

        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    protected function mode(string $mode): void
    {
        Container::getInstance()['config']->set('dolt.adjustments', $mode);
    }

    public function test_warn_logs_each_adjustment_once_and_announces_it_every_time(): void
    {
        Adjustments::make('dolt', 'row_lock', 'A query on jobs asks for a row lock.', 'It ran without it.');
        Adjustments::make('dolt', 'row_lock', 'A query on jobs asks for a row lock.', 'It ran without it.');
        Adjustments::make('dolt', 'row_lock', 'A query on posts asks for a row lock.', 'It ran without it.');

        $this->assertSame([
            '[dolt] A query on jobs asks for a row lock. It ran without it.',
            '[dolt] A query on posts asks for a row lock. It ran without it.',
        ], $this->warnings);

        $this->assertCount(3, $this->events);
        $this->assertSame('row_lock', $this->events[0]->kind);
        $this->assertSame('dolt', $this->events[0]->connection);
    }

    public function test_allow_stays_quiet_but_still_announces(): void
    {
        $this->mode('allow');

        Adjustments::make('dolt', 'cascade_foreign_key', 'Problem.', 'Adjusted.');

        $this->assertSame([], $this->warnings);
        $this->assertCount(1, $this->events);
    }

    public function test_fail_refuses_and_names_the_setting(): void
    {
        $this->mode('fail');

        try {
            Adjustments::make('dolt', 'cascade_foreign_key', 'Dolt refuses this.', 'Adjusted.');

            $this->fail('The adjustment should have been refused.');
        } catch (DoltException $e) {
            $this->assertStringStartsWith('Dolt refuses this.', $e->getMessage());
            $this->assertStringContainsString('"adjustments" in config/dolt.php', $e->getMessage());
        }

        $this->assertSame([], $this->events);
    }

    public function test_an_unknown_setting_falls_back_to_warn(): void
    {
        $this->mode('loudly');

        $this->assertSame(Adjustments::WARN, Adjustments::mode());
    }
}
