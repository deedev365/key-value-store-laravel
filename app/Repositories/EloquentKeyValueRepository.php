<?php

namespace App\Repositories;

use App\Models\KvEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every query the API makes against the append-only `kv_entries` table.
 *
 * The controllers depend on this class directly rather than on an interface.
 * There is one storage engine, and the tests exercise the real (in-memory)
 * database rather than a double — the behaviour under test *is* the SQL, so a
 * mock would assert nothing. An interface here would have had no second
 * implementer and no test that bound to it. If a second engine or a caching
 * decorator ever arrives, extracting one back out is a mechanical refactor.
 */
class EloquentKeyValueRepository
{
    /**
     * Store a new version of $key with $value, recorded at $recordedAt
     * (UTC unix timestamp). Never overwrites a prior version.
     */
    public function store(string $key, mixed $value, int $recordedAt): KvEntry
    {
        return KvEntry::create([
            'key' => $key,
            'value' => $value,
            'recorded_at' => $recordedAt,
        ]);
    }

    /**
     * The most recently recorded version of $key, or null if the key has
     * never been written.
     */
    public function findLatest(string $key): ?KvEntry
    {
        return KvEntry::query()
            ->where('key', $key)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The version of $key that was current at $timestamp (UTC unix
     * timestamp), i.e. the latest version recorded at or before that
     * moment. Null if the key had no value yet at that time.
     */
    public function findAtTimestamp(string $key, int $timestamp): ?KvEntry
    {
        return KvEntry::query()
            ->where('key', $key)
            ->where('recorded_at', '<=', $timestamp)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * One page of the latest version of every key, ordered by key.
     *
     * $limit is mandatory rather than defaulted: the store grows without
     * bound, so there is deliberately no way to ask this for everything.
     * An $offset past the last key yields an empty collection.
     *
     * @return Collection<int, KvEntry>
     */
    public function allLatest(int $limit, int $offset = 0): Collection
    {
        // The row with the highest id within each key group is its latest
        // version, since writes are append-only and id is monotonically
        // increasing with insertion order.
        $latestIds = KvEntry::query()
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('key');

        // The page is cut in SQL, not in PHP: the whole table would otherwise
        // be hydrated into models on every request just to throw most of it
        // away. Ordering by key is what makes paging stable — without a
        // deterministic order, a row could appear on two pages or on none.
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
     * Every version ever recorded for $key, oldest first. Empty if the key
     * has never been written.
     *
     * @return Collection<int, KvEntry>
     */
    public function history(string $key): Collection
    {
        return KvEntry::query()
            ->where('key', $key)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Delete every recorded version of $key. Returns true if the key existed
     * (and was deleted), false if it had never been written.
     */
    public function deleteAll(string $key): bool
    {
        return KvEntry::query()->where('key', $key)->delete() > 0;
    }
}
