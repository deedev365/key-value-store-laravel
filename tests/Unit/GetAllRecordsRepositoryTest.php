<?php

namespace Tests\Unit;

use App\Models\KvEntry;
use App\ValueObjects\Key;
use Illuminate\Support\Collection;

/**
 * EloquentKeyValueRepository::allLatest() — the storage call behind
 * GET /object/get_all_records/{page?}, where the page is cut in SQL rather
 * than sliced in PHP.
 */
class GetAllRecordsRepositoryTest extends RepositoryTestCase
{
    /**
     * The keys of a page, as plain strings. The model casts `key` to a Key
     * value object, and these assertions are about which keys came back in
     * which order, not about the object wrapping them.
     *
     * @param  Collection<int, KvEntry>  $entries
     * @return list<string>
     */
    private function keysOf(Collection $entries): array
    {
        return $entries->pluck('key')
            ->map(fn (Key $key): string => $key->value)
            ->all();
    }

    public function test_all_latest_returns_one_row_per_key(): void
    {
        $this->repository->store('a', 'a1', 1000);
        $this->repository->store('a', 'a2', 2000);
        $this->repository->store('b', 'b1', 1500);

        $latest = $this->repository->allLatest(10)->keyBy('key');

        $this->assertCount(2, $latest);
        $this->assertSame('a2', $latest['a']->value);
        $this->assertSame('b1', $latest['b']->value);
    }

    public function test_all_latest_returns_an_empty_collection_when_the_store_is_empty(): void
    {
        $this->assertCount(0, $this->repository->allLatest(10));
    }

    public function test_all_latest_returns_at_most_the_requested_limit(): void
    {
        foreach (range(1, 12) as $i) {
            $this->repository->store('key'.$i, $i, 1000 + $i);
        }

        $this->assertCount(10, $this->repository->allLatest(10));
        $this->assertCount(3, $this->repository->allLatest(3));
    }

    public function test_all_latest_skips_the_offset(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $key) {
            $this->repository->store($key, $key.'1', 1000);
        }

        $this->assertSame(['a', 'b'], $this->keysOf($this->repository->allLatest(2)));
        $this->assertSame(['c', 'd'], $this->keysOf($this->repository->allLatest(2, 2)));
    }

    public function test_all_latest_is_empty_past_the_last_page(): void
    {
        $this->repository->store('a', 'a1', 1000);

        $this->assertCount(0, $this->repository->allLatest(10, 10));
    }

    public function test_all_latest_pages_the_current_versions_not_the_history(): void
    {
        // Twelve writes across three keys must page as three records, not
        // twelve: the offset applies to keys, not to rows.
        foreach (range(1, 4) as $version) {
            foreach (['a', 'b', 'c'] as $key) {
                $this->repository->store($key, $key.$version, 1000 + $version);
            }
        }

        $this->assertSame(['a', 'b'], $this->keysOf($this->repository->allLatest(2)));
        $this->assertSame(['c'], $this->keysOf($this->repository->allLatest(2, 2)));
        $this->assertSame('c4', $this->repository->allLatest(2, 2)->first()->value);
    }

    public function test_all_latest_tolerates_a_negative_offset(): void
    {
        $this->repository->store('a', 'a1', 1000);

        $this->assertCount(1, $this->repository->allLatest(10, -5));
    }
}
