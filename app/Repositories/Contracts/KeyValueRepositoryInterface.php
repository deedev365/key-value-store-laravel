<?php

namespace App\Repositories\Contracts;

use App\Models\KvEntry;
use Illuminate\Support\Collection;

interface KeyValueRepositoryInterface
{
    /**
     * Store a new version of $key with $value, recorded at $recordedAt
     * (UTC unix timestamp). Never overwrites a prior version.
     */
    public function store(string $key, mixed $value, int $recordedAt): KvEntry;

    /**
     * The most recently recorded version of $key, or null if the key has
     * never been written.
     */
    public function findLatest(string $key): ?KvEntry;

    /**
     * The version of $key that was current at $timestamp (UTC unix
     * timestamp), i.e. the latest version recorded at or before that
     * moment. Null if the key had no value yet at that time.
     */
    public function findAtTimestamp(string $key, int $timestamp): ?KvEntry;

    /**
     * One page of the latest version of every key, ordered by key.
     *
     * $limit is mandatory rather than defaulted: the store grows without
     * bound, so there is deliberately no way to ask this for everything.
     * An $offset past the last key yields an empty collection.
     *
     * @return Collection<int, KvEntry>
     */
    public function allLatest(int $limit, int $offset = 0): Collection;

    /**
     * Every version ever recorded for $key, oldest first. Empty if the key
     * has never been written.
     *
     * @return Collection<int, KvEntry>
     */
    public function history(string $key): Collection;

    /**
     * Delete every recorded version of $key. Returns true if the key existed
     * (and was deleted), false if it had never been written.
     */
    public function deleteAll(string $key): bool;
}
