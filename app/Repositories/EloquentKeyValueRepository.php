<?php

namespace App\Repositories;

use App\Models\KvEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every query the API makes against the append-only `kv_entries` table.
 * Writes append versions; the "latest" of a key is its highest id, and
 * listings are paged and ordered in SQL rather than sliced in PHP.
 *
 * A version may carry a `publish_time`, and *every* read here honours it
 * through onlyPublished(): a version is returned only once that time has
 * passed. No endpoint can reveal a version another endpoint is hiding, so
 * `?timestamp=<future>` is not a way to read a campaign early.
 *
 * Among the versions of one key that are published, the current one is the
 * last written — highest `id`. findLatest() and allLatest() both apply exactly
 * that, so they cannot disagree.
 */
class EloquentKeyValueRepository
{
    /**
     * Appends a version. `$publishTime` is when it should become visible, and
     * null — the default — means "no schedule", i.e. live from now, which is
     * what every write did before scheduling existed.
     */
    public function store(string $key, mixed $value, int $recordedAt, ?int $publishTime = null): KvEntry
    {
        return KvEntry::create([
            'key' => $key,
            'value' => $value,
            'recorded_at' => $recordedAt,
            'publish_time' => $publishTime,
        ]);
    }

    /**
     * The version of a key that is live now: of those already published, the
     * one written last — highest `id`.
     *
     * Ordering on `id` rather than on `publish_time` is what the rule requires,
     * and the two differ. Take three versions written in this order: scheduled
     * for noon, scheduled for 4pm, then saved with no schedule at all. After
     * 4pm all three are published, and the greatest publish time is the 4pm
     * one — but the answer is the unscheduled version, because it was written
     * last and so is the editor's most recent intent. Ordering by publish time
     * would let a schedule set earlier override a correction saved after it.
     *
     * Since a null publish_time cannot be compared with a real one anyway, `id`
     * is also the only ordering that ranks scheduled and unscheduled versions
     * without depending on how the engine sorts NULL.
     *
     * This is the same rule allLatest() applies per key, so the two reads
     * cannot disagree about what is current.
     */
    public function findLatest(string $key, ?int $now = null): ?KvEntry
    {
        $now ??= now()->timestamp;

        $query = KvEntry::query()->where('key', $key);

        $this->onlyPublished($query, $now);

        return $query->orderByDesc('id')->first();
    }

    /**
     * The version that was current at `$timestamp`, among those published by
     * `$now`.
     *
     * Two different clocks, deliberately. `recorded_at <= $timestamp` answers
     * the caller's question about a past moment; the publish filter uses the
     * *real* current time, so travelling to a future timestamp cannot be used
     * to read a version before it goes live.
     *
     * Ordering stays on recorded_at: this read is asked which version was
     * current at a moment, so it ranks by when versions were written, not by
     * which was written last.
     */
    public function findAtTimestamp(string $key, int $timestamp, ?int $now = null): ?KvEntry
    {
        $now ??= now()->timestamp;

        $query = KvEntry::query()
            ->where('key', $key)
            ->where('recorded_at', '<=', $timestamp);

        $this->onlyPublished($query, $now);

        return $query
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Restricts a query to versions the public may see: one whose
     * `publish_time` has passed, or which never had a schedule to wait for.
     *
     * Strictly `<`, so a version becomes visible on the second *after* the one
     * it names. Every read in this class shares this rule, which is what stops
     * an unpublished version leaking out of one endpoint while another hides it.
     *
     * The two conditions are wrapped in their own group rather than chained
     * onto the caller's query. An unparenthesised `orWhere` would escape every
     * other condition on that query — `WHERE key = ? AND publish_time IS NULL
     * OR publish_time < ?` matches published rows belonging to *any* key —
     * so the grouping is what makes this safe to add to an existing query.
     *
     * @param  Builder<KvEntry>  $query
     */
    private function onlyPublished(Builder $query, int $now): void
    {
        $query->where(function (Builder $query) use ($now) {
            $query->whereNull('publish_time')
                ->orWhere('publish_time', '<', $now);
        });
    }

    /**
     * One page of the current value of every key, skipping keys whose only
     * versions are still scheduled — a key with nothing published yet is
     * absent from the listing rather than present and empty.
     *
     * `$now` is a parameter rather than a call to the clock inside the query
     * so that a caller can ask what the listing looked like, or will look
     * like, at another moment; it defaults to the current second.
     *
     * @return Collection<int, KvEntry>
     */
    public function allLatest(int $limit, int $offset = 0, ?int $now = null): Collection
    {
        $now ??= now()->timestamp;

        $latestIds = KvEntry::query()
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('key');

        // Filtered inside the subquery, not outside the join: excluding a
        // scheduled row after the group has been formed would drop the key
        // altogether, hiding the older version that is still live.
        $this->onlyPublished($latestIds, $now);

        return KvEntry::query()
            ->joinSub($latestIds, 'latest', function ($join) {
                $join->on('kv_entries.id', '=', 'latest.id');
            })
            ->orderBy('kv_entries.key')
            ->limit(max(0, $limit))
            ->offset(max(0, $offset))
            ->get('kv_entries.*');
    }

    /**
     * Every published version of a key, oldest first. A version still waiting
     * for its publish time is absent: the log is public, so listing a queued
     * campaign here would announce it before its time.
     *
     * @return Collection<int, KvEntry>
     */
    public function history(string $key, ?int $now = null): Collection
    {
        $now ??= now()->timestamp;

        $query = KvEntry::query()->where('key', $key);

        $this->onlyPublished($query, $now);

        return $query
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();
    }

    public function deleteAll(string $key): bool
    {
        return KvEntry::query()->where('key', $key)->delete() > 0;
    }
}
