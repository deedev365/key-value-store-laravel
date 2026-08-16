<?php

namespace Tests\Unit;

/**
 * EloquentKeyValueRepository::store() — the storage call behind
 * POST /object.
 */
class StoreObjectRepositoryTest extends RepositoryTestCase
{
    public function test_store_persists_a_new_row_without_touching_prior_versions(): void
    {
        $this->repository->store('mykey', 'value1', 1000);
        $this->repository->store('mykey', 'value2', 2000);

        $this->assertDatabaseCount('kv_entries', 2);
    }
}
