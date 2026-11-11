<?php

namespace BrimleyLabs\Dolt\Support;

use BrimleyLabs\Dolt\Events\StatementAdjusted;
use BrimleyLabs\Dolt\Exceptions\DoltException;
use Illuminate\Container\Container;
use Throwable;

/**
 * Decides what happens when a statement cannot run on the server exactly as written.
 *
 * The "adjustments" setting in config/dolt.php picks one of:
 *
 *   "warn"   run the adjusted statement and log a warning, once per kind and subject
 *   "allow"  run the adjusted statement quietly
 *   "fail"   throw instead of running anything
 *
 * StatementAdjusted is fired every time a statement is adjusted.
 */
final class Adjustments
{
    public const WARN = 'warn';

    public const ALLOW = 'allow';

    public const FAIL = 'fail';

    /** @var array<string, true> */
    protected static array $warned = [];

    /**
     * Record an adjustment, or refuse it in "fail" mode.
     *
     * @param  string  $problem  what the server cannot do
     * @param  string  $adjustment  what runs instead
     *
     * @throws DoltException
     */
    public static function make(string $connection, string $kind, string $problem, string $adjustment): void
    {
        $mode = self::mode();

        if ($mode === self::FAIL) {
            throw new DoltException($problem.' Set "adjustments" in config/dolt.php to "warn" or "allow" to run an adjusted statement instead.');
        }

        $message = $problem.' '.$adjustment;
        $app = Container::getInstance();

        try {
            if ($app->bound('events')) {
                $app['events']->dispatch(new StatementAdjusted($connection, $kind, $message));
            }

            if ($mode === self::WARN && ! isset(self::$warned[$kind.'|'.$message]) && $app->bound('log')) {
                self::$warned[$kind.'|'.$message] = true;

                $app['log']->warning('[dolt] '.$message);
            }
        } catch (Throwable) {
            // Telling someone must never stop the statement.
        }
    }

    public static function mode(): string
    {
        $app = Container::getInstance();

        $mode = $app->bound('config') ? $app['config']->get('dolt.adjustments', self::WARN) : self::WARN;

        return in_array($mode, [self::ALLOW, self::FAIL], true) ? $mode : self::WARN;
    }

    /**
     * @internal Forget which warnings were logged, for tests.
     */
    public static function forget(): void
    {
        self::$warned = [];
    }
}
