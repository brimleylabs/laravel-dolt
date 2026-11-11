<?php

namespace BrimleyLabs\Dolt\Eloquent;

use BrimleyLabs\Dolt\Data\Branch;
use BrimleyLabs\Dolt\Data\Commit;
use BrimleyLabs\Dolt\Data\RowChange;
use BrimleyLabs\Dolt\Data\Tag;
use BrimleyLabs\Dolt\Database\DoltConnection;
use BrimleyLabs\Dolt\DoltManager;
use BrimleyLabs\Dolt\Exceptions\DoltException;
use BrimleyLabs\Dolt\Repository;
use BrimleyLabs\Dolt\Support\Ref;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Gives a model its own history: who changed it, what it looked like before,
 * and a way back.
 *
 * Nothing is stored for this. There is no audit table and no model events are
 * involved, so changes made by bulk updates, raw queries and other
 * applications are in the history too.
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> asOf(string|DateTimeInterface|Commit|Branch|Tag $ref)
 */
trait Versioned
{
    /**
     * Every recorded change to this record, newest first.
     *
     * Changes that are not committed yet come first and have no commit.
     * Hidden attributes are left out unless $withHidden is true.
     *
     * @return Collection<int, RowChange>
     */
    public function history(int $limit = 50, bool $withHidden = false): Collection
    {
        $table = $this->versionedTable();
        $key = $this->getKeyName();
        $dialect = $this->versionedRepository()->dialect();
        $diff = $dialect->quote('dolt_diff_'.$table);
        $to = $dialect->quote('to_'.$key);
        $from = $dialect->quote('from_'.$key);
        $limit = max(1, $limit);

        $rows = $this->getConnection()->select(
            "select d.*,
                    l.commit_hash as __dolt_commit_hash, l.committer as __dolt_committer, l.email as __dolt_email,
                    l.date as __dolt_date, l.message as __dolt_message, l.parents as __dolt_parents,
                    l.author as __dolt_author, l.author_email as __dolt_author_email, l.author_date as __dolt_author_date
                    {$dialect->epochColumns(['date', 'author_date'], 'l', '__dolt_')}
             from {$diff} d
             left join dolt_log l on l.commit_hash = d.to_commit
             where d.{$to} = ? or d.{$from} = ?
             order by (d.to_commit = 'WORKING') desc, l.commit_order desc
             limit {$limit}",
            [$this->getKey(), $this->getKey()],
            false,
        );

        $timezone = app(DoltManager::class)->timezone();
        $hidden = $withHidden ? [] : $this->getHidden();

        return collect($rows)->map(function ($row) use ($table, $key, $hidden, $timezone) {
            $row = (array) $row;

            $commit = empty($row['__dolt_commit_hash']) ? null : Commit::fromLogRow([
                'commit_hash' => $row['__dolt_commit_hash'],
                'committer' => $row['__dolt_committer'],
                'email' => $row['__dolt_email'],
                'date' => $row['__dolt_date'],
                'message' => $row['__dolt_message'],
                'parents' => $row['__dolt_parents'],
                'author' => $row['__dolt_author'],
                'author_email' => $row['__dolt_author_email'],
                'author_date' => $row['__dolt_author_date'],
                'date_epoch' => $row['__dolt_date_epoch'] ?? null,
                'author_date_epoch' => $row['__dolt_author_date_epoch'] ?? null,
            ], $timezone);

            return RowChange::fromDiffRow($table, $row, [$key], $hidden, $commit);
        });
    }

    /**
     * This record as it was at a commit, tag, branch or moment in time. Null when it did not exist then.
     */
    public function versionAt(string|DateTimeInterface|Commit|Branch|Tag $ref): ?static
    {
        return $this->newQueryWithoutScopes()->asOf($ref)->whereKey($this->getKey())->first();
    }

    /**
     * Put this record back the way it was at a point in history and save it.
     *
     * The change is an ordinary update, so it shows up in the history like any other.
     */
    public function revertTo(string|DateTimeInterface|Commit|Branch|Tag $ref): static
    {
        $old = $this->versionAt($ref);

        if ($old === null) {
            throw new DoltException(sprintf(
                'Cannot revert %s [%s]: it did not exist at that point.',
                static::class,
                $this->getKey(),
            ));
        }

        $this->setRawAttributes($old->getAttributes());
        $this->save();

        return $this;
    }

    /**
     * The commit that last touched this record.
     */
    public function lastCommit(): ?Commit
    {
        $dialect = $this->versionedRepository()->dialect();
        $blame = $dialect->quote('dolt_blame_'.$this->versionedTable());
        $key = $dialect->quote($this->getKeyName());

        $rows = $this->getConnection()->select("select * from {$blame} where {$key} = ? limit 1", [$this->getKey()], false);

        if (! $rows) {
            return null;
        }

        // The blame row names the commit but leaves out its parents and author date.
        return $this->versionedRepository()->find((string) $rows[0]->commit)
            ?? Commit::fromLogRow($rows[0], app(DoltManager::class)->timezone());
    }

    /**
     * How this record differs between two points. With one argument, what changed since then.
     *
     * Null when the record is the same at both points.
     */
    public function changesBetween(
        string|DateTimeInterface|Commit|Branch|Tag $from,
        string|DateTimeInterface|Commit|Branch|Tag|null $to = null,
        bool $withHidden = false,
    ): ?RowChange {
        $repository = $this->versionedRepository();
        $table = $this->versionedTable();
        $key = $this->getKeyName();

        $ref = fn ($value) => Ref::literal(is_string($value) ? $repository->normalize($value) : $repository->resolve($value));

        $rows = $this->getConnection()->select(
            sprintf(
                'select * from dolt_diff(%s, %s, %s) where %s = ? or %s = ? limit 1',
                $ref($from),
                $to === null ? Ref::literal(Ref::WORKING) : $ref($to),
                Ref::tableLiteral($table),
                $repository->dialect()->quote('to_'.$key),
                $repository->dialect()->quote('from_'.$key),
            ),
            [$this->getKey(), $this->getKey()],
            false,
        );

        return $rows
            ? RowChange::fromDiffRow($table, $rows[0], [$key], $withHidden ? [] : $this->getHidden())
            : null;
    }

    /**
     * Records of this model that existed at a point in history and are gone now.
     *
     * The returned models are not saved. Call save() on one to bring it back.
     *
     * @return EloquentCollection<int, static>
     */
    public static function deletedSince(string|DateTimeInterface|Commit|Branch|Tag $ref, int $limit = 100): EloquentCollection
    {
        $model = new static;
        $repository = $model->versionedRepository();
        $limit = max(1, $limit);

        $rows = $model->getConnection()->select(
            sprintf(
                "select * from dolt_diff(%s, %s, %s) where diff_type = 'removed' limit %d",
                Ref::literal(is_string($ref) ? $repository->normalize($ref) : $repository->resolve($ref)),
                Ref::literal(Ref::WORKING),
                Ref::tableLiteral($model->versionedTable()),
                $limit,
            ),
            [],
            false,
        );

        $models = array_map(function ($row) use ($model) {
            $change = RowChange::fromDiffRow($model->getTable(), $row);

            return $model->newInstance()->setRawAttributes($change->before ?? [], true);
        }, $rows);

        return $model->newCollection($models);
    }

    /**
     * Bring one record back from a point in history, whether it was deleted or changed since.
     *
     * Null when the record did not exist at that point.
     */
    public static function restoreFrom(string|DateTimeInterface|Commit|Branch|Tag $ref, mixed $key): ?static
    {
        $old = (new static)->newQueryWithoutScopes()->asOf($ref)->whereKey($key)->first();

        if ($old === null) {
            return null;
        }

        $model = (new static)->newQueryWithoutScopes()->whereKey($key)->first() ?? new static;

        $model->setRawAttributes($old->getAttributes());
        $model->save();

        return $model;
    }

    /**
     * The table name as Dolt knows it, connection prefix included.
     */
    protected function versionedTable(): string
    {
        return Ref::table($this->getConnection()->getTablePrefix().$this->getTable());
    }

    protected function versionedRepository(): Repository
    {
        $connection = $this->getConnection();

        if (! $connection instanceof DoltConnection) {
            throw DoltException::notDolt((string) $connection->getName());
        }

        return app(DoltManager::class)->connection($connection->getName());
    }
}
