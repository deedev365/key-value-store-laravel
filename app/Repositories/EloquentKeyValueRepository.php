<?php

namespace App\Repositories;

use App\Models\KvEntry;
use App\Repositories\Contracts\KeyValueRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentKeyValueRepository implements KeyValueRepositoryInterface
{
    public function store(string $key, mixed $value, int $recordedAt): KvEntry
    {
        return KvEntry::create([
            'key' => $key,
            'value' => $value,
            'recorded_at' => $recordedAt,
        ]);
    }

    public function findLatest(string $key): ?KvEntry
    {
        return KvEntry::query()
            ->where('key', $key)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();
    }

    public function findAtTimestamp(string $key, int $timestamp): ?KvEntry
    {
        return KvEntry::query()
            ->where('key', $key)
            ->where('recorded_at', '<=', $timestamp)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();
    }

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

    public function history(string $key): Collection
    {
        return KvEntry::query()
            ->where('key', $key)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();
    }

    public function deleteAll(string $key): bool
    {
        return KvEntry::query()->where('key', $key)->delete() > 0;
    }
}
