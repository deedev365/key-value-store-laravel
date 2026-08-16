<?php

namespace Tests\Unit;

/**
 * EloquentKeyValueRepository::history() — the storage call behind
 * GET /object/{key}/history.
 */
class ObjectHistoryRepositoryTest extends RepositoryTestCase
{
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
}
