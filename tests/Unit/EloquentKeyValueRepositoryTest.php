<?php

namespace Tests\Unit;

use App\Repositories\EloquentKeyValueRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EloquentKeyValueRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EloquentKeyValueRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentKeyValueRepository;
    }

    public function test_store_persists_a_new_row_without_touching_prior_versions(): void
    {
        $this->repository->store('mykey', 'value1', 1000);
        $this->repository->store('mykey', 'value2', 2000);

        $this->assertDatabaseCount('kv_entries', 2);
    }

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

    public function test_all_latest_returns_one_row_per_key(): void
    {
        $this->repository->store('a', 'a1', 1000);
        $this->repository->store('a', 'a2', 2000);
        $this->repository->store('b', 'b1', 1500);

        $latest = $this->repository->allLatest()->keyBy('key');

        $this->assertCount(2, $latest);
        $this->assertSame('a2', $latest['a']->value);
        $this->assertSame('b1', $latest['b']->value);
    }

    public function test_all_latest_returns_an_empty_collection_when_the_store_is_empty(): void
    {
        $this->assertCount(0, $this->repository->allLatest());
    }

    public function test_history_returns_every_version_oldest_first(): void
    {
        $this->repository->store('mykey', 'value1', 2000);
        $this->repository->store('mykey', 'value2', 1000);
        $this->repository->store('mykey', 'value3', 1000);

        $history = $this->repository->history('mykey')->values();

        $this->assertCount(3, $history);
        $this->assertSame(['value2', 'value3', 'value1'], $history->pluck('value')->all());
    }

    public function test_history_is_empty_for_an_unknown_key(): void
    {
        $this->assertCount(0, $this->repository->history('missing'));
    }

    public function test_history_does_not_include_other_keys(): void
    {
        $this->repository->store('a', 'a1', 1000);
        $this->repository->store('b', 'b1', 1000);

        $this->assertCount(1, $this->repository->history('a'));
    }

    public function test_delete_all_removes_every_version_and_returns_true(): void
    {
        $this->repository->store('mykey', 'value1', 1000);
        $this->repository->store('mykey', 'value2', 2000);

        $this->assertTrue($this->repository->deleteAll('mykey'));
        $this->assertCount(0, $this->repository->history('mykey'));
    }

    public function test_delete_all_returns_false_for_an_unknown_key(): void
    {
        $this->assertFalse($this->repository->deleteAll('missing'));
    }

    public function test_delete_all_does_not_affect_other_keys(): void
    {
        $this->repository->store('a', 'a1', 1000);
        $this->repository->store('b', 'b1', 1000);

        $this->repository->deleteAll('a');

        $this->assertCount(1, $this->repository->history('b'));
    }
}
