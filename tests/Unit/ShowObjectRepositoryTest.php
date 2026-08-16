<?php

namespace Tests\Unit;

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
}
