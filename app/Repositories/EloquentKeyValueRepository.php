<?php

namespace App\Repositories;

use App\Models\KvEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every query the API makes against the append-only `kv_entries` table.
 * Writes append versions; the "latest" of a key is its highest id, and
 * listings are paged and ordered in SQL rather than sliced in PHP.
 */
class EloquentKeyValueRepository
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

    /**
     * @return Collection<int, KvEntry>
     */
    public function allLatest(int $limit, int $offset = 0): Collection
    {
        $latestIds = KvEntry::query()
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('key');

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

    public function deleteAll(string $key): bool
    {
        return KvEntry::query()->where('key', $key)->delete() > 0;
    }
}
