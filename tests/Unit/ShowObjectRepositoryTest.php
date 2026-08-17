<?php

namespace Tests\Unit;

use App\Models\KvEntry;

/**
 * EloquentKeyValueRepository::findLatest() and findAtTimestamp() — the two
 * storage calls behind GET /object/{key}, with and without ?timestamp.
 */
class ShowObjectRepositoryTest extends RepositoryTestCase
{
    public function test_find_latest_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->repository->findLatest('missing'));
    }

    public function test_find_latest_returns_the_most_recently_recorded_version(): void
    {
        $this->repository->store('mykey', 'value1', 1000);
        $this->repository->store('mykey', 'value2', 2000);

        $entry = $this->repository->findLatest('mykey');

        $this->assertSame('value2', $entry->value);
    }

    public function test_find_latest_breaks_ties_on_equal_recorded_at_by_insertion_order(): void
    {
        $this->repository->store('mykey', 'first', 5000);
        $this->repository->store('mykey', 'second', 5000);

        $entry = $this->repository->findLatest('mykey');

        $this->assertSame('second', $entry->value);
    }

    public function test_find_at_timestamp_returns_the_version_current_at_that_moment(): void
    {
        $this->repository->store('mykey', 'value1', 1000);
        $this->repository->store('mykey', 'value2', 2000);

        $this->assertSame('value1', $this->repository->findAtTimestamp('mykey', 1500)->value);
        $this->assertSame('value1', $this->repository->findAtTimestamp('mykey', 1000)->value);
        $this->assertSame('value2', $this->repository->findAtTimestamp('mykey', 2000)->value);
        $this->assertSame('value2', $this->repository->findAtTimestamp('mykey', 999999)->value);
    }

    public function test_find_at_timestamp_returns_null_before_the_first_write(): void
    {
        $this->repository->store('mykey', 'value1', 1000);

        $this->assertNull($this->repository->findAtTimestamp('mykey', 500));
    }

    // ---------------------------------------------------------------
    // publish_time: which version is live now
    // ---------------------------------------------------------------

    private function schedule(string $key, mixed $value, int $recordedAt, ?int $publishTime): KvEntry
    {
        return KvEntry::create([
            'key' => $key,
            'value' => $value,
            'recorded_at' => $recordedAt,
            'publish_time' => $publishTime,
        ]);
    }

    public function test_find_latest_ignores_a_version_whose_publish_time_has_not_arrived(): void
    {
        $this->schedule('mykey', 'live', 1000, 2000);
        $this->schedule('mykey', 'campaign', 1100, 5000);

        $this->assertSame('live', $this->repository->findLatest('mykey', 4999)->value);
    }

    public function test_find_latest_prefers_the_last_written_of_the_versions_that_are_due(): void
    {
        // Two versions both due — the one saved later is on air. Here write
        // order and publish order agree.
        $this->schedule('route.bangkok-chiang-mai.banner', 'earlier slot', 1000, 3000);
        $this->schedule('route.bangkok-chiang-mai.banner', 'later slot', 1100, 4000);

        $this->assertSame(
            'later slot',
            $this->repository->findLatest('route.bangkok-chiang-mai.banner', 9000)->value
        );
    }

    public function test_the_last_write_wins_even_when_it_names_the_earlier_publish_time(): void
    {
        // Insertion order deliberately opposite to publish order. The rule is
        // about which version was written last, not which names the later
        // moment — so a correction saved afterwards is not overridden by a
        // schedule that was set before it.
        $this->schedule('mykey', 'scheduled for 4000', 1000, 4000);
        $this->schedule('mykey', 'corrected afterwards', 2000, 3000);

        $this->assertSame('corrected afterwards', $this->repository->findLatest('mykey', 9000)->value);
    }

    public function test_an_unscheduled_version_written_last_beats_a_published_schedule(): void
    {
        $this->schedule('mykey', 'scheduled for 3000', 1000, 3000);
        $this->schedule('mykey', 'written with no schedule', 4000, null);

        $this->assertSame('written with no schedule', $this->repository->findLatest('mykey', 9000)->value);

        // ...and a schedule written after that unscheduled version takes over
        // again, once it is due.
        $this->schedule('mykey', 'scheduled for 5000', 1000, 5000);

        $this->assertSame('scheduled for 5000', $this->repository->findLatest('mykey', 9000)->value);
    }

    public function test_find_latest_breaks_a_tie_on_equal_publish_times_by_insertion_order(): void
    {
        $this->schedule('mykey', 'first', 1000, 3000);
        $this->schedule('mykey', 'second', 1000, 3000);

        $this->assertSame('second', $this->repository->findLatest('mykey', 9000)->value);
    }

    public function test_find_latest_is_null_when_every_version_is_still_scheduled(): void
    {
        // Nothing is live, so there is nothing to answer with — the same 404
        // the endpoint gives an unknown key, which is also what stops the
        // response from revealing that embargoed content exists.
        $this->schedule('mykey', 'campaign', 1000, 5000);

        $this->assertNull($this->repository->findLatest('mykey', 4999));
    }

    public function test_the_publish_time_is_exclusive_of_its_own_second(): void
    {
        // publish_time < now, so the named second is still pending.
        $this->schedule('mykey', 'campaign', 1000, 5000);

        $this->assertNull($this->repository->findLatest('mykey', 5000));
        $this->assertSame('campaign', $this->repository->findLatest('mykey', 5001)->value);
    }

    public function test_find_latest_defaults_to_the_current_second(): void
    {
        $now = now()->timestamp;

        $this->schedule('mykey', 'live', 1000, $now - 1);
        $this->schedule('mykey', 'pending', 1000, $now + 3600);

        $this->assertSame('live', $this->repository->findLatest('mykey')->value);
    }

    public function test_a_key_that_was_never_scheduled_answers_exactly_as_before(): void
    {
        // Every publish_time null, so the ordering must reduce to recorded_at.
        $this->repository->store('mykey', 'value1', 1000);
        $this->repository->store('mykey', 'value2', 2000);

        $this->assertSame('value2', $this->repository->findLatest('mykey', 0)->value);
    }
}
