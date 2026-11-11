<?php

namespace BrimleyLabs\Dolt\Http\Middleware;

use BrimleyLabs\Dolt\Commits\AutoCommit;
use BrimleyLabs\Dolt\Database\DoltConnection;
use BrimleyLabs\Dolt\DoltManager;
use BrimleyLabs\Dolt\Exceptions\BranchNotFoundException;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Puts a request on the right branch and turns what it writes into a commit.
 *
 *   Route::middleware('dolt')          the user's workspace, or live data
 *   Route::middleware('dolt:staging')  always the "staging" branch
 *
 * A request that writes nothing costs nothing: no branch is looked up and no
 * commit is attempted.
 */
class HandleDoltRequests
{
    /**
     * The request attribute that holds the request's commit scope.
     */
    protected const SCOPE = 'dolt.commit_scope';

    public function __construct(protected DoltManager $dolt, protected AutoCommit $commits, protected Application $app) {}

    public function handle(Request $request, Closure $next, ?string $branch = null): Response
    {
        $repository = $this->dolt->connection();
        $connection = $repository->connection();

        // A request that arrives inside a transaction is a test wrapped in one.
        // Branches cannot be switched there and commits would end it early.
        if ($connection->transactionLevel() > 0 || $connection->isReadingRevision()) {
            return $next($request);
        }

        $this->enterBranch($connection, $branch ?? $this->dolt->branchForRequest($request));

        $request->attributes->set(self::SCOPE, $this->commits->begin(fn () => $this->dolt->describeRequest($request)));

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Laravel could not turn the failure into a response. What the request
            // wrote first is committed and marked, the same as for a failed job.
            $this->commits->flush(fn () => $this->dolt->describeRequest($request).' (failed)');

            throw $e;
        }

        $this->commits->flush(fn () => $this->dolt->describeRequest($request, $response));

        return $response;
    }

    /**
     * Commit anything written after the response was sent, then go back to the home branch.
     *
     * Deferred functions and jobs dispatched "after response" run later than
     * any middleware does, so the work waits for the application to finish.
     */
    public function terminate(Request $request, Response $response): void
    {
        $finished = false;

        $this->app->terminating(function () use ($request, $response, &$finished) {
            if (! $finished) {
                $finished = true;

                $this->finish($request, $response);
            }
        });
    }

    protected function finish(Request $request, Response $response): void
    {
        $scope = $request->attributes->get(self::SCOPE);

        if (! is_int($scope)) {
            return;
        }

        $request->attributes->remove(self::SCOPE);

        try {
            $this->commits->flush(fn () => $this->dolt->describeRequest($request, $response).' (after response)');
        } finally {
            $this->commits->end($scope);

            // A message set after the last commit must not name the next request's.
            $this->dolt->pullMessage();
        }

        try {
            $connection = $this->dolt->connection()->connection();
        } catch (Throwable) {
            return;
        }

        if ($connection->transactionLevel() > 0 || $connection->isReadingRevision()) {
            return;
        }

        // Deferred: a PHP-FPM worker is about to drop the session anyway.
        $connection->checkout(null, defer: true);
    }

    protected function enterBranch(DoltConnection $connection, ?string $branch): void
    {
        if ($branch === null) {
            return;
        }

        try {
            $connection->checkout($branch);
        } catch (BranchNotFoundException) {
            // The workspace was published or discarded since this user's last request.
            $this->dolt->workspaces()->leave();
        }
    }
}
