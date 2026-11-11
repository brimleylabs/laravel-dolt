<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | The database connection the Dolt facade and middleware work with. Leave
    | it empty to use the application's default connection. The connection
    | must use the "dolt" driver, or the "doltgres" driver on Postgres.
    |
    */

    'connection' => env('DOLT_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Home branch
    |--------------------------------------------------------------------------
    |
    | The branch the ready made "dolt" and "doltgres" connections work on. Leave it empty to
    | use the default branch of the database. Point staging at a "staging"
    | branch and production at "main" and both can share one server.
    |
    */

    'branch' => env('DOLT_BRANCH'),

    /*
    |--------------------------------------------------------------------------
    | System author
    |--------------------------------------------------------------------------
    |
    | Commits made while a user is signed in are credited to that user. This
    | author is used for everything else: queued jobs, console commands,
    | migrations and requests from guests. The name falls back to the
    | application name.
    |
    */

    'author' => [
        'name' => env('DOLT_AUTHOR_NAME'),
        'email' => env('DOLT_AUTHOR_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic commits
    |--------------------------------------------------------------------------
    |
    | Requests through the "dolt" middleware, queued jobs, console commands
    | and migrations are recorded as commits. Two modes are available.
    |
    | "transaction"  Dolt commits every SQL transaction as it happens. Each
    |                commit carries the request, job or command as its
    |                message and is credited to the signed in user, or the
    |                system author. Attribution is exact even when requests
    |                overlap. A request that writes three times outside a
    |                DB::transaction() makes three commits, which
    |                "dolt:log" and Dolt::activity() show as one entry.
    |
    | "request"      One commit when the request, job or command ends. Fewer
    |                commits, and failed requests are marked in the log. Use
    |                it only when one process writes to a branch at a time:
    |                Dolt commits everything pending on a branch, so writes
    |                from overlapping requests can land in one commit under
    |                one name.
    |
    */

    'commit' => [
        'mode' => env('DOLT_COMMIT_MODE', 'transaction'),
        'queue' => true,
        'console' => true,
        'migrations' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Adjusted statements
    |--------------------------------------------------------------------------
    |
    | A few statements cannot run on the server exactly as Laravel writes
    | them, and the package runs a version that does a little less:
    |
    | - A foreign key with an "on delete" or "on update" rule that touches a
    |   table on the ignore list is left out. Dolt refuses those rules.
    | - On Doltgres, lockForUpdate() and sharedLock() run without the lock.
    |   Doltgres does not lock rows yet.
    |
    | "warn"   run the adjusted statement and log a warning the first time
    | "allow"  run the adjusted statement quietly
    | "fail"   throw an exception instead
    |
    | The StatementAdjusted event is fired every time, whichever is chosen.
    |
    */

    'adjustments' => env('DOLT_ADJUSTMENTS', 'warn'),

    /*
    |--------------------------------------------------------------------------
    | Tables outside version control
    |--------------------------------------------------------------------------
    |
    | Sessions, cache entries and queued jobs change on every request and say
    | nothing in a diff. Tables that match these patterns are left out of
    | commits, and every branch shares one copy of them, so a user stays signed
    | in while working in a workspace. "%" matches any run of characters.
    |
    | The list is applied when migrations run on a new database. For a
    | database that already versions one of these tables, run
    | "php artisan dolt:ignore" once. Set an empty list to switch this off.
    |
    */

    'ignore' => [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'telescope_%',
        'pulse_%',
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspaces
    |--------------------------------------------------------------------------
    |
    | A workspace is a branch where changes wait until they are published.
    | Workspace branches share this prefix. The session key remembers which
    | workspace a user is working in.
    |
    */

    'workspaces' => [
        'prefix' => 'workspace/',
        'session_key' => 'dolt.workspace',
    ],

    /*
    |--------------------------------------------------------------------------
    | Previews
    |--------------------------------------------------------------------------
    |
    | Dolt::preview() runs a block of code on a throwaway branch. Previews that
    | were neither applied nor discarded are removed by "dolt:prune" once they
    | are older than this many hours.
    |
    */

    'previews' => [
        'prefix' => 'preview/',
        'expire_after_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    |
    | Dolt stores commit dates in UTC. They are converted to this timezone when
    | read. Leave it empty to use the application timezone.
    |
    */

    'timezone' => null,

];
