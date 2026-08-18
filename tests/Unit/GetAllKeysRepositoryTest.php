<?php

namespace Tests\Unit;

/**
 * EloquentKeyValueRepository::allKeys() — the storage call behind
 * GET /object/get_all_records/keys.
 */
class GetAllKeysRepositoryTest extends RepositoryTestCase
{
    public function test_it_returns_distinct_keys_in_alphabetical_order(): void
    {
        $this->repository->store('charlie', 'v', 1000);
        $this->repository->store('alpha', 'v', 1000);
        $this->repository->store('alpha', 'v2', 2000);

        $this->assertSame(
            ['alpha', 'charlie'],
            $this->repository->allKeys(100, 3000)->all()
        );
    }

    public function test_it_honours_the_publish_filter(): void
    {
        $this->repository->store('live', 'v', 900);
        $this->repository->store('queued', 'v', 900, 5000);

        $this->assertSame(['live'], $this->repository->allKeys(100, 1000)->all());
    }

    public function test_it_never_returns_more_than_the_limit(): void
    {
        foreach (['a', 'b', 'c'] as $key) {
            $this->repository->store($key, 'v', 1000);
        }

        $this->assertSame(['a', 'b'], $this->repository->allKeys(2, 2000)->all());
    }

    public function test_a_limit_of_zero_or_less_returns_nothing(): void
    {
        // max(0, $limit) rather than an unbounded query, matching allLatest().
        $this->repository->store('a', 'v', 1000);

        $this->assertCount(0, $this->repository->allKeys(0, 2000));
        $this->assertCount(0, $this->repository->allKeys(-5, 2000));
    }
}
