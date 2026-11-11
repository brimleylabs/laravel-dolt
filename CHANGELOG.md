# Changelog

## 0.1.0

The first release.

- `dolt` and `doltgres` database drivers. Dolt speaks MySQL and Doltgres speaks Postgres. Both are used through the same API.
- Previews: an agent writes on a throwaway branch, the diff is reviewed, then it is applied or discarded.
- Workspaces: named drafts for people, routed per user by the `dolt` middleware.
- Automatic commits for web requests, queued jobs, artisan commands and migrations, each with an author. By default Dolt commits every transaction as it happens, labelled and credited to the request, job or command it belongs to, so attribution stays exact when writers overlap. `DOLT_COMMIT_MODE=request` makes one commit per request instead, for branches with one writer at a time.
- A failed automatic commit is tried once more, then reported with a `CommitFailed` event.
- `Dolt::activity()` and `dolt:log` show the history one request, job or command at a time, however many commits each made. `dolt:log --all` lists every commit.
- Doltgres: row locks and `ilike` are checked against the server once per connection, so the workarounds step aside on a Doltgres that supports them. `dolt:doctor` shows the version the package was tested with and the workarounds in use.
- Doltgres: an insert into a shared table that draws a row number already taken is tried again when it is not inside a transaction.
- Statements that have to be adjusted to run (a cascade rule on a shared table, a row lock on Doltgres) log a warning and fire `StatementAdjusted`. The `adjustments` setting can silence them or refuse them instead.
- Undo with `Dolt::revert()` and `Dolt::reset()`. Moving the default branch back past commits needs `force: true`, and `reset()` returns the commit it left so the move can be taken back.
- Time travel with `asOf()` on models, the query builder and whole callbacks.
- Record history with the `Versioned` trait.
- Branches, tags, diffs, merges with conflict handling, and merge previews.
- Sessions, cache, jobs and similar tables kept out of history and shared by every branch.
- `RefreshDoltDatabase` for tests.
- Measured overhead in the README, with the benchmark in `benchmarks/OverheadBench.php`.
- Supports Laravel 12 and 13. Tested on PHP 8.2 with Laravel 12 and on PHP 8.3 with Laravel 13, against Dolt 2.7.2 and Doltgres 1.5.0.
- Artisan commands: `dolt:install`, `dolt:doctor`, `dolt:status`, `dolt:log`, `dolt:diff`, `dolt:commit`, `dolt:branch`, `dolt:merge`, `dolt:workspaces`, `dolt:prune`, `dolt:ignore`.
