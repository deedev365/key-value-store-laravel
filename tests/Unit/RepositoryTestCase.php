<?php

namespace Tests\Unit;

use App\Repositories\EloquentKeyValueRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared setup for the repository tests, which are split one file per
 * endpoint — the storage call behind each route is tested next to that
 * route's name rather than all six mixed into one class.
 *
 * The concrete EloquentKeyValueRepository is exercised against the real
 * (in-memory) database rather than a mocked query builder: the behaviour
 * under test *is* the SQL, so a double would assert nothing.
 *
 * Not suffixed *Test, so PHPUnit does not try to collect it as a test class.
 */
abstract class RepositoryTestCase extends TestCase
{
    use RefreshDatabase;

    protected EloquentKeyValueRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentKeyValueRepository;
    }
}
