<?php

namespace Tests\Unit;

use App\Models\KvEntry;
use Illuminate\Support\Collection;

/**
 * EloquentKeyValueRepository::allLatest() — the storage call behind
 * GET /object/get_all_records/{page?}, where the page is cut in SQL rather
 * than sliced in PHP.
 */
class GetAllRecordsRepositoryTest extends RepositoryTestCase
{
    /**
     * The keys of a page, in the order the repository returned them.
     *
     * @param  Collection<int, KvEntry>  $entries
     * @return list<string>
     */
    private function keysOf(Collection $entries): array
    {
        return $entries->pluck('key')->all();
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

    // ---------------------------------------------------------------
    // publish_time: a version is listed once its time has come
    // ---------------------------------------------------------------

    /**
     * A version with an explicit activation time. store() has no parameter for
     * it — no write path sets one yet — so the row is built directly.
     */
    private function schedule(string $key, mixed $value, int $recordedAt, ?int $publishTime): KvEntry
    {
        return KvEntry::create([
            'key' => $key,
            'value' => $value,
            'recorded_at' => $recordedAt,
            'publish_time' => $publishTime,
        ]);
    }

    public function test_a_version_whose_publish_time_has_not_arrived_is_not_listed(): void
    {
        $this->schedule('a', 'scheduled', 1000, 5000);

        $this->assertCount(0, $this->repository->allLatest(10, 0, 4999));
    }

    public function test_a_version_whose_publish_time_has_passed_is_listed(): void
    {
        $this->schedule('a', 'live', 1000, 5000);

        $listed = $this->repository->allLatest(10, 0, 5001);

        $this->assertCount(1, $listed);
        $this->assertSame('live', $listed->first()->value);
    }

    public function test_a_null_publish_time_is_listed_whatever_the_clock_says(): void
    {
        // Null is "no schedule", not "not yet" — every row written before the
        // column existed is one of these.
        $this->schedule('a', 'unscheduled', 1000, null);

        $this->assertCount(1, $this->repository->allLatest(10, 0, 0));
    }

    public function test_a_key_with_nothing_published_yet_is_absent_rather_than_empty(): void
    {
        $this->schedule('pending', 'soon', 1000, 5000);
        $this->schedule('live', 'now', 1000, null);

        $this->assertSame(['live'], $this->keysOf($this->repository->allLatest(10, 0, 4999)));
    }

    public function test_a_scheduled_version_does_not_hide_the_live_one_beneath_it(): void
    {
        // The regression the filter has to avoid: if the newest row is excluded
        // after the per-key group is formed, the key drops out of the listing
        // and the version that *is* live disappears with it.
        $this->schedule('a', 'current', 1000, null);
        $this->schedule('a', 'campaign', 2000, 5000);

        $listed = $this->repository->allLatest(10, 0, 4999);

        $this->assertCount(1, $listed);
        $this->assertSame('current', $listed->first()->value);
    }

    public function test_the_scheduled_version_takes_over_once_its_time_arrives(): void
    {
        $this->schedule('a', 'current', 1000, null);
        $this->schedule('a', 'campaign', 2000, 5000);

        $this->assertSame('campaign', $this->repository->allLatest(10, 0, 5001)->first()->value);
    }

    public function test_the_publish_time_is_exclusive_of_its_own_second(): void
    {
        // The rule is publish_time < now, so a version scheduled for 16:15:00 is
        // still pending during that second and live from 16:15:01.
        $this->schedule('a', 'campaign', 1000, 5000);

        $this->assertCount(0, $this->repository->allLatest(10, 0, 5000));
        $this->assertCount(1, $this->repository->allLatest(10, 0, 5001));
    }

    public function test_paging_counts_only_the_keys_that_are_published(): void
    {
        // The offset applies to visible keys. If pending keys were counted, a
        // page would come back short without being the last one.
        foreach (['a', 'b', 'c', 'd'] as $key) {
            $this->schedule($key, $key.'1', 1000, null);
        }

        $this->schedule('bb', 'pending', 1000, 5000);
        $this->schedule('cc', 'pending', 1000, 5000);

        $this->assertSame(['a', 'b'], $this->keysOf($this->repository->allLatest(2, 0, 4999)));
        $this->assertSame(['c', 'd'], $this->keysOf($this->repository->allLatest(2, 2, 4999)));
    }

    public function test_all_latest_defaults_to_the_current_second(): void
    {
        $now = now()->timestamp;

        $this->schedule('past', 'live', 1000, $now - 1);
        $this->schedule('future', 'pending', 1000, $now + 3600);

        $this->assertSame(['past'], $this->keysOf($this->repository->allLatest(10)));
    }
}
