<?php

namespace Tests\Unit;

/**
 * EloquentKeyValueRepository::deleteAll() — the storage call behind
 * DELETE /object/{key}.
 */
class DeleteObjectRepositoryTest extends RepositoryTestCase
{
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
